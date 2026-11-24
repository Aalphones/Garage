<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\Actor;
use Garage\Domain\CostService;
use Garage\Domain\DomainError;
use Garage\Domain\ExpenseService;
use Garage\Domain\FuelService;
use Garage\Http\Request;
use Garage\Http\Response;
use PDO;

/**
 * Routen der Oberfläche für Kosten, Tankbuch und Ausgaben. Lesen ruft denselben Dienst wie die Werkzeuge; Eingaben von Menschen
 * tragen die Herkunft `nutzer`/`bestaetigt` (ADR-025).
 */
final class CostController
{
    public function fuel(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new FuelService($pdo))->list($vehicleId, $actor));
    }

    public function addFuel(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new FuelService($pdo))->recordByHuman($vehicleId, $request->json(), $actor));
    }

    public function expenses(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new ExpenseService($pdo))->list($vehicleId, $actor));
    }

    public function addExpense(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new ExpenseService($pdo))->recordByHuman($vehicleId, $request->json(), $actor));
    }

    public function costs(Request $request, int $vehicleId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new CostService($pdo))->summary($vehicleId, $request->query('period'), $request->query('group'), $actor));
    }

    /** Unterhalt einer Garage; jede Rolle der Garage darf lesen, fremd und unbekannt sind 404. */
    public function garageCosts(Request $request, int $garageId): never
    {
        $this->respondJson($request, static fn (Actor $actor, PDO $pdo): array => (new CostService($pdo))->garageSummary($garageId, $request->query('period'), $request->query('group'), $actor));
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
