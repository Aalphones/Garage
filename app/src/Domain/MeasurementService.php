<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/** Messwerte eines Fahrzeugs (Profiltiefe, Reifendruck, Bremsbeläge, Batterie …) über den Datensatz-Abgleich. */
final class MeasurementService
{
    private const TABLE = 'measurements';
    private const OTHER = 'sonstiges';
    private const DEFAULT_POSITION = 'gesamt';
    private const POSITIONS = ['vl', 'vr', 'hl', 'hr', 'vorne', 'hinten', 'gesamt'];
    private const MAX_VALUES = 20;
    /** Die Spalte ist DECIMAL(10, 2); der Prüfer lässt mehr zu. */
    private const MAX_VALUE = 99999.99;
    private const EXAMPLE = '{"values": [{"quantity": "profiltiefe", "position": "vl", "value": "6.5"}]}';

    /** Einheit je Größe; bei `sonstiges` nennt die Eingabe sie selbst. */
    public const UNITS = [
        'profiltiefe' => 'mm',
        'reifendruck' => 'bar',
        'bremsbelag' => 'mm',
        'bremsscheibe' => 'mm',
        'batterie_spannung' => 'V',
        'batterie_kaltstart' => 'A',
        'frostschutz' => '°C',
        'hu_bremswert' => '%',
    ];

    private VehicleAccess $access;
    private RecordMerger $merger;
    private RecordRepository $records;
    private OdometerService $odometer;
    private EventLink $eventLink;

    public function __construct(private readonly PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->merger = new RecordMerger($pdo);
        $this->records = new RecordRepository($pdo);
        $this->odometer = new OdometerService($pdo);
        $this->eventLink = new EventLink($pdo);
    }

    /** @return list<string> */
    public static function quantities(): array
    {
        return [...array_keys(self::UNITS), self::OTHER];
    }

    /** @return list<string> */
    public static function positions(): array
    {
        return self::POSITIONS;
    }

    /**
     * Alles oder nichts: ein ungültiger Wert schreibt gar nichts. Datum, Größe, Position und Bezeichnung erkennen denselben
     * Messwert wieder; mit `odometer_km` entsteht oder bestätigt sich zugleich der Kilometerstand zum Messdatum.
     *
     * @param array<string, mixed> $input measured_on, odometer_km?, event_id?, values, source, source_url?, confidence
     * @return array{measurements: list<array<string, mixed>>, odometer: array<string, mixed>|null}
     */
    public function record(int $vehicleId, array $input, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($vehicleId, $input, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $measuredOn = Validate::date('measured_on', $input['measured_on'] ?? null, '{"measured_on": "2026-04-01"}');
            $odometerKm = ($input['odometer_km'] ?? null) === null ? null : OdometerService::validKm('odometer_km', $input['odometer_km']);
            $eventId = $this->eventLink->validated('event_id', $input['event_id'] ?? null, $vehicleId, $actor);
            $rows = self::validValues($input['values'] ?? null);
            $provenance = Provenance::validate($input, 'measurement');

            $results = [];
            foreach ($rows as $row) {
                $values = $row + ['measured_on' => $measuredOn, 'odometer_km' => $odometerKm, 'event_id' => $eventId];
                $merged = $this->merger->merge(self::TABLE, $values, $provenance, $vehicle, $actor);
                $result = ['quantity' => $row['quantity'], 'position' => $row['position']];
                if ($row['label'] !== '') {
                    $result['label'] = $row['label'];
                }
                $results[] = $result + ['measurement_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
            }

            $odometer = null;
            if ($odometerKm !== null) {
                $odometer = $this->odometer->recordInTransaction($vehicle, $measuredOn, $odometerKm, $eventId === null ? null : (int) $eventId, null, $provenance, $actor);
            }

            return ['measurements' => $results, 'odometer' => $odometer];
        });
    }

    /**
     * Eine Reihe je Größe, Position und Bezeichnung; die Punkte laufen nach Datum aufwärts.
     *
     * @return array{series: list<array<string, mixed>>}
     */
    public function series(int $vehicleId, mixed $quantity, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);
        $filter = $quantity === null ? null : Validate::enum('quantity', $quantity, self::quantities());

