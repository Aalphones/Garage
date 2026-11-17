<?php

declare(strict_types=1);

namespace Garage\Mcp;

use Garage\Auth\McpAuth;
use Garage\Auth\Scopes;
use Garage\Db\Connection;
use Garage\Domain\Actor;
use Garage\Domain\DamageService;
use Garage\Domain\DueService;
use Garage\Domain\EquipmentService;
use Garage\Domain\EventService;
use Garage\Domain\MeasurementService;
use Garage\Domain\NoteService;
use Garage\Domain\OdometerService;
use Garage\Domain\PartService;
use Garage\Domain\RegistrationService;
use Garage\Domain\TaskService;
use Garage\Domain\TireService;
use Garage\Domain\TokenService;
use Garage\Domain\UserRepository;
use Garage\Domain\VehicleService;
use Garage\Http\Request;
use Garage\Http\Response;
use Garage\Mcp\Tools\DamageTools;
use Garage\Mcp\Tools\EquipmentTools;
use Garage\Mcp\Tools\EventTools;
use Garage\Mcp\Tools\MeasurementTools;
use Garage\Mcp\Tools\NoteTools;
use Garage\Mcp\Tools\OdometerTools;
use Garage\Mcp\Tools\PartTools;
use Garage\Mcp\Tools\RegistrationTools;
use Garage\Mcp\Tools\TaskTools;
use Garage\Mcp\Tools\TireTools;
use Garage\Mcp\Tools\VehicleTools;
use Garage\OAuth\Discovery;
use Garage\OAuth\TokenIssuer;
use Garage\Util\Log;
use PDO;
use stdClass;
use Throwable;

/** Endpunkt `/garage/mcp`: beide Protokoll-Generationen, Anmeldung, Dispatch der Methoden (aus DISPATCH, ADR-005). */
final class McpController
{
    private const SERVER_NAME = 'garage';
    private const SERVER_TITLE = 'GARAGE';
    private const SERVER_VERSION = '0.1.0';
    private const CACHE_TTL_MS = 300000;
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Services einmal pro Anfrage.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function services(PDO $pdo, array $config): array
    {
        return [
            'tokens' => new TokenService($pdo),
            'vehicles' => new VehicleService($pdo),
            'registrations' => new RegistrationService($pdo),
            'equipment' => new EquipmentService($pdo),
            'events' => new EventService($pdo),
            'odometer' => new OdometerService($pdo),
            'tasks' => new TaskService($pdo),
            'due' => new DueService($pdo),
            'measurements' => new MeasurementService($pdo),
            'tires' => new TireService($pdo),
            'parts' => new PartService($pdo),
            'damages' => new DamageService($pdo),
            'notes' => new NoteService($pdo),
            'pdo' => $pdo,
            'config' => $config,
        ];
    }

    public function handle(Request $request): never
    {
        if ($request->method() !== 'POST') {
            Response::error('method_not_allowed', 'Der MCP-Endpunkt akzeptiert nur POST.', 405, ['Allow' => 'POST']);
        }
        if (!self::isOriginAllowed($request)) {
            Response::error('forbidden_origin', 'Der Origin-Header gehört nicht zu dieser Website.', 403);
        }

        $pdo = Connection::open();
        $services = self::services($pdo, $this->config);
        /** @var TokenService $tokens */
        $tokens = $services['tokens'];
        $auth = McpAuth::authenticate($request, $tokens, new TokenIssuer($pdo, $this->config), new UserRepository($pdo));
        if ($auth === null) {
            Response::error(
                'unauthorized',
                'Gültiges MCP-Token im Header Authorization: Bearer … erforderlich.',
                401,
                ['WWW-Authenticate' => Discovery::challengeHeader($this->config, $request->bearerToken() === null ? null : 'invalid_token')]
            );
        }
        $actor = Actor::forUser(
            $auth['user_id'],
            $auth['kind'],
            ($auth['kind'] === Actor::VIA_OAUTH ? 'oauth:' : 'mcp:') . $auth['name'],
            $auth['scopes'],
            $pdo
        );

        $requestId = null;
        try {
            $message = self::parse($request->body());
            if (!array_key_exists('id', $message)) {
                Response::empty(202);
            }
            $requestId = $message['id'];
            $era = Protocol::negotiate($request, $message);
            $result = $this->dispatch($message, $era, $actor, $services);
            Response::raw(self::encode(['jsonrpc' => '2.0', 'id' => $requestId, 'result' => $result]), 200);
        } catch (ProtocolError $error) {
            Response::raw(self::encodeError($requestId, $error), $error->httpStatus, $error->headers);
        } catch (Throwable $error) {
            Log::error('MCP-Anfrage fehlgeschlagen', [
                'type' => $error::class,
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ]);
            $internal = new ProtocolError(ProtocolError::INTERNAL_ERROR, 'Interner Fehler.', 500);
            Response::raw(self::encodeError($requestId, $internal), 500);
        }
    }

