<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\VehicleService;
use Garage\Http\Request;
use Garage\Http\Response;

/** `GET /garage/api/vehicles`: Garagen mit ihren Fahrzeugen für die Auswahlfelder der Oberfläche. */
final class VehicleController
{
    public function list(Request $request): never
    {
        $actor = ApiAuth::user($request);

        Response::json(['garages' => (new VehicleService(Connection::open()))->list($actor)]);
    }
}
