<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\OdometerService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class OdometerTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var OdometerService $odometer */
        $odometer = $services['odometer'];

        $registry->add(new Tool(
            'record_odometer',
            'Kilometerstand erfassen',
            'Trägt einen Kilometerstand mit Datum ein. Kilometer laufen nur vorwärts: ein Stand, der einem früheren größeren oder einem späteren kleineren widerspricht, wird gespeichert, zählt aber nicht und öffnet einen Klärfall (result klaerfall, open_item_id, ein Mensch entscheidet). Derselbe Stand am selben Tag noch einmal ergibt unveraendert. Ein Stand aus einer Werkstattrechnung gehört in record_service_event (odometer_km). Beispiel: {"vehicle_id": 12, "read_on": "2026-03-02", "km": 26910, "source": "nutzer", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'read_on' => Schema::text('Datum der Ablesung, YYYY-MM-DD'),
                'km' => Schema::integer('Kilometerstand, 0 bis 2000000'),
                'note' => Schema::text('Anlass, 1 bis 200 Zeichen, z. B. "Tacho beim Tanken"'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'read_on', 'km', 'source', 'confidence']),
            Tool::annotations('Kilometerstand erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($odometer->record((int) $args['vehicle_id'], $args, $actor))
        ));
    }
}
