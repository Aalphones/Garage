<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\Actor;
use Garage\Domain\DeadlineService;
use Garage\Domain\DomainError;
use Garage\Domain\PaperService;
use Garage\Http\Request;
use Garage\Http\Response;
use PDO;

/** Routen der Oberfläche für Papiere, Stichtage und Notfallkarte; dieselben Dienste wie `get_papers`. */
final class PaperController
{
    public function papers(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new PaperService($pdo))->forVehicle($vehicleId, $actor));
    }

    public function deadlines(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => ['deadlines' => (new DeadlineService($pdo))->forVehicle($vehicleId, $actor)]);
    }

    public function emergency(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new PaperService($pdo))->emergency($vehicleId, $actor));
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
