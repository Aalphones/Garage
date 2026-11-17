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
}
