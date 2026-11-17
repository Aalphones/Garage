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
}