    /** Ein Origin-Header (Browser) muss zum selben Host gehören; Claude Code sendet keinen. */
    private static function isOriginAllowed(Request $request): bool
    {
        $origin = $request->header('Origin');
        if ($origin === null) {
            return true;
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        if (!is_string($originHost)) {
            return false;
        }
        $ownHost = (string) $request->header('Host');
        $originAuthority = $originPort === null ? $originHost : $originHost . ':' . $originPort;

        return strcasecmp($originAuthority, $ownHost) === 0;
    }

    /** @return array<string, mixed> */
    private static function parse(string $body): array
    {
        $message = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ProtocolError(ProtocolError::PARSE_ERROR, 'Der Body ist kein gültiges JSON.');
        }
        if (!is_array($message) || array_is_list($message)) {
            throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'Erwartet wird genau eine JSON-RPC-Nachricht als Objekt; Batches werden nicht unterstützt.');
        }
        if (!is_string($message['method'] ?? null)) {
            throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'Das Feld „method“ fehlt oder ist kein Text.');
        }
        if (array_key_exists('id', $message) && !is_string($message['id']) && !is_int($message['id'])) {
            throw new ProtocolError(ProtocolError::INVALID_REQUEST, 'Das Feld „id“ muss Text oder ganze Zahl sein.');
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $message
     * @param array{version: string, modern: bool} $era
     * @param array<string, mixed> $services
     * @return array<string, mixed>|stdClass
     */
    private function dispatch(array $message, array $era, Actor $actor, array $services): array|stdClass
    {
        $method = (string) $message['method'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        $result = match ($method) {
            'initialize' => $this->initialize($era['version']),
            'server/discover' => $this->discover($era),
            'ping' => new stdClass(),
            'tools/list' => $this->listTools($era, $actor, $services),
            'tools/call' => $this->callTool($params, $actor, $services),
            'resources/list' => $this->readOnlyList($actor, static fn (): array => ['resources' => (new Resources())->list()], $era),
            'resources/templates/list' => $this->readOnlyList($actor, static fn (): array => ['resourceTemplates' => (new Resources())->templates()], $era),
            'resources/read' => $this->readResource($params, $actor, $era),
            'prompts/list' => $this->readOnlyList($actor, static fn (): array => ['prompts' => (new Prompts())->list()], $era),
            'prompts/get' => $this->getPrompt($params, $actor),
            default => throw new ProtocolError(
                ProtocolError::METHOD_NOT_FOUND,
                sprintf('Unbekannte Methode „%s“.', $method),
                $era['modern'] ? 404 : 200
            ),
        };

        if (!$era['modern'] || $result instanceof stdClass) {
            return $result;
        }

        return self::modernEnvelope($result);
    }

    /**
     * Bei Modern trägt jedes Ergebnis `resultType` und die Server-Info.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function modernEnvelope(array $result): array
    {
        $meta = $result['_meta'] ?? [];
        $meta[Protocol::META_SERVER_INFO] = ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION];
        $result['_meta'] = $meta;

        return ['resultType' => 'complete'] + $result;
    }

    /** @return array<string, mixed> */
    private function initialize(string $version): array
    {
        return [
            'protocolVersion' => $version,
            'capabilities' => self::capabilities(),
            'serverInfo' => ['name' => self::SERVER_NAME, 'title' => self::SERVER_TITLE, 'version' => self::SERVER_VERSION],
            'instructions' => self::instructions(),
        ];
    }

    /**
     * @param array{version: string, modern: bool} $era
     * @return array<string, mixed>
     */
    private function discover(array $era): array
    {
        if (!$era['modern']) {
            throw new ProtocolError(ProtocolError::METHOD_NOT_FOUND, 'server/discover gibt es nur ab Protokoll ' . Protocol::MODERN . '; ältere Clients nutzen initialize.', 200);
        }

        return [
            'supportedVersions' => Protocol::supported(),
            'capabilities' => self::capabilities(),
            'instructions' => self::instructions(),
            'ttlMs' => self::CACHE_TTL_MS,
            'cacheScope' => 'public',
        ];
    }

    /**
     * @param array{version: string, modern: bool} $era
     * @param array<string, mixed> $services
     * @return array<string, mixed>
     */
    private function listTools(array $era, Actor $actor, array $services): array
    {
        $tools = array_map(
            static fn (Tool $tool): array => $tool->describe(),
            self::registry($services)->visible($actor->scopes)
        );

        return self::cacheable(['tools' => $tools], $era);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $services
     * @return array<string, mixed>
     */
    private function callTool(array $params, Actor $actor, array $services): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || !is_array($arguments)) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, 'tools/call braucht params.name (Text) und optional params.arguments (Objekt).');
        }

        return self::registry($services)->call($name, $arguments, $actor)->toArray();
    }

    /**
     * Resources und Prompts brauchen garage:read.
     *
     * @param callable(): array<string, mixed> $list
     * @param array{version: string, modern: bool} $era
     * @return array<string, mixed>
     */
    private function readOnlyList(Actor $actor, callable $list, array $era): array
    {
        self::requireReadScope($actor);

        return self::cacheable($list(), $era);
    }

    /**
     * @param array<string, mixed> $params
     * @param array{version: string, modern: bool} $era
     * @return array<string, mixed>
     */
    private function readResource(array $params, Actor $actor, array $era): array
    {
        self::requireReadScope($actor);
        $uri = $params['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, 'resources/read braucht params.uri (Text), z. B. "garage://fahrzeuge".');
        }

        return self::cacheable(['contents' => (new Resources())->read($uri)], $era);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getPrompt(array $params, Actor $actor): array
    {
        self::requireReadScope($actor);
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || !is_array($arguments)) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, 'prompts/get braucht params.name (Text) und optional params.arguments (Objekt).');
        }

        return (new Prompts())->get($name, $arguments);
    }

    private static function requireReadScope(Actor $actor): void
    {
        if (in_array(Scopes::READ, $actor->scopes, true)) {
            return;
        }

        throw new ProtocolError(
            ProtocolError::INSUFFICIENT_SCOPE,
            'Resources und Prompts brauchen den Scope ' . Scopes::READ . '; das Token hat ihn nicht.',
            403,
            ['required_scope' => Scopes::READ],
            ['WWW-Authenticate' => sprintf('Bearer error="insufficient_scope", scope="%s"', Scopes::READ)]
        );
    }

    /**
     * Cache-Hinweise gibt es nur bei Modern; die Listen hängen vom Token ab, daher `private`.
     *
     * @param array<string, mixed> $result
     * @param array{version: string, modern: bool} $era
     * @return array<string, mixed>
     */
    private static function cacheable(array $result, array $era): array
    {
        if (!$era['modern']) {
            return $result;
        }

        return $result + ['ttlMs' => self::CACHE_TTL_MS, 'cacheScope' => 'private'];
    }

    /**
     * Alle Werkzeuge.
     *
     * @param array<string, mixed> $services
     */
    public static function registry(array $services): Registry
    {
        $registry = new Registry();
        $registry->group('Fahrzeuge');
        VehicleTools::register($registry, $services);
        $registry->group('Stammdaten');
        RegistrationTools::register($registry, $services);
        EquipmentTools::register($registry, $services);
        $registry->group('Scheckheft');
        EventTools::register($registry, $services);
        OdometerTools::register($registry, $services);
        $registry->group('Wartungsplan');
        TaskTools::register($registry, $services);
        $registry->group('Messwerte und Reifen');
        MeasurementTools::register($registry, $services);
        TireTools::register($registry, $services);
        $registry->group('Teile');
        PartTools::register($registry, $services);
        $registry->group('Schäden und Rückrufe');
        DamageTools::register($registry, $services);
        $registry->group('Notizen');
        NoteTools::register($registry, $services);

        return $registry;
    }

    /** @return array<string, stdClass> */
    private static function capabilities(): array
    {
        return ['tools' => new stdClass(), 'resources' => new stdClass(), 'prompts' => new stdClass()];
    }

    private static function instructions(): string
    {
        return trim((string) file_get_contents(dirname(__DIR__, 2) . '/mcp/instructions.md'));
    }

    /** @param array<string, mixed> $body */
    private static function encode(array $body): string
    {
        return json_encode($body, self::JSON_FLAGS);
    }

    private static function encodeError(string|int|null $requestId, ProtocolError $error): string
    {
        $payload = ['code' => $error->rpcCode, 'message' => $error->getMessage()];
        if ($error->data !== []) {
            $payload['data'] = $error->data;
        }

        return self::encode(['jsonrpc' => '2.0', 'id' => $requestId, 'error' => $payload]);
    }
}
