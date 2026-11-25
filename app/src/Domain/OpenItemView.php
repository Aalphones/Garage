<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/**
 * Stellt für einen Menschen nebeneinander, was bisher gilt (`current`) und was neu gemeldet wurde (`proposed`) —
 * je nach `ref_table` des Klärfalls. Nur Lesen; fehlt ein Bezug (Zeile weg, Vorschlag leer), steht dort `null`.
 */
final class OpenItemView
{
    private const ITEM_TYPE_LABELS = ['arbeit' => 'Arbeit', 'teil' => 'Teil', 'material' => 'Material', 'pauschale' => 'Pauschale'];
    private const BOOLEAN_COLUMNS = ['digital_service_record', 'verified', 'repaired'];

    private VehicleRepository $vehicles;
    private RecordRepository $records;
    private ContractRepository $contracts;
    private EventRepository $events;
    private OdometerRepository $readings;
    private TaskRepository $tasks;

    public function __construct(PDO $pdo)
    {
        $this->vehicles = new VehicleRepository($pdo);
        $this->records = new RecordRepository($pdo);
        $this->contracts = new ContractRepository($pdo);
        $this->events = new EventRepository($pdo);
        $this->readings = new OdometerRepository($pdo);
        $this->tasks = new TaskRepository($pdo);
    }

    /**
     * @param array{ref_table: string|null, ref_id: int|null, proposal: array<string, mixed>|null} $item
     * @return array{current: array<string, mixed>|null, proposed: array<string, mixed>|null}
     */
    public function describe(array $item, Actor $actor): array
    {
        $refTable = $item['ref_table'];
        $refId = $item['ref_id'];
        $proposal = $item['proposal'] ?? [];
        if ($refTable === null || $refId === null) {
            $suggestion = $proposal['suggestion'] ?? null;

            return ['current' => null, 'proposed' => is_string($suggestion) ? self::entry($suggestion, null, null, null) : null];
        }

        return match ($refTable) {
            'vehicle_facts' => $this->fact($refId, $actor),
            'odometer_readings' => $this->readingPair($refId, $proposal, $actor),
            'task_rules' => $this->rulePair($refId, $proposal, $actor),
            'events' => isset($proposal['items']) ? $this->itemPair($refId, $proposal, $actor) : $this->columnPair($refTable, $refId, $proposal, $actor),
            default => $this->columnPair($refTable, $refId, $proposal, $actor),
        };
    }

