<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\DomainError;
use Garage\Domain\ValueService;
use Garage\Http\Request;
use Garage\Http\Response;

/** Wert eines Fahrzeugs für die Oberfläche; dieselbe Ausgabe wie `get_value_summary` (ADR-023). */
final class ValueController
{
    public function summary(Request $request, int $vehicleId): never
    {
        $actor = ApiAuth::user($request);

        try {
            $body = (new ValueService(Connection::open()))->summary($vehicleId, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($body);
    }
}
