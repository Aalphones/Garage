<?php

declare(strict_types=1);

namespace Garage\OAuth;

use Garage\Domain\DomainError;
use Garage\Util\HttpClient;
use Garage\Util\Log;
use JsonException;
use PDO;

/** OAuth-Clients: ausschließlich CIMD-Clients (Metadatendokument unter der `client_id`, E6). Apps ohne CIMD nutzen ein festes Token. */
final class ClientRegistry
{
    private const MAX_CLIENT_ID_LENGTH = 500;
    private const MAX_NAME_LENGTH = 120;
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    private OAuthRepository $repository;
    private HttpClient $http;

    /** @param array<string, mixed> $config */
    public function __construct(PDO $pdo, private readonly array $config, ?HttpClient $http = null)
    {
        $this->repository = new OAuthRepository($pdo);
        $this->http = $http ?? new HttpClient(
            array_merge($config['http'], $config['oauth']['http']),
            Discovery::base($config)
        );
    }

    /**
     * @return array{client_id: string, kind: string, name: string, host: string, redirect_uris: list<string>}
     * @throws OAuthError `unbekannte_app` | `app_nicht_erreichbar`
     */
    public function resolve(string $clientId): array
    {
        if (str_starts_with($clientId, 'https://')) {
            return $this->resolveCimd($clientId);
        }

        throw $this->unknownApp('Die App ist nicht bekannt.');
    }

    /**
     * Client aus der gespeicherten Zeile, ohne neuen Abruf (Zustimmung und Token-Endpunkt).
     *
     * @return array{client_id: string, kind: string, name: string, host: string, redirect_uris: list<string>}
     * @throws OAuthError `unbekannte_app` (nicht gespeichert oder widerrufen)
     */
    public function stored(string $clientId): array
    {
        $row = $this->repository->findClient($clientId, 0);
        if ($row === null || (int) $row['revoked'] === 1) {
            throw $this->unknownApp('Die App ist nicht bekannt.');
        }

        return $this->describe($row, self::hostFor($clientId));
    }

    /** Anzeige-Host: der Host der `client_id` — er weist die App aus, nicht ihr selbst gewählter Name. */
    public static function hostFor(string $clientId): string
    {
        return strtolower((string) parse_url($clientId, PHP_URL_HOST));
    }

    /** @param array{redirect_uris: list<string>} $client */
    public function redirectAllowed(array $client, string $uri): bool
    {
        if (in_array($uri, $client['redirect_uris'], true)) {
            return true;
        }

        $requested = parse_url($uri);
        if ($requested === false || ($requested['scheme'] ?? '') !== 'http' || array_intersect_key($requested, ['fragment' => 1, 'user' => 1, 'pass' => 1]) !== []) {
            return false;
        }

        foreach ($client['redirect_uris'] as $registeredUri) {
            if ($this->isLoopbackMatch(parse_url($registeredUri), $requested)) {
                return true;
            }
        }

        return false;
    }

    /** true, wenn die Rückadresse auf den eigenen Rechner zeigt (Claude Code). */
    public static function isLoopback(string $uri): bool
    {
        $parts = parse_url($uri);

        return $parts !== false && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', self::LOOPBACK_HOSTS, true);
    }

    /** @return array{client_id: string, kind: string, name: string, host: string, redirect_uris: list<string>} */
    private function resolveCimd(string $clientId): array
    {
        $host = $this->cimdHost($clientId);
        $cacheSeconds = (int) $this->config['oauth']['cimd_cache_seconds'];
        $stored = $this->repository->findClient($clientId, $cacheSeconds);
        $hasUsableRow = $stored !== null && $stored['kind'] === OAuthRepository::KIND_CIMD;

        if ($hasUsableRow && (int) $stored['is_fresh'] === 1) {
            return $this->describe($stored, $host);
        }

        try {
            $response = $this->http->get($clientId, (int) $this->config['oauth']['cimd_max_bytes']);
        } catch (DomainError $error) {
            if (!$hasUsableRow) {
                Log::warning('CIMD nicht abrufbar', ['client_id' => $clientId, 'reason' => $error->getMessage()]);

                throw new OAuthError('app_nicht_erreichbar', 'Das Client-Metadatendokument ist nicht abrufbar.');
            }
            Log::warning('CIMD nicht abrufbar, Zwischenspeicher wird genutzt', ['client_id' => $clientId, 'reason' => $error->getMessage()]);

            return $this->describe($stored, $host);
        }

        $document = $this->parseDocument($response->body, $clientId);
        $this->repository->saveCimdClient($clientId, $this->documentName($document, $host), $document['redirect_uris'], $response->body);
        $saved = $this->repository->findClient($clientId, $cacheSeconds);

        return $this->describe($saved ?? throw $this->unknownApp('Die App ist nicht bekannt.'), $host);
    }

