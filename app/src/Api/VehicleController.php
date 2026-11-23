<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\Actor;
use Garage\Domain\DamageService;
use Garage\Domain\DocumentService;
use Garage\Domain\DomainError;
use Garage\Domain\DossierService;
use Garage\Domain\DueService;
use Garage\Domain\MeasurementService;
use Garage\Domain\NoteService;
use Garage\Domain\OdometerService;
use Garage\Domain\PartService;
use Garage\Domain\TireService;
use Garage\Domain\VehicleRepository;
use Garage\Domain\VehicleService;
use Garage\Http\Request;
use Garage\Http\Response;
use PDO;

/**
 * Lese-Routen der Oberfläche für Garagen und Fahrzeuge. Jede ruft denselben Service wie das gleichnamige MCP-Tool
 * und gibt dessen Ausgabe unverändert zurück (Kontrakt in ADR-018).
 */
final class VehicleController
{
    private const MARKDOWN_TYPE = 'text/markdown; charset=utf-8';

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = [])
    {
    }

    public function list(Request $request): never
    {
        $actor = ApiAuth::user($request);
        $pdo = Connection::open();

        if ($request->query('deleted') === '1') {
            Response::json(['vehicles' => (new VehicleRepository($pdo))->listDeleted($actor, VehicleRepository::RESTORE_DAYS)]);
        }

        Response::json(['garages' => (new VehicleService($pdo))->list($actor)]);
    }

    public function show(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, function (Actor $actor, PDO $pdo) use ($vehicleId): array {
            $result = (new VehicleService($pdo))->get($vehicleId, $actor);
            $result['documents'] = (new DocumentService($pdo, $this->config))->forVehicle($vehicleId, $actor);
            // Ein leeres PHP-Array würde als JSON-Liste statt als Objekt ausgegeben.
            $result['facts'] = (object) $result['facts'];
            $result['specs'] = (object) $result['specs'];
            $role = $actor->roles[$result['vehicle']['garage_id']] ?? null;
            $result['permissions'] = [
                'write' => $role === Actor::ROLE_OWNER || $role === Actor::ROLE_MEMBER,
                'owner' => $role === Actor::ROLE_OWNER,
            ];

            return $result;
        });
    }

    public function due(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new DueService($pdo))->dueItems($vehicleId, DueService::DEFAULT_HORIZON_MONTHS, $actor));
    }

    public function odometer(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => ['readings' => (new OdometerService($pdo))->validReadingsDetailed($vehicleId, $actor)]);
    }

    public function measurements(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new MeasurementService($pdo))->series($vehicleId, null, $actor));
    }

    public function tires(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new TireService($pdo))->list($vehicleId, $actor));
    }

    public function parts(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new PartService($pdo))->grouped($vehicleId, $actor));
    }

    public function notes(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => ['notes' => (new NoteService($pdo))->listCurrent($vehicleId, $actor)]);
    }

    public function damages(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static function (Actor $actor, PDO $pdo) use ($vehicleId): array {
            $damages = new DamageService($pdo);

            return ['damages' => $damages->listDamages($vehicleId, $actor), 'recalls' => $damages->listRecalls($vehicleId, $actor)];
        });
    }

    public function dossier(Request $request, int $vehicleId): never
    {
        $actor = ApiAuth::user($request);

        try {
            $markdown = (new DossierService(Connection::open()))->render($vehicleId, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        $filename = sprintf('akte-%d-%s.md', $vehicleId, gmdate('Y-m-d'));
        Response::text($markdown, self::MARKDOWN_TYPE, ['Content-Disposition' => 'attachment; filename="' . $filename . '"']);
    }

    /** @param callable(Actor, PDO): array<string, mixed> $load */
    private function respondJson(Request $request, callable $load): never
    {
        $actor = ApiAuth::user($request);

        try {
            $body = $load($actor, Connection::open());
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($body);
    }
}
