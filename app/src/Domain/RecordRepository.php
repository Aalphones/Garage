<?php

declare(strict_types=1);

namespace Garage\Domain;

use InvalidArgumentException;
use PDO;

/** Lesen der Listen-Tabellen aus dem Datensatz-Abgleich; hängt die Garagen des Akteurs selbst an (Regel 3 in AGENTS.md). */
final class RecordRepository
{
    /** Sortierung je Tabelle; nie ein Wert aus der Eingabe. */
    private const ORDER = [
        'registrations' => 'valid_from DESC, id DESC',
        'equipment' => 'category, name',
        // Reihen je Größe und Position, innerhalb nach Datum aufsteigend (Verlauf).
        'measurements' => 'quantity, position, label, measured_on, id',
        'tire_sets' => 'label, id',
        // `role` ist ein ENUM: verbaut (1) vor alternative (2).
        'parts' => 'component, role, brand, article_number, id',
        'damages' => 'occurred_on DESC, id DESC',
        'recalls' => 'recall_status, title, id',
        // ENUM-Reihenfolge der Kategorie, dann alphabetisch.
        'accessories' => 'category, name, id',
        'permits' => 'kind, label, id',
        'fuel_logs' => 'fueled_on DESC, odometer_km DESC, id DESC',
        'expenses' => 'spent_on DESC, id DESC',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Alle aktiven Datensätze eines Fahrzeugs mit allen Spalten samt Herkunft.
     *
     * @return list<array<string, mixed>>
     */
    public function listForVehicle(string $table, int $vehicleId, Actor $actor): array
    {
        $order = self::ORDER[$table] ?? throw new InvalidArgumentException(sprintf('Tabelle „%s“ ist nicht lesbar.', $table));
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT * FROM %s WHERE vehicle_id = ? AND status = 'aktiv' AND garage_id IN (%s) ORDER BY %s",
                $table,
                implode(', ', array_fill(0, count($garageIds), '?')),
                $order
            )
        );
        $statement->execute([$vehicleId, ...$garageIds]);

        return $statement->fetchAll();
    }

    /**
     * Ein aktiver Datensatz mit allen Spalten samt Herkunft; `$table` ist eine Tabelle des Datensatz-Abgleichs.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $table, int $id, Actor $actor): ?array
    {
        if (!isset(RecordMerger::TABLES[$table])) {
            throw new InvalidArgumentException(sprintf('Tabelle „%s“ ist nicht lesbar.', $table));
        }
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf("SELECT * FROM %s WHERE id = ? AND status = 'aktiv' AND garage_id IN (%s)", $table, implode(', ', array_fill(0, count($garageIds), '?')))
        );
        $statement->execute([$id, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Ein Datensatz in jedem Status (auch gelöscht), für Löschen und Wiederherstellen; mit `$lock` bis zum Ende der Transaktion gesperrt.
     *
     * @return array<string, mixed>|null
     */
    public function findAnyStatus(string $table, int $id, Actor $actor, bool $lock = false): ?array
    {
        self::assertMergeTable($table);
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf('SELECT * FROM %s WHERE id = ? AND garage_id IN (%s)%s', $table, self::placeholders($garageIds), $lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$id, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function setStatus(string $table, int $id, string $status, ?string $reason, Actor $actor): void
    {
        self::assertMergeTable($table);
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
     * Gibt es am selben Fahrzeug einen anderen aktiven Datensatz mit demselben Schlüssel? Verglichen wird mit `<=>` wie im Abgleich.
     *
     * @param list<string> $keyColumns Spalten aus `RecordMerger::TABLES`, nie aus der Eingabe
     */
    public function hasActiveDuplicate(string $table, int $id, array $keyColumns, Actor $actor): bool
    {
        self::assertMergeTable($table);
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return false;
        }

        $keyConditions = array_map(static fn (string $column): string => sprintf('other.%1$s <=> own.%1$s', $column), $keyColumns);
        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT 1 FROM %1\$s own JOIN %1\$s other ON other.vehicle_id = own.vehicle_id AND other.id <> own.id
                 WHERE own.id = ? AND other.status = 'aktiv' AND other.garage_id IN (%2\$s) AND %3\$s LIMIT 1",
                $table,
                self::placeholders($garageIds),
                implode(' AND ', $keyConditions)
            )
        );
        $statement->execute([$id, ...$garageIds]);

        return $statement->fetchColumn() !== false;
    }

    private static function assertMergeTable(string $table): void
    {
        if (!isset(RecordMerger::TABLES[$table])) {
            throw new InvalidArgumentException(sprintf('Tabelle „%s“ ist kein Datensatz des Abgleichs.', $table));
        }
    }

    /** @param list<int|string> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
