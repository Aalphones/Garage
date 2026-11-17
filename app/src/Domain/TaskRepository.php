<?php

declare(strict_types=1);

namespace Garage\Domain;

use LogicException;
use PDO;

/** Wartungsaufgaben und Intervallregeln; hängt die Garagen des Akteurs selbst an (Regel 3 in AGENTS.md). */
final class TaskRepository
{
    public const RULE_CURRENT = 'aktuell';
    public const RULE_SUPERSEDED = 'abgeloest';
    public const TASK_ACTIVE = 'aktiv';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** „Heute“ kommt aus der Datenbank (UTC), nie aus der PHP-Uhr. */
    public function utcToday(): string
    {
        return (string) $this->pdo->query('SELECT UTC_DATE()')->fetchColumn();
    }

    /** @return array{id: int, garage_id: int, vehicle_id: int, task_key: string, title: string}|null */
    public function findTask(int $vehicleId, string $taskKey, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, garage_id, vehicle_id, task_key, title FROM tasks WHERE vehicle_id = ? AND task_key = ? AND garage_id IN (%s)',
                self::placeholders($garageIds)
            )
        );
        $statement->execute([$vehicleId, $taskKey, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'garage_id' => (int) $row['garage_id'],
            'vehicle_id' => (int) $row['vehicle_id'],
            'task_key' => (string) $row['task_key'],
            'title' => (string) $row['title'],
        ];
    }

    public function insertTask(int $garageId, int $vehicleId, string $taskKey, string $title, Actor $actor): int
    {
        $this->assertOwnGarage($garageId, $actor);
        $statement = $this->pdo->prepare(
            "INSERT INTO tasks (garage_id, vehicle_id, task_key, title, status, actor, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'aktiv', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute([$garageId, $vehicleId, $taskKey, $title, $actor->name]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateTitle(int $taskId, int $garageId, string $title): void
    {
        $statement = $this->pdo->prepare('UPDATE tasks SET title = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND garage_id = ?');
        $statement->execute([$title, $taskId, $garageId]);
    }

    /**
     * Die aktuelle Regel einer Aufgabe, gesperrt für die Transaktion (`$lock`, nur beim Schreiben sinnvoll).
     *
     * @return array{id: int, interval_km: int|null, interval_months: int|null, first_km: int|null, first_months: int|null, mode: string, valid_from: string, source: string, source_url: string|null, confidence: string}|null
     */
    public function currentRule(int $taskId, Actor $actor, bool $lock = true): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id, interval_km, interval_months, first_km, first_months, mode, valid_from, source, source_url, confidence
                 FROM task_rules WHERE task_id = ? AND rule_status = 'aktuell' AND garage_id IN (%s) ORDER BY id DESC LIMIT 1%s",
                self::placeholders($garageIds),
                $lock ? ' FOR UPDATE' : ''
            )
        );
        $statement->execute([$taskId, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : self::rule($row);
    }

    /**
     * Eine Regel (auch abgelöste) samt ihrer Aufgabe, zum Auflösen eines Klärfalls.
     *
     * @return array{task: array{id: int, garage_id: int, vehicle_id: int, task_key: string, title: string}, rule: array{id: int, interval_km: int|null, interval_months: int|null, first_km: int|null, first_months: int|null, mode: string, valid_from: string, source: string, source_url: string|null, confidence: string}}|null
     */
    public function findRuleWithTask(int $ruleId, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT t.id AS task_id, t.garage_id, t.vehicle_id, t.task_key, t.title, r.id, r.interval_km, r.interval_months, r.first_km, r.first_months,
                        r.mode, r.valid_from, r.source, r.source_url, r.confidence
                 FROM task_rules r JOIN tasks t ON t.id = r.task_id AND t.garage_id = r.garage_id
                 WHERE r.id = ? AND r.garage_id IN (%s)',
                self::placeholders($garageIds)
            )
        );
        $statement->execute([$ruleId, ...$garageIds]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'task' => [
                'id' => (int) $row['task_id'],
                'garage_id' => (int) $row['garage_id'],
                'vehicle_id' => (int) $row['vehicle_id'],
                'task_key' => (string) $row['task_key'],
                'title' => (string) $row['title'],
            ],
            'rule' => self::rule($row),
        ];
    }

    /**
     * @param array{interval_km: string|null, interval_months: string|null, first_km: string|null, first_months: string|null, mode: string, valid_from: string} $values
     * @param array{source: string, source_url: string|null, confidence: string} $provenance
     */
    public function insertRule(int $garageId, int $taskId, array $values, array $provenance, Actor $actor): int
    {
        $this->assertOwnGarage($garageId, $actor);
        $statement = $this->pdo->prepare(
            "INSERT INTO task_rules (garage_id, task_id, interval_km, interval_months, first_km, first_months, mode, valid_from, rule_status, source, source_url, confidence, actor, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'aktuell', ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $statement->execute([
            $garageId,
            $taskId,
            $values['interval_km'],
            $values['interval_months'],
            $values['first_km'],
            $values['first_months'],
            $values['mode'],
            $values['valid_from'],
            $provenance['source'],
            $provenance['source_url'],
            $provenance['confidence'],
            $actor->name,
            $actor->userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function supersedeRule(int $ruleId, int $garageId): void
    {
        $statement = $this->pdo->prepare("UPDATE task_rules SET rule_status = 'abgeloest' WHERE id = ? AND garage_id = ?");
        $statement->execute([$ruleId, $garageId]);
    }

    /**
     * Aktive Aufgaben mit ihrer aktuellen Regel (`null`, falls keine besteht) für viele Fahrzeuge auf einmal.
     *
     * @param list<int> $vehicleIds
     * @return list<array{vehicle_id: int, task_key: string, title: string, rule: array{id: int, interval_km: int|null, interval_months: int|null, first_km: int|null, first_months: int|null, mode: string, valid_from: string, source: string, source_url: string|null, confidence: string}|null}>
     */
    public function listTasksWithRules(array $vehicleIds, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === [] || $vehicleIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT t.vehicle_id, t.task_key, t.title, r.id AS rule_id, r.interval_km, r.interval_months, r.first_km, r.first_months,
                        r.mode, r.valid_from, r.source, r.source_url, r.confidence
                 FROM tasks t
                 LEFT JOIN task_rules r ON r.task_id = t.id AND r.rule_status = 'aktuell' AND r.garage_id = t.garage_id
                 WHERE t.status = 'aktiv' AND t.vehicle_id IN (%s) AND t.garage_id IN (%s)
                 ORDER BY t.vehicle_id, t.task_key",
                self::placeholders($vehicleIds),
                self::placeholders($garageIds)
            )
        );
        $statement->execute([...$vehicleIds, ...$garageIds]);

        $tasks = [];
        foreach ($statement->fetchAll() as $row) {
            $tasks[] = [
                'vehicle_id' => (int) $row['vehicle_id'],
                'task_key' => (string) $row['task_key'],
                'title' => (string) $row['title'],
                'rule' => $row['rule_id'] === null ? null : self::rule($row + ['id' => $row['rule_id']]),
            ];
        }

        return $tasks;
    }

    /**
     * Je Fahrzeug und Aufgabenschlüssel die jüngste Ausführung: ein aktives Ereignis mit einer aktiven Position dieses
     * Schlüssels. Bei gleichem Datum gewinnt das spätere Ereignis.
     *
     * @param list<int> $vehicleIds
     * @return array<int, array<string, array{event_id: int, date: string, km: int|null}>> Fahrzeug-ID => Aufgabenschlüssel => Ausführung
     */
    public function lastExecutions(array $vehicleIds, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === [] || $vehicleIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT e.vehicle_id, i.task_key, e.id AS event_id, e.performed_on, e.odometer_km
                 FROM events e
                 JOIN event_items i ON i.event_id = e.id AND i.status = 'aktiv' AND i.task_key IS NOT NULL AND i.garage_id = e.garage_id
                 WHERE e.status = 'aktiv' AND e.vehicle_id IN (%s) AND e.garage_id IN (%s)
                 ORDER BY e.performed_on, e.id",
                self::placeholders($vehicleIds),
                self::placeholders($garageIds)
            )
        );
        $statement->execute([...$vehicleIds, ...$garageIds]);

        $executions = [];
        foreach ($statement->fetchAll() as $row) {
            // Aufsteigend sortiert: der jeweils letzte Treffer ist der jüngste.
            $executions[(int) $row['vehicle_id']][(string) $row['task_key']] = [
                'event_id' => (int) $row['event_id'],
                'date' => (string) $row['performed_on'],
                'km' => $row['odometer_km'] === null ? null : (int) $row['odometer_km'],
            ];
        }

        return $executions;
    }

    private function assertOwnGarage(int $garageId, Actor $actor): void
    {
        if (!$actor->canRead($garageId)) {
            throw new LogicException('Schreibzugriff auf eine fremde Garage.');
        }
    }

    /** @param list<int|string> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, interval_km: int|null, interval_months: int|null, first_km: int|null, first_months: int|null, mode: string, valid_from: string, source: string, source_url: string|null, confidence: string}
     */
    private static function rule(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'interval_km' => self::nullableInt($row['interval_km']),
            'interval_months' => self::nullableInt($row['interval_months']),
            'first_km' => self::nullableInt($row['first_km']),
            'first_months' => self::nullableInt($row['first_months']),
            'mode' => (string) $row['mode'],
            'valid_from' => (string) $row['valid_from'],
            'source' => (string) $row['source'],
            'source_url' => $row['source_url'] === null ? null : (string) $row['source_url'],
            'confidence' => (string) $row['confidence'],
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
