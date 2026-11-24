<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\ValuationService;
use Garage\Domain\ValueService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class ValueTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var ValuationService $valuations */
        $valuations = $services['valuations'];
        /** @var ValueService $value */
        $value = $services['value'];

        $registry->add(new Tool(
            'add_valuation',
            'Bewertung erfassen',
            'Trägt einen Ankerwert ein: Kaufpreis (kind kaufpreis, zählt für den Wertverlust), Verkaufspreis, Gutachten, Händlerangebot, Online-Bewertung (bewertung) oder eigene Schätzung. Datum, Art und Anbieter (provider, ohne Angabe leer) erkennen dieselbe Bewertung wieder: ein zweiter identischer Aufruf meldet unveraendert, neue Angaben ergaenzt, ein Widerspruch zu einem bestätigten Wert klaerfall (open_item_ids). value_type sagt, welcher Wert gemeint ist (Händlereinkauf, Privatverkauf, Händlerverkauf); range_low_eur/range_high_eur ein genanntes Band. Beispiel: {"vehicle_id": 12, "valued_on": "2026-11-20", "kind": "bewertung", "amount_eur": "12400", "value_type": "privatverkauf", "range_low_eur": "11500", "range_high_eur": "13200", "odometer_km": 31200, "provider": "DAT", "source": "recherche", "source_url": "https://example.com/bewertung", "confidence": "wahrscheinlich"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'valued_on' => Schema::text('Datum der Bewertung YYYY-MM-DD'),
                'kind' => Schema::choice(ValuationService::KINDS, 'Art des Ankerwerts'),
                'amount_eur' => Schema::money('Wert in Euro, über 0, z. B. "12400"'),
                'value_type' => Schema::choice(ValuationService::VALUE_TYPES, 'Welcher Wert gemeint ist'),
                'odometer_km' => Schema::integer('Kilometerstand bei der Bewertung'),
                'range_low_eur' => Schema::money('Untergrenze eines genannten Bands in Euro'),
                'range_high_eur' => Schema::money('Obergrenze eines genannten Bands in Euro'),
                'provider' => Schema::text('Anbieter oder Gutachter, 1 bis 120 Zeichen, z. B. "DAT"'),
                'note' => Schema::text('Anmerkung, 1 bis 200 Zeichen'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'valued_on', 'kind', 'amount_eur', 'source', 'confidence']),
            Tool::annotations('Bewertung erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($valuations->addValuation((int) $args['vehicle_id'], $args, $actor))
        ));

        $registry->add(new Tool(
            'add_market_listing',
            'Vergleichsinserat erfassen',
            'Legt ein Vergleichsinserat für den Marktkorridor ab. Die URL erkennt dasselbe Inserat wieder; ein erneuter Abruf trägt Preis, km und fetched_on nach (ergaenzt bzw. aktualisiert, bei bestätigtem Altwert klaerfall). same_drivetrain entscheidest du: passen Motor und Getriebe zum eigenen Auto? counts sagt, ob das Inserat heute zählt — es zählt mit passendem Antrieb, Erstzulassungsjahr ±1, km ±25 % des eigenen geschätzten Stands und höchstens 60 Tage nach fetched_on. Ab 8 zählenden Inseraten entsteht ein Korridor (get_value_summary). Beispiel: {"vehicle_id": 12, "url": "https://example.com/inserat/123", "fetched_on": "2026-11-20", "price_eur": "11950", "odometer_km": 31200, "first_registration": "2020-04", "same_drivetrain": true, "equipment_note": "Navi, Sitzheizung", "postal_code": "10115", "source": "recherche", "source_url": "https://example.com/inserat/123", "confidence": "wahrscheinlich"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'url' => Schema::text('Adresse des Inserats, http(s), bis 500 Zeichen'),
                'fetched_on' => Schema::text('Abrufdatum YYYY-MM-DD'),
                'price_eur' => Schema::money('Angebotspreis in Euro, über 0'),
                'odometer_km' => Schema::integer('Kilometerstand laut Inserat'),
                'first_registration' => Schema::text('Erstzulassung laut Inserat JJJJ-MM'),
                'same_drivetrain' => Schema::boolean('true, wenn Motor und Getriebe zum eigenen Auto passen'),
                'equipment_note' => Schema::text('Auffällige Ausstattung, 1 bis 300 Zeichen'),
                'postal_code' => Schema::text('Postleitzahl des Angebots, 4 bis 10 Zeichen'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'url', 'fetched_on', 'price_eur', 'odometer_km', 'first_registration', 'same_drivetrain', 'source', 'confidence']),
            Tool::annotations('Vergleichsinserat erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($valuations->addListing((int) $args['vehicle_id'], $args, $actor))
        ));

        $registry->add(new Tool(
            'get_value_summary',
            'Wert lesen',
            'Liefert den Wert eines Fahrzeugs: anchors (Bewertungen und Kaufpreis, jüngste zuerst, Beträge in Cent), corridor (counting zählende Inserate; method none unter 8, median ab 8 mit mittlerer Hälfte, regression ab 15 auf km und Alter umgerechnet; median_cents, low_cents, high_cents, explanation in Worten), history (Korridor je Monatsende der letzten 24 Monate, wo genug Inserate zählten, type korridor, dazu jeder Ankerwert als type anker), listings (alle Inserate mit counts und reason_not_counting: drivetrain, year, km, age oder null), factors (acht Wertfaktoren mit verdict plus, minus oder unbekannt, detail und ref = Reiter der Fundstelle) und note. Angebotspreise sind keine Verkaufspreise — sag das, wenn du den Korridor nennst.',
            Schema::object(['vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles')], ['vehicle_id']),
            Tool::annotations('Wert lesen', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($value->summary((int) $args['vehicle_id'], $actor))
        ));
    }
}
