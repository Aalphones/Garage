<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\MeasurementService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class MeasurementTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var MeasurementService $measurements */
        $measurements = $services['measurements'];

        $registry->add(new Tool(
            'record_measurement',
            'Messwerte erfassen',
            'Trägt Messwerte eines Tages ein (Profiltiefe, Reifendruck, Bremsbelag, Bremsscheibe, Batterie, Frostschutz, HU-Bremswert, sonstiges). Mehrere Werte in einem Aufruf, z. B. vier Profiltiefen (vl, vr, hl, hr). Die Einheit steht je Größe fest (mm, bar, V, A, °C, %); nur bei sonstiges nennst du label und unit selbst. Datum, Größe, Position und Bezeichnung erkennen denselben Messwert wieder: ein zweiter identischer Aufruf meldet je Wert unveraendert, fehlende Angaben werden ergaenzt, ein Widerspruch zu einem bestätigten Wert wird klaerfall (open_item_ids). Mit odometer_km entsteht oder bestätigt sich zugleich der Kilometerstand zum Messdatum (Feld odometer; Kilometer laufen nur vorwärts). Alles oder nichts: ein ungültiger Wert schreibt gar nichts. Beispiel: {"vehicle_id": 12, "measured_on": "2026-04-01", "odometer_km": 27500, "values": [{"quantity": "profiltiefe", "position": "vl", "value": "6.5"}, {"quantity": "profiltiefe", "position": "vr", "value": "6.0"}], "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'measured_on' => Schema::text('Datum der Messung, YYYY-MM-DD'),
                'odometer_km' => Schema::integer('Kilometerstand zum Messdatum, 0 bis 2000000; legt einen Kilometerstand an'),
                'event_id' => Schema::integer('Ereignis (aus list_events), bei dem gemessen wurde, z. B. die Inspektion'),
                'values' => Schema::list(
                    Schema::object([
                        'quantity' => Schema::choice(MeasurementService::quantities(), 'Größe; die Einheit ist je Größe fest'),
                        'position' => Schema::choice(MeasurementService::positions(), 'Position: vl, vr, hl, hr (Rad), vorne, hinten, gesamt (Standard)'),
                        'value' => Schema::money('Messwert als Zahl oder Text mit Punkt oder Komma, höchstens zwei Nachkommastellen, z. B. "6.5"; negativ erlaubt (Frostschutz "-25")'),
                        'label' => Schema::text('Bezeichnung, 1 bis 80 Zeichen — nur und Pflicht bei sonstiges'),
                        'unit' => Schema::text('Einheit, 1 bis 10 Zeichen — Pflicht bei sonstiges, sonst weglassen'),
                    ], ['quantity', 'value']),
                    'Messwerte, 1 bis 20 Einträge; je Größe und Position höchstens einer'
                ),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'measured_on', 'values', 'source', 'confidence']),
            Tool::annotations('Messwerte erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($measurements->record((int) $args['vehicle_id'], $args, $actor))
        ));

        $registry->add(new Tool(
            'get_measurements',
            'Messwerte lesen',
            'Liefert die Messwerte eines Fahrzeugs als Reihen je Größe und Position, die Punkte nach Datum aufsteigend: quantity, position, label, unit, points (date, value, odometer_km, source, confidence). Mit quantity nur diese Größe. Beispiel: {"vehicle_id": 12, "quantity": "profiltiefe"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'quantity' => Schema::choice(MeasurementService::quantities(), 'nur diese Größe'),
            ], ['vehicle_id']),
            Tool::annotations('Messwerte lesen', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($measurements->series((int) $args['vehicle_id'], $args['quantity'] ?? null, $actor))
        ));
    }
}
