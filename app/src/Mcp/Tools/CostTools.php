<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\CostCalculator;
use Garage\Domain\CostService;
use Garage\Domain\ExpenseService;
use Garage\Domain\FuelService;
use Garage\Domain\Validate;
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
        /** @var CostService $costs */
        $costs = $services['costs'];

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

        $registry->add(new Tool(
            'get_cost_summary',
            'Kosten lesen',
            'Rechnet die Kosten eines Fahrzeugs (vehicle_id) oder einer Garage (garage_id, genau eins von beiden) in vier Blöcken: fix (Vertragsperioden, Plaketten, HU über 24 Monate, Anteile an Garagen-Verträgen), run (Tankungen, Zubehör, Ausgaben über spread_months), shop (Werkstatt-Ereignisse außer Kauf und HU, Bruttobetrag), loss (Wertverlust linear vom Kaufpreis bis zum jüngsten Wert). Alles tagesgenau: Betrag ÷ Tage der Periode, es zählen nur Tage im Zeitraum und im Aktiv-Zeitraum des Autos (ab Kaufpreis bzw. Zulassung bis Verkauf/Stilllegung). period: 12m (Standard), seit_kauf oder jahr:JJJJ. Ausgabe in Cent: months (je Kalendermonat fix, run, shop, loss, running_cents, full_cents), blocks (cents und ein Satz), running_cents (laufend, ohne Wertverlust), full_cents (mit Wertverlust), month_running_cents und month_full_cents (Summe ÷ Tage/30,4375), per_km_full_cents (Euro je km als Text, zwei Nachkommastellen, null ohne km), km, missing (Versicherung, Steuer, Kraftstoff, Wertverlust — nenne sie dem Nutzer), posts (group block: je Block; group monat: je Monat mit month_cents; jeder Posten mit explanation = Rechenweg und Herkunft) und shares (Anteile des Autos an Garagen-Verträgen). Fahrzeug zusätzlich: per_km (je Monat Euro pro km mit Wertverlust über die letzten 12 Monate, bei kürzerer Haltedauer ab Beginn; ohne die ersten zwei Haltemonate). Garage zusätzlich: vehicles (je Auto Monatswerte) und garage_contracts (Betrag/Jahr, Umlage gleich/km/fest, Anteil je Auto heute). Garagen-ID steht in list_vehicles.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'garage_id' => Schema::integer('ID der Garage (statt vehicle_id) für den Unterhalt aller aktiven Autos'),
                'period' => Schema::text('Zeitraum: 12m (Standard, heute − 12 Monate + 1 Tag bis heute), seit_kauf oder jahr:JJJJ, z. B. "jahr:2026"'),
                'group' => Schema::choice(CostCalculator::GROUPS, 'Posten je Block (Standard) oder je Monat'),
            ], []),
            Tool::annotations('Kosten lesen', 'R'),
            Scopes::READ,
            static function (array $args, Actor $actor) use ($costs): ToolResult {
                $hasVehicle = isset($args['vehicle_id']);
                if ($hasVehicle === isset($args['garage_id'])) {
                    throw Validate::invalid('vehicle_id', 'Genau eins von vehicle_id und garage_id angeben.', '{"vehicle_id": 12, "period": "12m"}');
                }
                $period = isset($args['period']) ? (string) $args['period'] : null;
                $group = isset($args['group']) ? (string) $args['group'] : null;
                if ($hasVehicle) {
                    return ToolResult::json($costs->summary((int) $args['vehicle_id'], $period, $group, $actor));
                }

                return ToolResult::json($costs->garageSummary((int) $args['garage_id'], $period, $group, $actor));
            }
        ));
    }
}
