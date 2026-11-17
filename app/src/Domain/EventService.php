<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/**
 * Ereignisse im Scheckheft (Inspektion, Reparatur, HU …) mit Werkstatt, Positionen und Kilometerstand. Dieselbe Rechnung
 * wird ergänzt statt verdoppelt (Datensatz-Abgleich, ADR-009); abweichende Positionen werden ein Klärfall.
 */
final class EventService
{
    public const KINDS = ['inspektion', 'reparatur', 'hu', 'reifen', 'schaden', 'rueckruf', 'kauf', 'sonstiges'];
    public const ITEM_TYPES = ['arbeit', 'teil', 'material', 'pauschale'];

    private const TABLE = 'events';
    private const MAX_ITEMS = 200;
    private const EXAMPLE = '{"vehicle_id": 12, "kind": "inspektion", "title": "Inspektion 30.000 km", "performed_on": "2026-03-02", "odometer_km": 26910, "invoice_number": "R-2026-0815", "gross_eur": "1288.70"}';
    private const ITEM_EXAMPLE = '{"items": [{"type": "arbeit", "description": "Ölwechsel", "task_key": "oelwechsel", "net_eur": "49.90"}]}';
    private const COST_KINDS = ['inspektion', 'reparatur'];

    private VehicleAccess $access;
    private RecordMerger $merger;
    private WorkshopRepository $workshops;
    private EventRepository $events;
    private OdometerService $odometer;
    private OdometerRepository $readings;
    private OpenItemRepository $openItems;
    private AuditRepository $audit;
    private TaskRepository $tasks;

