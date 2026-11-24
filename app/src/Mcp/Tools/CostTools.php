<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\ExpenseService;
use Garage\Domain\FuelService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class CostTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var FuelService $fuel */
        $fuel = $services['fuel'];
        /** @var ExpenseService $expenses */
        $expenses = $services['expenses'];

        $registry->add(new Tool(
            'record_fuel',
            'Tankung erfassen',
            'Trägt eine Tankung ins Tankbuch ein und legt in derselben Transaktion den Kilometerstand dazu an. Datum und Kilometerstand erkennen dieselbe Tankung wieder: ein zweiter identischer Aufruf meldet unveraendert, später ergänzte Angaben (z. B. station) ergaenzt, ein Widerspruch zu einem bestätigten Wert klaerfall (open_item_ids). odometer meldet das Ergebnis des Kilometerstands; ein Stand, der rückwärts läuft, wird dort klaerfall, die Tankung bleibt gespeichert. consumption_l_100km ist der Verbrauch seit der vorherigen Volltankung (nur bei is_full true und wenn es eine vorherige Volltankung gibt, sonst null); gerechnet beim Lesen, nie gespeichert. liters 0,5 bis 200, amount_eur 0,01 bis 1.000 (Gesamtbetrag der Tankung). Beispiel: {"vehicle_id": 12, "fueled_on": "2026-11-20", "odometer_km": 27410, "liters": "42.35", "amount_eur": "71.80", "is_full": true, "station": "Aral Friedrichstraße", "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'fueled_on' => Schema::text('Datum der Tankung YYYY-MM-DD'),
                'odometer_km' => Schema::integer('Kilometerstand beim Tanken, 0 bis 2.000.000'),
                'liters' => Schema::text('Getankte Liter, 0,5 bis 200, höchstens zwei Nachkommastellen, z. B. "42.35"'),
                'amount_eur' => Schema::money('Gesamtbetrag der Tankung in Euro, 0,01 bis 1.000, z. B. "71.80"'),
                'is_full' => Schema::boolean('true, wenn voll getankt wurde (nur dann entsteht ein Verbrauch), sonst false'),
                'station' => Schema::text('Tankstelle, 1 bis 80 Zeichen'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'fueled_on', 'odometer_km', 'liters', 'amount_eur', 'is_full', 'source', 'confidence']),
            Tool::annotations('Tankung erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($fuel->record((int) $args['vehicle_id'], $args, $actor))
        ));

        $registry->add(new Tool(
            'record_expense',
            'Ausgabe erfassen',
            'Trägt eine sonstige Ausgabe ein, die weder Werkstatt noch Tanken noch Vertrag ist: Parken, Wäsche, Pflege, Maut, Reifeneinlagerung, Bußgeld. Datum, Kategorie und Betrag erkennen dieselbe Ausgabe wieder (unveraendert, ergaenzt, klaerfall wie bei record_fuel). spread_months verteilt den Betrag gleichmäßig auf 1 bis 36 Monate ab spent_on (Standard 1, z. B. 12 für eine Jahreskarte). Beispiel: {"vehicle_id": 12, "spent_on": "2026-11-12", "category": "parken", "amount_eur": "18.50", "note": "Parkhaus Hauptbahnhof", "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'spent_on' => Schema::text('Datum der Ausgabe YYYY-MM-DD'),
                'category' => Schema::choice(ExpenseService::CATEGORIES, 'Kategorie der Ausgabe'),
                'amount_eur' => Schema::money('Betrag in Euro, über 0, z. B. "18.50"'),
                'spread_months' => Schema::integer('Auf so viele Monate verteilt, 1 bis 36 (Standard 1)'),
                'note' => Schema::text('Anmerkung, 1 bis 200 Zeichen'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'spent_on', 'category', 'amount_eur', 'source', 'confidence']),
            Tool::annotations('Ausgabe erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($expenses->record((int) $args['vehicle_id'], $args, $actor))
        ));
    }
}