    /**
     * Nur `https://<Host>/<Pfad>`: kein Port, keine Zugangsdaten, kein Fragment. Jeder Host ist zugelassen,
     * die Schranke ist die Zustimmung des angemeldeten Nutzers; fremde Abrufe schützt der HttpClient.
     */
    private function cimdHost(string $clientId): string
    {
        $parts = parse_url($clientId);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $isPlain = $parts !== false && $host !== ''
            && array_intersect_key($parts, ['port' => 1, 'user' => 1, 'pass' => 1, 'fragment' => 1]) === []
            && ($parts['path'] ?? '/') !== '/' && strlen($clientId) <= self::MAX_CLIENT_ID_LENGTH;
        if (!$isPlain) {
            throw $this->unknownApp('Die client_id der App ist keine gültige Adresse.');
        }

        return $host;
    }

    /** @return array{redirect_uris: list<string>, client_name?: string} */
    private function parseDocument(string $body, string $clientId): array
    {
        try {
            $document = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->unknownApp('Das Client-Metadatendokument ist kein JSON.');
        }

        $redirectUris = is_array($document) ? ($document['redirect_uris'] ?? null) : null;
        $isValid = is_array($document) && !array_is_list($document)
            && ($document['client_id'] ?? null) === $clientId
            && is_array($redirectUris) && $redirectUris !== [] && array_is_list($redirectUris)
            && array_filter($redirectUris, static fn (mixed $uri): bool => !is_string($uri) || $uri === '') === []
            && (!isset($document['client_name']) || is_string($document['client_name']));
        if (!$isValid) {
            throw $this->unknownApp('Das Client-Metadatendokument ist ungültig.');
        }

        return $document;
    }

    /** @param array<string, mixed> $document */
    private function documentName(array $document, string $host): string
    {
        $name = trim((string) ($document['client_name'] ?? ''));

        return $name === '' ? $host : mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{client_id: string, kind: string, name: string, host: string, redirect_uris: list<string>}
     */
    private function describe(array $row, string $host): array
    {
        $redirectUris = json_decode((string) $row['redirect_uris'], true, 8, JSON_THROW_ON_ERROR);

        return [
            'client_id' => (string) $row['client_id'],
            'kind' => (string) $row['kind'],
            'name' => (string) $row['name'],
            'host' => $host,
            'redirect_uris' => array_values(array_map('strval', $redirectUris)),
        ];
    }

    /**
     * Loopback (RFC 8252 §7.3): Schema, Host, Pfad und Query gleich, der Port ist frei.
     *
     * @param array<string, mixed>|false $registered
     * @param array<string, mixed> $requested
     */
    private function isLoopbackMatch(array|false $registered, array $requested): bool
    {
        if ($registered === false || ($registered['scheme'] ?? '') !== 'http' || !in_array($registered['host'] ?? '', self::LOOPBACK_HOSTS, true)) {
            return false;
        }

        return ($requested['host'] ?? null) === $registered['host']
            && ($requested['path'] ?? '') === ($registered['path'] ?? '')
            && ($requested['query'] ?? '') === ($registered['query'] ?? '');
    }

    private function unknownApp(string $description): OAuthError
    {
        return new OAuthError('unbekannte_app', $description);
    }
}
