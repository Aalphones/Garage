<?php

declare(strict_types=1);

namespace Garage\Api;

use Garage\Auth\ApiAuth;
use Garage\Db\Connection;
use Garage\Domain\DocumentService;
use Garage\Domain\DomainError;
use Garage\Domain\EventService;
use Garage\Http\Request;
use Garage\Http\Response;

/** Lese-Routen für Ereignisse (Scheckheft, Wartung); Ausgabe wie `list_events` und `get_event`. */
final class EventController
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = [])
    {
    }

    public function listForVehicle(Request $request, int $vehicleId): never
    {
        $actor = ApiAuth::user($request);

        try {
            $events = (new EventService(Connection::open()))->list($vehicleId, null, null, null, $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        // Die Lücken im Scheckheft berechnet eine spätere Phase; bis dahin bleibt die Liste leer.
        Response::json(['events' => $events, 'gaps' => []]);
    }

    public function show(Request $request, int $eventId): never
    {
        $actor = ApiAuth::user($request);

        try {
            $pdo = Connection::open();
            $event = (new EventService($pdo))->get($eventId, $actor);
            $event['documents'] = (new DocumentService($pdo, $this->config))->forTarget('events', $event['id'], $actor);
        } catch (DomainError $error) {
            ApiErrors::respond($error);
        }

        Response::json($event);
    }
}