        $series = [];
        foreach ($this->records->listForVehicle(self::TABLE, $vehicleId, $actor) as $row) {
            if ($filter !== null && $row['quantity'] !== $filter) {
                continue;
            }
            $key = $row['quantity'] . '|' . $row['position'] . '|' . $row['label'];
            if (!isset($series[$key])) {
                $series[$key] = ['quantity' => (string) $row['quantity'], 'position' => (string) $row['position'], 'label' => (string) $row['label'], 'unit' => (string) $row['unit'], 'points' => []];
            }
            $series[$key]['points'][] = [
                'id' => (int) $row['id'],
                'date' => (string) $row['measured_on'],
                'value' => (float) $row['value'],
                'odometer_km' => $row['odometer_km'] === null ? null : (int) $row['odometer_km'],
                'source' => (string) $row['source'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        return ['series' => array_values($series)];
    }

    /**
     * @return list<array<string, string>> quantity, position, label, value, unit — Datum und Kilometer ergänzt der Aufrufer
     */
    private static function validValues(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_VALUES) {
            throw Validate::invalid('values', sprintf('Erwartet werden 1 bis %d Messwerte.', self::MAX_VALUES), self::EXAMPLE);
        }

        $rows = [];
        $seen = [];
        foreach ($raw as $index => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw Validate::invalid(sprintf('values[%d]', $index), 'Jeder Messwert ist ein Objekt.', self::EXAMPLE);
            }
            $row = self::validValue($index, $entry);
            $key = mb_strtolower($row['quantity'] . '|' . $row['position'] . '|' . $row['label']);
            if (isset($seen[$key])) {
                throw Validate::invalid(sprintf('values[%d]', $index), 'Dieselbe Größe an derselben Position kommt mehrfach in einer Anfrage vor. Pro Größe und Position genau ein Wert.', self::EXAMPLE);
            }
            $seen[$key] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, string>
     */
    private static function validValue(int $index, array $entry): array
    {
        $prefix = sprintf('values[%d].', $index);
        $quantity = Validate::enum($prefix . 'quantity', $entry['quantity'] ?? null, self::quantities());
        $position = ($entry['position'] ?? null) === null ? self::DEFAULT_POSITION : Validate::enum($prefix . 'position', $entry['position'], self::POSITIONS);
        // Negativ ist erlaubt (Frostschutz in °C).
        $value = Validate::decimal($prefix . 'value', $entry['value'] ?? null, 2, '{"value": "6.5"}', true);
        if (abs((float) $value) > self::MAX_VALUE) {
            throw Validate::invalid($prefix . 'value', sprintf('Der Betrag darf höchstens %s sein.', self::MAX_VALUE), '{"value": "6.5"}');
        }

        if ($quantity === self::OTHER) {
            $label = Validate::text($prefix . 'label', $entry['label'] ?? null, 1, 80, '{"quantity": "sonstiges", "label": "Spurweite vorn", "unit": "mm", "value": "1520"}');
            $unit = Validate::text($prefix . 'unit', $entry['unit'] ?? null, 1, 10, '{"quantity": "sonstiges", "label": "Spurweite vorn", "unit": "mm", "value": "1520"}');
        } else {
            $label = '';
            $unit = self::UNITS[$quantity];
            self::assertNoDeviation($prefix . 'label', $entry['label'] ?? null, sprintf('label gibt es nur bei sonstiges; %s hat keins.', $quantity), $quantity);
            self::assertNoDeviation($prefix . 'unit', $entry['unit'] ?? null, sprintf('Die Einheit von %s ist fest %s.', $quantity, $unit), $quantity, $unit);
        }

        return ['quantity' => $quantity, 'position' => $position, 'label' => $label, 'value' => $value, 'unit' => $unit];
    }

    /** Eine mitgegebene Angabe, die nicht zur Größe passt, wird nicht stillschweigend verworfen (32 „psi“ als 32 bar wäre falsch). */
    private static function assertNoDeviation(string $field, mixed $given, string $reason, string $quantity, ?string $expected = null): void
    {
        if ($given === null || $given === '' || $given === $expected) {
            return;
        }

        throw Validate::invalid($field, $reason, sprintf('{"quantity": "%s", "value": "6.5"}', $quantity));
    }
}
