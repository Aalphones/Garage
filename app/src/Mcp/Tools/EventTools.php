<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\EventService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class EventTools
{
    private const RECORD_EXAMPLE = '{"vehicle_id": 12, "kind": "inspektion", "title": "Inspektion 30.000 km", "performed_on": "2026-03-02", "odometer_km": 26910, "workshop": {"name": "Autohaus Ostmann", "city": "Hamburg", "manufacturer_partner": true}, "invoice_number": "R-2026-0815", "net_eur": "1083.00", "vat_eur": "205.77", "gross_eur": "1288.77", "items": [{"type": "arbeit", "description": "Ölwechsel", "task_key": "oelwechsel", "net_eur": "49.90"}, {"type": "teil", "description": "Ölfilter", "part_number": "04E115561H", "quantity": 1, "net_eur": "12.40"}, {"type": "material", "description": "Motoröl 0W-20", "quantity": "4.300", "unit": "l", "net_eur": "58.05"}], "source": "beleg", "confidence": "bestaetigt"}';

    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var EventService $events */
        $events = $services['events'];

        $registry->add(new Tool(
            'record_service_event',
            'Werkstattereignis erfassen',
            'Trägt ein Ereignis im Scheckheft ein (Inspektion, Reparatur, HU, Reifen …) mit Werkstatt, Rechnung, Positionen und Kilometerstand. Die Rechnungsnummer (zusammen mit der Werkstatt) erkennt dasselbe Ereignis wieder; ohne Rechnungsnummer sind es Art, Leistungsdatum und Werkstatt. Ein zweiter Aufruf mit derselben Rechnung ergänzt statt zu verdoppeln: result und items_result sind angelegt, ergaenzt (nur leere Angaben gefüllt), aktualisiert, unveraendert oder klaerfall. Weichen die Positionen von den gespeicherten ab, bleiben die gespeicherten stehen und items_result ist klaerfall (items_open_item_id), ein Mensch entscheidet. Mit odometer_km entsteht oder bestätigt sich der Kilometerstand zum Leistungsdatum; Kilometer laufen nur vorwärts, ein widersprüchlicher Stand wird strittig (odometer.result klaerfall). Geld in Euro als Zahl oder Text mit höchstens zwei Nachkommastellen; ein Rabatt ist ein negativer Betrag. Alles oder nichts: ein ungültiger Wert schreibt gar nichts. Prüfe vorab mit validate_service_event. Beispiel: ' . self::RECORD_EXAMPLE . '.',
            self::eventSchema(),
            Tool::annotations('Werkstattereignis erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($events->record($args, $actor))
        ));

        $registry->add(new Tool(
            'validate_service_event',
            'Werkstattereignis prüfen (Trockenlauf)',
            'Trockenlauf: zeigt, was angelegt würde und welche Klärfälle entstünden; speichert nichts. IDs im Ergebnis sind vorläufig. Eingabe und Ausgabe wie record_service_event, dazu dry_run: true. Nutze es vor einem Import, um Doppelungen, Widersprüche und Eingabefehler zu sehen.',
            self::eventSchema(),
            Tool::annotations('Werkstattereignis prüfen (Trockenlauf)', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($events->record($args, $actor, true))
        ));

        $registry->add(new Tool(
            'list_events',
            'Ereignisse auflisten',
            'Listet die Ereignisse eines Fahrzeugs, neueste zuerst: id, kind, title, performed_on, odometer_km, workshop, invoice_number, gross_eur, item_count, source, confidence. Filter: from und to (YYYY-MM-DD, einschließlich), kind. Beispiel: {"vehicle_id": 12, "from": "2025-01-01", "kind": "inspektion"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'from' => Schema::text('frühestes Leistungsdatum, YYYY-MM-DD'),
                'to' => Schema::text('spätestes Leistungsdatum, YYYY-MM-DD'),
                'kind' => Schema::choice(EventService::KINDS, 'nur diese Art'),
            ], ['vehicle_id']),
            Tool::annotations('Ereignisse auflisten', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json(['events' => $events->list((int) $args['vehicle_id'], $args['from'] ?? null, $args['to'] ?? null, $args['kind'] ?? null, $actor)])
        ));

        $registry->add(new Tool(
            'get_event',
            'Ereignis lesen',
            'Liefert ein Ereignis vollständig: Werkstatt, Rechnungsnummer, Beträge in Euro, Positionen in der Reihenfolge der Rechnung (type, description, task_key, part_number, quantity, unit, net_eur), den Kilometerstand und die Herkunft. Beispiel: {"event_id": 31}.',
            Schema::object(['event_id' => Schema::integer('ID des Ereignisses aus list_events')], ['event_id']),
            Tool::annotations('Ereignis lesen', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($events->get((int) $args['event_id'], $actor))
        ));
    }

    /** @return array<string, mixed> */
    private static function eventSchema(): array
    {
        return Schema::object([
            'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
            'kind' => Schema::choice(EventService::KINDS, 'Art des Ereignisses'),
            'title' => Schema::text('Kurzbezeichnung, 1 bis 200 Zeichen, z. B. "Inspektion 30.000 km"'),
            'performed_on' => Schema::text('Leistungsdatum, YYYY-MM-DD'),
            'invoiced_on' => Schema::text('Rechnungsdatum, YYYY-MM-DD'),
            'odometer_km' => Schema::integer('Kilometerstand zum Leistungsdatum, 0 bis 2000000'),
            'workshop' => Schema::object([
                'name' => Schema::text('Name der Werkstatt, 1 bis 120 Zeichen'),
                'city' => Schema::text('Ort, 1 bis 80 Zeichen; zusammen mit dem Namen erkennt er dieselbe Werkstatt wieder'),
                'manufacturer_partner' => Schema::boolean('Vertragswerkstatt des Herstellers'),
            ], ['name']),
            'invoice_number' => Schema::text('Rechnungsnummer, 1 bis 60 Zeichen; erkennt dasselbe Ereignis wieder'),
            'order_number' => Schema::text('Auftragsnummer, 1 bis 60 Zeichen'),
            'net_eur' => Schema::money('Nettosumme in Euro, z. B. "1083.00"'),
            'vat_eur' => Schema::money('Umsatzsteuer in Euro'),
            'gross_eur' => Schema::money('Bruttosumme in Euro; bei Inspektion und Reparatur erwartet'),
            'digital_service_record' => Schema::boolean('Eintrag im digitalen Serviceheft des Herstellers vorhanden'),
            'items' => Schema::list(
                Schema::object([
                    'type' => Schema::choice(EventService::ITEM_TYPES, 'arbeit, teil, material oder pauschale'),
                    'description' => Schema::text('Text der Position, 1 bis 200 Zeichen'),
                    'task_key' => Schema::text('Aufgabenschlüssel für den Wartungsplan, z. B. "oelwechsel" (Kleinbuchstaben, Ziffern, _)'),
                    'part_number' => Schema::text('Teilenummer, 1 bis 60 Zeichen'),
                    'manufacturer' => Schema::text('Hersteller des Teils, 1 bis 80 Zeichen'),
                    'quantity' => Schema::money('Menge mit höchstens drei Nachkommastellen, z. B. "4.300"'),
                    'unit' => Schema::text('Einheit, 1 bis 10 Zeichen, z. B. "l"'),
                    'net_eur' => Schema::money('Nettobetrag der Position in Euro; ein Rabatt ist negativ'),
                ], ['type', 'description']),
                'Positionen der Rechnung in ihrer Reihenfolge, höchstens 200'
            ),
        ] + ProvenanceSchema::properties(), ['vehicle_id', 'kind', 'title', 'performed_on', 'source', 'confidence']);
    }
}
