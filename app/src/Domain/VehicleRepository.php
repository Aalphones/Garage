<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PDO;

/**
 * Jede Methode nimmt den Akteur und hängt dessen `garage_id`s selbst an (Regel 3 in AGENTS.md).
 * Hat der Akteur keine Garage, läuft keine Abfrage.
 */
final class VehicleRepository
{
    public const STATUS_ACTIVE = 'aktiv';
    public const STATUS_DELETED = 'geloescht';
    public const FACT_CURRENT = 'aktuell';
    public const FACT_SUPERSEDED = 'abgeloest';
    public const FACT_DISPUTED = 'strittig';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id: int, garage_id: int, label: string, status: string}> */
    public function listForActor(Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, garage_id, label, status FROM vehicles WHERE garage_id IN (%s) AND status <> ? ORDER BY garage_id, id',
                self::placeholders($garageIds)
            )
        );
        $statement->execute([...$garageIds, self::STATUS_DELETED]);

        return array_map(static fn (array $row): array => self::vehicle($row), $statement->fetchAll());
    }

    /**
     * Gelöschte Fahrzeuge der Garagen, in denen der Akteur Inhaber ist, gelöscht vor höchstens `$days` Tagen.
     * Der Löschzeitpunkt ist `updated_at` (Löschen ist die letzte Änderung am Fahrzeug).
     *
     * @return list<array{id: int, garage_id: int, label: string, status_reason: string|null, deleted_at: string, restorable_until: string}>
     */
    public function listDeleted(Actor $actor, int $days): array
    {
        $ownerGarageIds = array_keys(array_filter(
            $actor->roles,
            static fn (string $role): bool => $role === Actor::ROLE_OWNER
        ));
        if ($ownerGarageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, garage_id, label, status_reason, updated_at FROM vehicles
                 WHERE garage_id IN (%s) AND status = ? AND updated_at >= UTC_TIMESTAMP() - INTERVAL %d DAY
                 ORDER BY updated_at DESC, id DESC',
                self::placeholders($ownerGarageIds),
                $days
            )
        );
        $statement->execute([...$ownerGarageIds, self::STATUS_DELETED]);

        return array_map(static function (array $row) use ($days): array {
            $deletedAt = new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC'));

            return [
                'id' => (int) $row['id'],
                'garage_id' => (int) $row['garage_id'],
                'label' => (string) $row['label'],
                'status_reason' => $row['status_reason'] === null ? null : (string) $row['status_reason'],
                'deleted_at' => $deletedAt->format('Y-m-d\TH:i:s\Z'),
                'restorable_until' => $deletedAt->modify('+' . $days . ' days')->format('Y-m-d\TH:i:s\Z'),
            ];
        }, $statement->fetchAll());
    }

    /**
     * Mit `$lock` wird die Zeile bis zum Ende der Transaktion gesperrt, damit zwei gleichzeitige Schreibungen
     * an einem Fahrzeug nacheinander laufen (die Konfliktregel liest den aktuellen Wert).
     *
     * @return array{id: int, garage_id: int, label: string, status: string}|null
     */
    public function find(int $vehicleId, Actor $actor, bool $lock = false): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, garage_id, label, status FROM vehicles WHERE id = ? AND garage_id IN (%s) AND status <> ?%s',
                self::placeholders($garageIds),
                $lock ? ' FOR UPDATE' : ''
            )
        );
        $statement->execute([$vehicleId, ...$garageIds, self::STATUS_DELETED]);
        $row = $statement->fetch();

        return $row === false ? null : self::vehicle($row);
    }

    /**
     * Ein Fahrzeug in jedem Status (auch gelöscht), gesperrt bis zum Ende der Transaktion. `is_restorable` vergleicht
     * `updated_at` in der Abfrage mit der Frist von `$restoreDays` Tagen.
     *
     * @return array{id: int, garage_id: int, label: string, status: string, status_reason: string|null, updated_at: string, is_restorable: bool}|null
     */
    public function findForLifecycle(int $vehicleId, int $restoreDays, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, garage_id, label, status, status_reason, updated_at, updated_at >= UTC_TIMESTAMP() - INTERVAL %d DAY AS is_restorable
                 FROM vehicles WHERE id = ? AND garage_id IN (%s) FOR UPDATE',
                $restoreDays,
                self::placeholders($garageIds)
            )
        );
        $statement->execute([$vehicleId, ...$garageIds]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::vehicle($row) + [
            'status_reason' => $row['status_reason'] === null ? null : (string) $row['status_reason'],
            'updated_at' => (string) $row['updated_at'],
            'is_restorable' => (int) $row['is_restorable'] === 1,
        ];
    }

    /** @return string der neue `updated_at` (UTC) */
    public function setStatus(int $vehicleId, string $status, ?string $reason, Actor $actor): string
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            throw new LogicException('Statuswechsel ohne Garage.');
        }

        $placeholders = self::placeholders($garageIds);
        $update = $this->pdo->prepare(
            sprintf('UPDATE vehicles SET status = ?, status_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND garage_id IN (%s)', $placeholders)
        );
        $update->execute([$status, $reason, $vehicleId, ...$garageIds]);

        $select = $this->pdo->prepare(sprintf('SELECT updated_at FROM vehicles WHERE id = ? AND garage_id IN (%s)', $placeholders));
        $select->execute([$vehicleId, ...$garageIds]);

        return (string) $select->fetchColumn();
    }

    public function insert(int $garageId, string $label, Actor $actor): int
    {
        $this->assertOwnGarage($garageId, $actor);
        $statement = $this->pdo->prepare(
            'INSERT INTO vehicles (garage_id, label, status, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([$garageId, $label, self::STATUS_ACTIVE, $actor->name]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array{id: int, field: string, value: string, source: string, source_url: string|null, confidence: string, actor: string, created_at: string}> */
    public function currentFacts(int $vehicleId, Actor $actor): array
    {
        return $this->factsWithStatus($vehicleId, self::FACT_CURRENT, $actor);
    }

    /**
     * Strittige Werte mit dem zugehörigen offenen Klärfall (0, falls keiner mehr offen ist).
     *
     * @return list<array{id: int, field: string, value: string, source: string, source_url: string|null, confidence: string, actor: string, created_at: string, open_item_id: int}>
     */
    public function disputedFacts(int $vehicleId, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT f.id, f.field, f.value, f.source, f.source_url, f.confidence, f.actor, f.created_at,
                        COALESCE((SELECT MIN(o.id) FROM open_items o
                                  WHERE o.ref_table = 'vehicle_facts' AND o.ref_id = f.id AND o.status = 'offen'), 0) AS open_item_id
                 FROM vehicle_facts f
                 WHERE f.vehicle_id = ? AND f.status = ? AND f.garage_id IN (%s)
                 ORDER BY f.id",
                self::placeholders($garageIds)
            )
        );
        $statement->execute([$vehicleId, self::FACT_DISPUTED, ...$garageIds]);

        return array_map(
            static fn (array $row): array => self::fact($row) + ['open_item_id' => (int) $row['open_item_id']],
            $statement->fetchAll()
        );
    }

    /**
     * Aktuelle Werte weniger Felder für viele Fahrzeuge auf einmal (Übersicht).
     *
     * @param list<int> $vehicleIds
     * @param list<string> $fields
     * @return array<int, array<string, string>> Fahrzeug-ID => Feld => Wert
     */
    public function currentValues(array $vehicleIds, array $fields, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === [] || $vehicleIds === [] || $fields === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT vehicle_id, field, value FROM vehicle_facts
                 WHERE status = ? AND vehicle_id IN (%s) AND field IN (%s) AND garage_id IN (%s)',
                self::placeholders($vehicleIds),
                self::placeholders($fields),
                self::placeholders($garageIds)
            )
        );
        $statement->execute([self::FACT_CURRENT, ...$vehicleIds, ...$fields, ...$garageIds]);

        $values = [];
        foreach ($statement->fetchAll() as $row) {
            $values[(int) $row['vehicle_id']][(string) $row['field']] = (string) $row['value'];
        }

        return $values;
    }

    /** Ein strittiger Wert, der schon mit demselben Wert vorliegt (und noch einen offenen Klärfall hat). */
    public function findOpenDispute(int $vehicleId, string $field, string $value, Actor $actor): ?int
    {
        foreach ($this->disputedFacts($vehicleId, $actor) as $fact) {
            if ($fact['field'] === $field && $fact['value'] === $value && $fact['open_item_id'] > 0) {
                return $fact['open_item_id'];
            }
        }

        return null;
    }

    /** @param array{source: string, source_url: string|null, confidence: string} $provenance */
    public function insertFact(int $garageId, int $vehicleId, string $field, string $value, array $provenance, string $status, Actor $actor): int
    {
        $this->assertOwnGarage($garageId, $actor);
        $statement = $this->pdo->prepare(
            'INSERT INTO vehicle_facts (garage_id, vehicle_id, field, value, source, source_url, confidence, status, actor, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $statement->execute([
            $garageId,
            $vehicleId,
            $field,
            $value,
            $provenance['source'],
            $provenance['source_url'],
            $provenance['confidence'],
            $status,
            $actor->name,
            $actor->userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function supersede(int $factId, Actor $actor): void
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            sprintf('UPDATE vehicle_facts SET status = ? WHERE id = ? AND garage_id IN (%s)', self::placeholders($garageIds))
        );
        $statement->execute([self::FACT_SUPERSEDED, $factId, ...$garageIds]);
    }

    public function setFactStatus(int $factId, string $status, Actor $actor): void
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            sprintf('UPDATE vehicle_facts SET status = ? WHERE id = ? AND garage_id IN (%s)', self::placeholders($garageIds))
        );
        $statement->execute([$status, $factId, ...$garageIds]);
    }

    /** @return array{id: int, vehicle_id: int, field: string, value: string, source: string, source_url: string|null, confidence: string, actor: string, created_at: string, status: string}|null */
    public function findFact(int $factId, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, vehicle_id, field, value, source, source_url, confidence, actor, created_at, status FROM vehicle_facts WHERE id = ? AND garage_id IN (%s)',
                self::placeholders($garageIds)
            )
        );
        $statement->execute([$factId, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : self::fact($row) + ['vehicle_id' => (int) $row['vehicle_id'], 'status' => (string) $row['status']];
    }

    /**
     * @param list<int> $vehicleIds
     * @return array<int, int> Fahrzeug-ID => Zahl offener Klärfälle
     */
    public function countOpenItems(array $vehicleIds, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === [] || $vehicleIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT vehicle_id, COUNT(*) AS open_count FROM open_items
                 WHERE status = 'offen' AND vehicle_id IN (%s) AND garage_id IN (%s)
                 GROUP BY vehicle_id",
                self::placeholders($vehicleIds),
                self::placeholders($garageIds)
            )
        );
        $statement->execute([...$vehicleIds, ...$garageIds]);

        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            $counts[(int) $row['vehicle_id']] = (int) $row['open_count'];
        }

        return $counts;
    }

    /** @return list<array{id: int, field: string, value: string, source: string, source_url: string|null, confidence: string, actor: string, created_at: string}> */
    private function factsWithStatus(int $vehicleId, string $status, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, field, value, source, source_url, confidence, actor, created_at FROM vehicle_facts
                 WHERE vehicle_id = ? AND status = ? AND garage_id IN (%s) ORDER BY id',
                self::placeholders($garageIds)
            )
        );
        $statement->execute([$vehicleId, $status, ...$garageIds]);

        return array_map(static fn (array $row): array => self::fact($row), $statement->fetchAll());
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
     * @return array{id: int, garage_id: int, label: string, status: string}
     */
    private static function vehicle(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'garage_id' => (int) $row['garage_id'],
            'label' => (string) $row['label'],
            'status' => (string) $row['status'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, field: string, value: string, source: string, source_url: string|null, confidence: string, actor: string, created_at: string}
     */
    private static function fact(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'field' => (string) $row['field'],
            'value' => (string) $row['value'],
            'source' => (string) $row['source'],
            'source_url' => $row['source_url'] === null ? null : (string) $row['source_url'],
            'confidence' => (string) $row['confidence'],
            'actor' => (string) $row['actor'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