    public function __construct(private readonly PDO $pdo)
    {
        $this->tasks = new TaskRepository($pdo);
        $this->access = new VehicleAccess($pdo);
        $this->merger = new RecordMerger($pdo);
        $this->workshops = new WorkshopRepository($pdo);
        $this->events = new EventRepository($pdo);
        $this->odometer = new OdometerService($pdo);
        $this->readings = new OdometerRepository($pdo);
        $this->openItems = new OpenItemRepository($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * Alles wird vorab geprüft; ein ungültiger Wert schreibt gar nichts. Der Trockenlauf rechnet vollständig durch
     * (Sperren, Abgleich, Klärfälle) und rollt am Ende zurück — die IDs im Ergebnis sind dann vorläufig.
     *
     * @param array<string, mixed> $input siehe `record_service_event`
     * @return array{event_id: int, result: string, items_result: string, items_open_item_id?: int, odometer: array<string, mixed>|null, open_item_ids: list<int>, warnings: list<string>, dry_run?: true}
     */
    public function record(array $input, Actor $actor, bool $dryRun = false): array
    {
        $vehicleId = (int) Validate::int('vehicle_id', $input['vehicle_id'] ?? null, '{"vehicle_id": 12}', 1);
        $parsed = $this->parse($input);
        $work = fn (): array => $this->store($vehicleId, $parsed, $actor);

        if ($dryRun) {
            return Transaction::dryRun($this->pdo, $work) + ['dry_run' => true];
        }

        return Transaction::run($this->pdo, $work);
    }

    /**
     * „Neue Positionen gelten“ (Klärfall auflösen): die aktiven Positionen werden abgelöst, die Positionen des Vorschlags
     * treten an ihre Stelle. Läuft in der Transaktion des Aufrufers, der das Fahrzeug gesperrt hat.
     *
     * @param array<string, mixed> $proposal `items` in der Form der Eingabe von `record_service_event`
     * @return array{before: int, after: int} Zahl der Positionen vorher und nachher
     */
    public function replaceItems(int $eventId, array $proposal, Actor $actor): array
    {
        $items = self::parseItems($proposal['items'] ?? null);
        if ($items === []) {
            throw new DomainError('invalid_value', 'Der Vorschlag enthält keine Positionen.');
        }

        $event = $this->events->find($eventId, $actor);
        if ($event === null) {
            throw new DomainError('not_found', 'Das Ereignis zu diesem Klärfall existiert nicht mehr.');
        }

        $before = count($this->events->activeItems($eventId, $actor));
        $this->events->supersedeItems($eventId, $actor);
        $this->events->insertItems((int) $event['garage_id'], $eventId, $items);

        return ['before' => $before, 'after' => count($items)];
    }

    /**
     * Neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $vehicleId, ?string $from, ?string $to, ?string $kind, Actor $actor): array
    {
        $fromDate = $from === null ? null : Validate::date('from', $from, '{"from": "2025-01-01"}');
        $toDate = $to === null ? null : Validate::date('to', $to, '{"to": "2026-12-31"}');
        $kindValue = $kind === null ? null : Validate::enum('kind', $kind, self::KINDS);
        $this->access->readable($vehicleId, $actor);

        $events = [];
        foreach ($this->events->listForVehicle($vehicleId, $fromDate, $toDate, $kindValue, $actor) as $row) {
            $events[] = [
                'id' => (int) $row['id'],
                'kind' => (string) $row['kind'],
                'title' => (string) $row['title'],
                'performed_on' => (string) $row['performed_on'],
                'odometer_km' => $row['odometer_km'] === null ? null : (int) $row['odometer_km'],
                'workshop' => $row['workshop_id'] === null ? null : ['id' => (int) $row['workshop_id'], 'name' => (string) $row['workshop_name'], 'city' => (string) $row['workshop_city']],
                'invoice_number' => $row['invoice_number'] === null ? null : (string) $row['invoice_number'],
                'gross_eur' => self::euro($row['gross_cents']),
                'item_count' => (int) $row['item_count'],
                'source' => (string) $row['source'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        return $events;
    }

    /**
     * Fremde und unbekannte Ereignisse sind beide `not_found`.
     *
     * @return array<string, mixed>
     */
    public function get(int $eventId, Actor $actor): array
    {
        $row = $this->events->find($eventId, $actor);
        if ($row === null) {
            throw new DomainError('not_found', 'Ein Ereignis mit dieser ID gibt es in deinen Garagen nicht. list_events zeigt die vorhandenen.', ['event_id' => $eventId]);
        }

        $items = [];
        foreach ($this->events->activeItems($eventId, $actor) as $item) {
            $items[] = [
                'line_no' => (int) $item['line_no'],
                'type' => (string) $item['item_type'],
                'description' => (string) $item['description'],
                'task_key' => $item['task_key'] === null ? null : (string) $item['task_key'],
                'part_number' => $item['part_number'] === null ? null : (string) $item['part_number'],
                'manufacturer' => $item['manufacturer'] === null ? null : (string) $item['manufacturer'],
                'quantity' => $item['quantity'] === null ? null : (string) $item['quantity'],
                'unit' => $item['unit'] === null ? null : (string) $item['unit'],
                'net_eur' => self::euro($item['net_cents']),
            ];
        }
        $reading = $this->readings->findForEvent($eventId, $actor);

        return [
            'id' => (int) $row['id'],
            'vehicle_id' => (int) $row['vehicle_id'],
            'kind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'performed_on' => (string) $row['performed_on'],
            'invoiced_on' => $row['invoiced_on'] === null ? null : (string) $row['invoiced_on'],
            'odometer_km' => $row['odometer_km'] === null ? null : (int) $row['odometer_km'],
            'workshop' => $row['workshop_id'] === null ? null : [
                'id' => (int) $row['workshop_id'],
                'name' => (string) $row['workshop_name'],
                'city' => (string) $row['workshop_city'],
                'manufacturer_partner' => $row['workshop_partner'] === null ? null : (bool) $row['workshop_partner'],
            ],
            'invoice_number' => $row['invoice_number'] === null ? null : (string) $row['invoice_number'],
            'order_number' => $row['order_number'] === null ? null : (string) $row['order_number'],
            'net_eur' => self::euro($row['net_cents']),
            'vat_eur' => self::euro($row['vat_cents']),
            'gross_eur' => self::euro($row['gross_cents']),
            'digital_service_record' => $row['digital_service_record'] === null ? null : (bool) $row['digital_service_record'],
            'items' => $items,
            'odometer' => $reading === null ? null : ['reading_id' => $reading['id'], 'read_on' => $reading['read_on'], 'km' => $reading['km'], 'status' => $reading['reading_status'], 'note' => $reading['note']],
            'source' => (string) $row['source'],
            'source_url' => $row['source_url'] === null ? null : (string) $row['source_url'],
            'confidence' => (string) $row['confidence'],
            'actor' => (string) $row['actor'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array{values: array<string, string|null>, workshop: array{name: string, city: string, partner: bool|null}|null, items: list<array<string, string|null>>, provenance: array{source: string, source_url: string|null, confidence: string}, has_gross: bool} $parsed
     * @return array{event_id: int, result: string, items_result: string, items_open_item_id?: int, odometer: array<string, mixed>|null, open_item_ids: list<int>, warnings: list<string>}
     */
    private function store(int $vehicleId, array $parsed, Actor $actor): array
    {
        $vehicle = $this->access->writable($vehicleId, $actor);
        $values = $parsed['values'];
        $workshop = $parsed['workshop'];
        $values['workshop_id'] = $workshop === null ? null : (string) $this->workshops->findOrCreate($vehicle['garage_id'], $workshop['name'], $workshop['city'], $workshop['partner'], $actor);

        // Mit Rechnungsnummer erkennt Werkstatt + Nummer das Ereignis, ohne sie Art + Leistungsdatum + Werkstatt.
        $hasInvoiceNumber = $values['invoice_number'] !== null;
        if ($hasInvoiceNumber) {
            $key = ['workshop_id', 'invoice_number'];
        } else {
            unset($values['invoice_number']);
            $key = ['kind', 'performed_on', 'workshop_id'];
        }
        $merged = $this->merger->merge(self::TABLE, $values, $parsed['provenance'], $vehicle, $actor, $key);
        $eventId = $merged['id'];
        $openItemIds = $merged['open_item_ids'];

        $itemsOutcome = $this->storeItems($vehicle, $eventId, $merged['result'] === RecordMerger::RESULT_CREATED, $parsed, $actor);
        if ($itemsOutcome['open_item_id'] !== null) {
            $openItemIds[] = $itemsOutcome['open_item_id'];
        }

        $odometer = null;
        if ($values['odometer_km'] !== null) {
            $odometer = $this->odometer->recordInTransaction($vehicle, (string) $values['performed_on'], (string) $values['odometer_km'], $eventId, null, $parsed['provenance'], $actor);
            if (isset($odometer['open_item_id'])) {
                $openItemIds[] = $odometer['open_item_id'];
            }
        }

        $result = [
            'event_id' => $eventId,
            'result' => $merged['result'],
            'items_result' => $itemsOutcome['result'],
        ];
        if ($itemsOutcome['open_item_id'] !== null) {
            $result['items_open_item_id'] = $itemsOutcome['open_item_id'];
        }
        $result['odometer'] = $odometer;
        $result['open_item_ids'] = array_values(array_unique($openItemIds));
        $result['warnings'] = [...self::warnings((string) $values['kind'], $parsed['has_gross']), ...$this->missingRuleWarnings($vehicle['id'], $parsed['items'], $actor)];

        return $result;
    }

    /**
     * Ein `task_key`, zu dem es noch keine Aufgabe gibt, taucht in keiner Fälligkeit auf: das Ereignis ist gespeichert,
     * der Agent bekommt den Hinweis, die Intervallregel nachzutragen.
     *
     * @param list<array<string, string|null>> $items
     * @return list<string>
     */
    private function missingRuleWarnings(int $vehicleId, array $items, Actor $actor): array
    {
        $taskKeys = array_unique(array_filter(array_column($items, 'task_key'), static fn (?string $taskKey): bool => $taskKey !== null));

        $warnings = [];
        foreach ($taskKeys as $taskKey) {
            if ($this->tasks->findTask($vehicleId, $taskKey, $actor) === null) {
                $warnings[] = sprintf('task_key ‚%s‘ hat noch keine Intervallregel (upsert_task_rule).', $taskKey);
            }
        }

        return $warnings;
    }

    /**
     * Positionen sind ein Ganzes: ein Ereignis ohne aktive Positionen bekommt sie, gleiche bleiben, abweichende ändern nichts
     * und öffnen einen Klärfall mit der neuen Fassung als Vorschlag.
     *
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param array{items: list<array<string, string|null>>, provenance: array{source: string, source_url: string|null, confidence: string}, values: array<string, string|null>} $parsed
     * @return array{result: string, open_item_id: int|null}
     */
    private function storeItems(array $vehicle, int $eventId, bool $isNewEvent, array $parsed, Actor $actor): array
    {
        $items = $parsed['items'];
        if ($items === []) {
            return ['result' => RecordMerger::RESULT_UNCHANGED, 'open_item_id' => null];
        }

        $stored = $this->events->activeItems($eventId, $actor);
        if ($stored === []) {
            $this->events->insertItems($vehicle['garage_id'], $eventId, $items);
            $this->audit->record($actor, 'events.items', sprintf('events:%d', $eventId), $vehicle['garage_id'], null, ['count' => count($items)], $vehicle['id']);

            return ['result' => $isNewEvent ? RecordMerger::RESULT_CREATED : RecordMerger::RESULT_SUPPLEMENTED, 'open_item_id' => null];
        }
        if (self::sameItems($stored, $items)) {
            return ['result' => RecordMerger::RESULT_UNCHANGED, 'open_item_id' => null];
        }

        return ['result' => RecordMerger::RESULT_DISPUTE, 'open_item_id' => $this->disputeItems($vehicle, $eventId, count($stored), $parsed, $actor)];
    }

    /**
     * @param list<array<string, mixed>> $stored
     * @param list<array<string, string|null>> $items
     */
    private static function sameItems(array $stored, array $items): bool
    {
        if (count($stored) !== count($items)) {
            return false;
        }
        foreach ($items as $index => $item) {
            $row = $stored[$index];
            $storedNet = $row['net_cents'] === null ? null : (string) $row['net_cents'];
            if ((string) $row['item_type'] !== $item['item_type'] || (string) $row['description'] !== $item['description'] || $storedNet !== $item['net_cents']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gleiche Abweichung zum zweiten Mal: bestehender Klärfall, kein neuer.
     *
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param array{items: list<array<string, string|null>>, provenance: array{source: string, source_url: string|null, confidence: string}, values: array<string, string|null>} $parsed
     */
    private function disputeItems(array $vehicle, int $eventId, int $storedCount, array $parsed, Actor $actor): int
    {
        $proposalItems = array_map(self::proposalItem(...), $parsed['items']);
        foreach ($this->openItems->findOpenByRef(self::TABLE, $eventId, $actor) as $openItem) {
            $storedItems = $openItem['proposal']['items'] ?? null;
            if (is_array($storedItems) && self::sortedKeys($storedItems) === self::sortedKeys($proposalItems)) {
                return $openItem['id'];
            }
        }

        $provenance = $parsed['provenance'];
        $subject = $parsed['values']['invoice_number'] === null
            ? sprintf('des Ereignisses „%s“ vom %s', $parsed['values']['title'], $parsed['values']['performed_on'])
            : sprintf('der Rechnung %s', $parsed['values']['invoice_number']);
        $text = sprintf(
            'Die Positionen %s weichen von den gespeicherten ab (%d gegen %d Positionen). Ein Mensch entscheidet, welche gelten.',
            $subject,
            $storedCount,
            count($proposalItems)
        );
        $proposal = ['items' => $proposalItems, 'source' => $provenance['source'], 'source_url' => $provenance['source_url'], 'confidence' => $provenance['confidence']];
        $openItemId = $this->openItems->insert($vehicle['garage_id'], $vehicle['id'], OpenItemRepository::KIND_CONFLICT, $text, self::TABLE, $eventId, $actor, $proposal);
        $this->audit->record($actor, 'events.items_dispute', sprintf('events:%d', $eventId), $vehicle['garage_id'], ['count' => $storedCount], ['count' => count($proposalItems), 'open_item_id' => $openItemId], $vehicle['id']);

        return $openItemId;
    }

    /**
     * Die JSON-Spalte der Datenbank sortiert Objektschlüssel um; verglichen wird deshalb ohne Rücksicht auf die Schlüsselreihenfolge.
     *
     * @param list<array<string, string>> $items
     * @return list<array<string, string>>
     */
    private static function sortedKeys(array $items): array
    {
        return array_map(static function (array $item): array {
            ksort($item);

            return $item;
        }, $items);
    }

    /**
     * Eine Position in der Form der Eingabe, damit ein Mensch den Vorschlag später ohne Neuerfassung übernehmen kann.
     *
     * @param array<string, string|null> $item
     * @return array<string, string>
     */
    private static function proposalItem(array $item): array
    {
        $proposal = ['type' => (string) $item['item_type'], 'description' => (string) $item['description']];
        foreach (['task_key', 'part_number', 'manufacturer', 'quantity', 'unit'] as $field) {
            if ($item[$field] !== null) {
                $proposal[$field] = $item[$field];
            }
        }
        if ($item['net_cents'] !== null) {
            $proposal['net_eur'] = Money::centsToEuro((int) $item['net_cents']);
        }

        return $proposal;
    }

    /** @return list<string> */
    private static function warnings(string $kind, bool $hasGross): array
    {
        if (!$hasGross && in_array($kind, self::COST_KINDS, true)) {
            return ['Bruttosumme fehlt (gross_eur): ohne sie lässt sich später nichts über die Kosten sagen.'];
        }

        return [];
    }

    private static function euro(mixed $cents): ?string
    {
        return $cents === null ? null : Money::centsToEuro((int) $cents);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{values: array<string, string|null>, workshop: array{name: string, city: string, partner: bool|null}|null, items: list<array<string, string|null>>, provenance: array{source: string, source_url: string|null, confidence: string}, has_gross: bool}
     */
    private function parse(array $input): array
    {
        $values = [
            'kind' => Validate::enum('kind', $input['kind'] ?? null, self::KINDS),
            'title' => Validate::text('title', $input['title'] ?? null, 1, 200, self::EXAMPLE),
            'performed_on' => Validate::date('performed_on', $input['performed_on'] ?? null, self::EXAMPLE),
            'invoiced_on' => self::optionalDate('invoiced_on', $input['invoiced_on'] ?? null),
            'odometer_km' => ($input['odometer_km'] ?? null) === null ? null : OdometerService::validKm('odometer_km', $input['odometer_km']),
            'invoice_number' => Validate::optionalText('invoice_number', $input['invoice_number'] ?? null, 1, 60, '{"invoice_number": "R-2026-0815"}'),
            'order_number' => Validate::optionalText('order_number', $input['order_number'] ?? null, 1, 60, '{"order_number": "A-4711"}'),
            'net_cents' => Validate::euroToCents('net_eur', $input['net_eur'] ?? null),
            'vat_cents' => Validate::euroToCents('vat_eur', $input['vat_eur'] ?? null),
            'gross_cents' => Validate::euroToCents('gross_eur', $input['gross_eur'] ?? null),
            'digital_service_record' => Validate::bool('digital_service_record', $input['digital_service_record'] ?? null),
        ];

        return [
            'values' => $values,
            'workshop' => self::parseWorkshop($input['workshop'] ?? null),
            'items' => self::parseItems($input['items'] ?? null),
            'provenance' => Provenance::validate($input, 'event'),
            'has_gross' => $values['gross_cents'] !== null,
        ];
    }

    private static function optionalDate(string $field, mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return Validate::date($field, $raw, sprintf('{"%s": "2026-03-04"}', $field));
    }

    /** @return array{name: string, city: string, partner: bool|null}|null */
    private static function parseWorkshop(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $example = '{"workshop": {"name": "Autohaus Ostmann", "city": "Hamburg", "manufacturer_partner": true}}';
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw Validate::invalid('workshop', 'Die Werkstatt ist ein Objekt mit name.', $example);
        }
        $partner = Validate::bool('workshop.manufacturer_partner', $raw['manufacturer_partner'] ?? null);

        return [
            'name' => Validate::text('workshop.name', $raw['name'] ?? null, 1, 120, $example),
            'city' => Validate::optionalText('workshop.city', $raw['city'] ?? null, 1, 80, $example) ?? '',
            'partner' => $partner === null ? null : $partner === '1',
        ];
    }

    /** @return list<array<string, string|null>> */
    private static function parseItems(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || ($raw !== [] && !array_is_list($raw))) {
            throw Validate::invalid('items', 'Die Positionen sind eine Liste von Objekten.', self::ITEM_EXAMPLE);
        }
        if (count($raw) > self::MAX_ITEMS) {
            throw Validate::invalid('items', sprintf('Höchstens %d Positionen je Aufruf.', self::MAX_ITEMS), self::ITEM_EXAMPLE);
        }

        $items = [];
        foreach ($raw as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw Validate::invalid(sprintf('items[%d]', $index), 'Jede Position ist ein Objekt.', self::ITEM_EXAMPLE);
            }
            $items[] = self::parseItem($index, $item);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, string|null>
     */
    private static function parseItem(int $index, array $item): array
    {
        $prefix = sprintf('items[%d].', $index);

        return [
            'item_type' => Validate::enum($prefix . 'type', $item['type'] ?? null, self::ITEM_TYPES),
            'description' => Validate::text($prefix . 'description', $item['description'] ?? null, 1, 200, self::ITEM_EXAMPLE),
            'task_key' => self::taskKey($prefix . 'task_key', $item['task_key'] ?? null),
            'part_number' => Validate::optionalText($prefix . 'part_number', $item['part_number'] ?? null, 1, 60, self::ITEM_EXAMPLE),
            'manufacturer' => Validate::optionalText($prefix . 'manufacturer', $item['manufacturer'] ?? null, 1, 80, self::ITEM_EXAMPLE),
            'quantity' => ($item['quantity'] ?? null) === null ? null : Validate::decimal($prefix . 'quantity', $item['quantity'], 3, '{"quantity": "5.500"}'),
            'unit' => Validate::optionalText($prefix . 'unit', $item['unit'] ?? null, 1, 10, '{"unit": "l"}'),
            'net_cents' => Validate::euroToCents($prefix . 'net_eur', $item['net_eur'] ?? null, true),
        ];
    }

    private static function taskKey(string $field, mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || preg_match(TaskService::KEY_PATTERN, $raw) !== 1) {
            throw Validate::invalid($field, 'Der Aufgabenschlüssel besteht aus Kleinbuchstaben, Ziffern und Unterstrichen und beginnt mit einem Buchstaben (2 bis 41 Zeichen).', '{"task_key": "oelwechsel"}');
        }

        return $raw;
    }
}
