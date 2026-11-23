<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\DomainError;
use Garage\Domain\DueService;
use Garage\Http\Request;
use Garage\Http\Response;

/** `GET /garage/api/due`: Fälligkeiten aller aktiven Fahrzeuge; Ausgabe wie `get_due_items` ohne `vehicle_id`. */
final class DueController
{
    public function list(Request $request): never
    {
        $actor = ApiAuth::user($request);

        try {
            $due = (new DueService(Connection::open()))->dueItems(null, DueService::DEFAULT_HORIZON_MONTHS, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($due);
    }
}