    /** @return array{current: array<string, mixed>|null, proposed: array<string, mixed>|null} */
    private function fact(int $factId, Actor $actor): array
    {
        $disputed = $this->vehicles->findFact($factId, $actor);
        if ($disputed === null) {
            return ['current' => null, 'proposed' => null];
        }

        $current = null;
        foreach ($this->vehicles->currentFacts($disputed['vehicle_id'], $actor) as $fact) {
            if ($fact['field'] === $disputed['field']) {
                $current = self::entry($fact['value'], $fact['source'], $fact['source_url'], $fact['confidence'], $fact['actor']);
            }
        }

        return ['current' => $current, 'proposed' => self::entry($disputed['value'], $disputed['source'], $disputed['source_url'], $disputed['confidence'], $disputed['actor'])];
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array{current: array<string, mixed>|null, proposed: array<string, mixed>|null}
     */
    private function columnPair(string $table, int $rowId, array $proposal, Actor $actor): array
    {
        $field = $proposal['field'] ?? null;
        $value = $proposal['value'] ?? null;
        // Die Vertragstabellen gehören nicht zu `RecordMerger::TABLES`; `RecordRepository` weist sie ab.
        if (isset(ContractMerger::TABLES[$table])) {
            $row = $this->contracts->findRow($table, $rowId, $actor);
        } else {
            $row = $this->records->find($table, $rowId, $actor);
        }
        if (!is_string($field) || !is_string($value) || $row === null || !array_key_exists($field, $row)) {
            return ['current' => null, 'proposed' => null];
        }

        return [
            'current' => self::entry(self::column($field, $row[$field] === null ? null : (string) $row[$field]), (string) $row['source'], self::nullableText($row['source_url']), (string) $row['confidence'], (string) $row['actor']),
            'proposed' => self::proposed(self::column($field, $value), $proposal),
        ];
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array{current: array<string, mixed>|null, proposed: array<string, mixed>|null}
     */
    private function itemPair(int $eventId, array $proposal, Actor $actor): array
    {
        $event = $this->events->find($eventId, $actor);
        $stored = $this->events->activeItems($eventId, $actor);
        $proposedItems = is_array($proposal['items']) ? $proposal['items'] : [];

        return [
            'current' => $event === null ? null : self::entry(
                implode("\n", array_map(static fn (array $item): string => self::itemLine((string) $item['item_type'], (string) $item['description'], $item['net_cents'] === null ? null : (int) $item['net_cents']), $stored)),
                (string) $event['source'],
                self::nullableText($event['source_url']),
                (string) $event['confidence'],
                (string) $event['actor']
            ),
            'proposed' => self::proposed(
                implode("\n", array_map(static fn (array $item): string => self::itemLine((string) ($item['type'] ?? ''), (string) ($item['description'] ?? ''), isset($item['net_eur']) ? (int) round((float) $item['net_eur'] * 100) : null), $proposedItems)),
                $proposal
            ),
        ];
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array{current: array<string, mixed>|null, proposed: array<string, mixed>|null}
     */
    private function readingPair(int $readingId, array $proposal, Actor $actor): array
    {
        $conflictIds = array_map(intval(...), is_array($proposal['conflicts_with'] ?? null) ? $proposal['conflicts_with'] : []);
        $conflicts = $this->readings->findByIds($conflictIds, $actor);
        $proposed = $this->readings->findByIds([$readingId], $actor)[0] ?? null;

        return [
            'current' => $conflicts === [] ? null : self::entry(
                implode("\n", array_map(static fn (array $reading): string => self::readingLine($reading['km'], $reading['read_on']), $conflicts)),
                $conflicts[0]['source'],
                $conflicts[0]['source_url'],
                $conflicts[0]['confidence'],
                $conflicts[0]['actor']
            ),
            'proposed' => $proposed === null ? null : self::entry(self::readingLine($proposed['km'], $proposed['read_on']), $proposed['source'], $proposed['source_url'], $proposed['confidence'], $proposed['actor']),
        ];
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array{current: array<string, mixed>|null, proposed: array<string, mixed>|null}
     */
    private function rulePair(int $ruleId, array $proposal, Actor $actor): array
    {
        $found = $this->tasks->findRuleWithTask($ruleId, $actor);
        if ($found === null) {
            return ['current' => null, 'proposed' => null];
        }

        $current = $this->tasks->currentRule($found['task']['id'], $actor, false);

        return [
            'current' => $current === null ? null : self::entry(self::ruleLine($current), $current['source'], $current['source_url'], $current['confidence']),
            'proposed' => self::proposed(self::ruleLine($proposal), $proposal),
        ];
    }

    /**
     * Die Regel als Text, etwa „15.000 km / 24 Monate, fest“; ein Erstintervall hängt hinten dran.
     *
     * @param array<string, mixed> $rule
     */
    private static function ruleLine(array $rule): string
    {
        $line = self::intervalText($rule['interval_km'] ?? null, $rule['interval_months'] ?? null) . ', ' . (string) ($rule['mode'] ?? '');
        $hasFirst = ($rule['first_km'] ?? null) !== null || ($rule['first_months'] ?? null) !== null;
        if ($hasFirst) {
            $line .= ' (erstmals ' . self::intervalText($rule['first_km'] ?? null, $rule['first_months'] ?? null) . ')';
        }

        return $line;
    }

    private static function intervalText(mixed $km, mixed $months): string
    {
        $parts = [];
        if ($km !== null) {
            $parts[] = self::kilometers((int) $km);
        }
        if ($months !== null) {
            $parts[] = (int) $months . ' Monate';
        }

        return $parts === [] ? '–' : implode(' / ', $parts);
    }

    private static function itemLine(string $type, string $description, ?int $netCents): string
    {
        $line = (self::ITEM_TYPE_LABELS[$type] ?? $type) . ' · ' . $description;
        if ($netCents !== null) {
            $line .= ' · ' . self::euro($netCents);
        }

        return $line;
    }

    private static function readingLine(int $km, string $readOn): string
    {
        return self::kilometers($km) . ' am ' . implode('.', array_reverse(explode('-', $readOn)));
    }

    /** Wert einer Spalte für Menschen: Cent als Euro, Kilometer mit Punkt, Ja/Nein statt 1/0. */
    private static function column(string $field, ?string $value): string
    {
        if ($value === null) {
            return '–';
        }
        if (str_ends_with($field, '_cents')) {
            return self::euro((int) $value);
        }
        if (str_ends_with($field, '_km')) {
            return self::kilometers((int) $value);
        }
        if (in_array($field, self::BOOLEAN_COLUMNS, true)) {
            return $value === '1' ? 'ja' : 'nein';
        }

        return $value;
    }

    private static function euro(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }

    private static function kilometers(int $km): string
    {
        return number_format($km, 0, ',', '.') . ' km';
    }

    private static function nullableText(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @param array<string, mixed> $proposal source, source_url, confidence
     * @return array<string, mixed>
     */
    private static function proposed(string $value, array $proposal): array
    {
        return self::entry($value, self::nullableText($proposal['source'] ?? null), self::nullableText($proposal['source_url'] ?? null), self::nullableText($proposal['confidence'] ?? null));
    }

    /** @return array{value: string, source: string|null, source_url: string|null, confidence: string|null, actor: string|null} */
    private static function entry(string $value, ?string $source, ?string $sourceUrl, ?string $confidence, ?string $actor = null): array
    {
        return ['value' => $value, 'source' => $source, 'source_url' => $sourceUrl, 'confidence' => $confidence, 'actor' => $actor];
    }
}
