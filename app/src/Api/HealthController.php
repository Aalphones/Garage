<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Http\Response;

/** `GET /garage/api/health`: belegt für den Rauchtest, dass Brücke und gesperrter Code zusammenfinden; ohne Datenbank und ohne Werte aus `.env`. */
final class HealthController
{
    public function show(): never
    {
        Response::json(['status' => 'ok']);
    }
}
