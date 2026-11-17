<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Lesen und Positionen schreiben für Ereignisse; das Ereignis selbst schreibt der `RecordMerger`. Hängt die Garagen des Akteurs selbst an. */
final class EventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Ereignisse eines Fahrzeugs, neueste zuerst. Filter laufen als gebundene Werte.
     *
     * @return list<array<string, mixed>>
     */
    public function listForVehicle(int $vehicleId, ?string $from, ?string $to, ?string $kind, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $conditions = ["e.vehicle_id = ?", "e.status = 'aktiv'", sprintf('e.garage_id IN (%s)', implode(', ', array_fill(0, count($garageIds), '?')))];
        $parameters = [$vehicleId, ...$garageIds];
        if ($from !== null) {
            $conditions[] = 'e.performed_on >= ?';
            $parameters[] = $from;
        }
        if ($to !== null) {
            $conditions[] = 'e.performed_on <= ?';
            $parameters[] = $to;
        }
        if ($kind !== null) {
            $conditions[] = 'e.kind = ?';
            $parameters[] = $kind;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT e.*, w.name AS workshop_name, w.city AS workshop_city,
                        (SELECT COUNT(*) FROM event_items i WHERE i.event_id = e.id AND i.status = 'aktiv') AS item_count
                 FROM events e LEFT JOIN workshops w ON w.id = e.workshop_id
                 WHERE %s ORDER BY e.performed_on DESC, e.id DESC",
                implode(' AND ', $conditions)
            )
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $eventId, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT e.*, w.name AS workshop_name, w.city AS workshop_city, w.manufacturer_partner AS workshop_partner
                 FROM events e LEFT JOIN workshops w ON w.id = e.workshop_id
                 WHERE e.id = ? AND e.status = 'aktiv' AND e.garage_id IN (%s)",
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$eventId, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** Gehört das aktive Ereignis zu diesem Fahrzeug (und einer Garage des Akteurs)? */
    public function belongsToVehicle(int $eventId, int $vehicleId, Actor $actor): bool
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return false;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT 1 FROM events WHERE id = ? AND vehicle_id = ? AND status = 'aktiv' AND garage_id IN (%s)",
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$eventId, $vehicleId, ...$garageIds]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Aktive Positionen in der Reihenfolge der Rechnung.
     *
     * @return list<array<string, mixed>>
     */
    public function activeItems(int $eventId, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT * FROM event_items WHERE event_id = ? AND status = 'aktiv' AND garage_id IN (%s) ORDER BY line_no",
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$eventId, ...$garageIds]);

        return $statement->fetchAll();
    }

    /** @param list<array<string, string|null>> $items validierte Positionen; `line_no` ergibt sich aus der Reihenfolge */
    public function insertItems(int $garageId, int $eventId, array $items): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO event_items (garage_id, event_id, line_no, item_type, description, task_key, part_number, manufacturer, quantity, unit, net_cents, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aktiv', UTC_TIMESTAMP())"
        );
        foreach ($items as $index => $item) {
            $statement->execute([
                $garageId,
                $eventId,
                $index + 1,
                $item['item_type'],
                $item['description'],
                $item['task_key'],
                $item['part_number'],
                $item['manufacturer'],
                $item['quantity'],
                $item['unit'],
                $item['net_cents'],
            ]);
        }
    }
}
