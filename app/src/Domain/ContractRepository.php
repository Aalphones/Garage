<?php

declare(strict_types=1);

namespace Garage\Domain;

use InvalidArgumentException;
use PDO;

/**
 * Lesen und Status der Vertragstabellen; hängt die Garagen des Akteurs selbst an (Regel 3 in AGENTS.md). Ein Vertrag
 * gehört zu einem Fahrzeug oder — mit `vehicle_id NULL` — zur ganzen Garage.
 */
final class ContractRepository
{
    private const CONTRACTS = 'contracts';
    private const EVENTS = 'contract_events';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Sperrt die Garage bis zum Ende der Transaktion, damit gleichzeitige Schreibungen an Garagen-Verträgen nacheinander
     * laufen (Gegenstück zu `VehicleAccess::writable`). Fremd und unbekannt sind beide `null`.
     *
     * @return array{id: int, name: string}|null
     */
    public function lockGarage(int $garageId, Actor $actor): ?array
    {
        if (!$actor->canRead($garageId)) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT id, name FROM garages WHERE id = ? FOR UPDATE');
        $statement->execute([$garageId]);
        $row = $statement->fetch();

        return $row === false ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    /**
     * Ein aktiver Vertrag; mit `$lock` bis zum Ende der Transaktion gesperrt.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $contractId, Actor $actor, bool $lock = false): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf("SELECT * FROM contracts WHERE id = ? AND status = 'aktiv' AND garage_id IN (%s)%s", self::placeholders($garageIds), $lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$contractId, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Verträge, die für ein Fahrzeug gelten: seine eigenen und die seiner Garage. Eigene zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function forVehicle(int $vehicleId, int $garageId, Actor $actor): array
    {
        if (!$actor->canRead($garageId)) {
            return [];
        }

        $statement = $this->pdo->prepare(
            "SELECT * FROM contracts
             WHERE garage_id = ? AND status = 'aktiv' AND (vehicle_id = ? OR vehicle_id IS NULL)
             ORDER BY vehicle_id IS NULL, kind, provider, id"
        );
        $statement->execute([$garageId, $vehicleId]);

        return $statement->fetchAll();
    }

    /**
     * Garagen-Verträge (ohne Fahrzeug) einer Garage.
     *
     * @return list<array<string, mixed>>
     */
    public function forGarage(int $garageId, Actor $actor): array
    {
        if (!$actor->canRead($garageId)) {
            return [];
        }

        $statement = $this->pdo->prepare(
            "SELECT * FROM contracts WHERE garage_id = ? AND status = 'aktiv' AND vehicle_id IS NULL ORDER BY kind, provider, id"
        );
        $statement->execute([$garageId]);

        return $statement->fetchAll();
    }

    /**
     * Aktive Zeilen einer Untertabelle (Perioden, Anteile, Vorgänge) zu mehreren Verträgen, neueste zuerst.
     *
     * @param list<int> $contractIds
     * @return list<array<string, mixed>>
     */
    public function childrenOf(string $table, array $contractIds, Actor $actor): array
    {
        $order = match ($table) {
            'contract_terms', 'contract_allocations' => 'valid_from DESC, id DESC',
            self::EVENTS => 'occurred_on DESC, id DESC',
            default => throw new InvalidArgumentException(sprintf('Tabelle „%s“ ist keine Untertabelle eines Vertrags.', $table)),
        };
        $garageIds = $actor->garageIds();
        if ($contractIds === [] || $garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT * FROM %s WHERE contract_id IN (%s) AND status = 'aktiv' AND garage_id IN (%s) ORDER BY %s",
                $table,
                self::placeholders($contractIds),
                self::placeholders($garageIds),
                $order
            )
        );
        $statement->execute([...$contractIds, ...$garageIds]);

        return $statement->fetchAll();
    }

    public function countActiveVehicles(int $garageId, Actor $actor): int
    {
        if (!$actor->canRead($garageId)) {
            return 0;
        }
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE garage_id = ? AND status = 'aktiv'");
        $statement->execute([$garageId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Ein Vertrag oder Vorgang in jedem Status, für Löschen und Wiederherstellen; `vehicle_id` ist das Fahrzeug des Vertrags
     * oder `null` bei einem Garagen-Vertrag. Ein Vorgang an einem gelöschten Vertrag gilt als unbekannt.
     *
     * @return array<string, mixed>|null
     */
    public function findAnyStatus(string $table, int $id, Actor $actor, bool $lock = false): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $sql = match ($table) {
            self::CONTRACTS => 'SELECT * FROM contracts WHERE id = ? AND garage_id IN (%s)%s',
            self::EVENTS => "SELECT e.*, c.vehicle_id FROM contract_events e JOIN contracts c ON c.id = e.contract_id AND c.status = 'aktiv'
                             WHERE e.id = ? AND e.garage_id IN (%s)%s",
            default => throw new InvalidArgumentException(sprintf('Tabelle „%s“ lässt sich nicht über Verträge löschen.', $table)),
        };
        $statement = $this->pdo->prepare(sprintf($sql, self::placeholders($garageIds), $lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function setStatus(string $table, int $id, string $status, ?string $reason, Actor $actor): void
    {
        self::assertTable($table);
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            sprintf('UPDATE %s SET status = ?, status_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND garage_id IN (%s)', $table, self::placeholders($garageIds))
        );
        $statement->execute([$status, $reason, $id, ...$garageIds]);
    }

    /**
     * Gibt es in derselben Garage einen anderen aktiven Datensatz mit demselben Schlüssel aus `ContractMerger::TABLES`?
     * Der Schlüssel enthält `vehicle_id` bzw. `contract_id`, darum genügt die Garage als Bereich.
     */
    public function hasActiveDuplicate(string $table, int $id, Actor $actor): bool
    {
        self::assertTable($table);
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return false;
        }

        $keyConditions = array_map(static fn (string $column): string => sprintf('other.%1$s <=> own.%1$s', $column), ContractMerger::TABLES[$table]['key']);
        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT 1 FROM %1\$s own JOIN %1\$s other ON other.garage_id = own.garage_id AND other.id <> own.id
                 WHERE own.id = ? AND other.status = 'aktiv' AND other.garage_id IN (%2\$s) AND %3\$s LIMIT 1",
                $table,
                self::placeholders($garageIds),
                implode(' AND ', $keyConditions)
            )
        );
        $statement->execute([$id, ...$garageIds]);

        return $statement->fetchColumn() !== false;
    }

    private static function assertTable(string $table): void
    {
        if (!isset(ContractMerger::TABLES[$table])) {
            throw new InvalidArgumentException(sprintf('Tabelle „%s“ ist keine Vertragstabelle.', $table));
        }
    }

    /** @param list<int|string> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
