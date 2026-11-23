<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Kilometerstände; hängt die Garagen des Akteurs selbst an (Regel 3 in AGENTS.md). */
final class OdometerRepository
{
    public const STATUS_VALID = 'gueltig';
    public const STATUS_DISPUTED = 'strittig';
    public const STATUS_DISCARDED = 'verworfen';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Stand mit gleichem Datum und gleichem Wert (gültig oder strittig), gesperrt für die Transaktion.
     *
     * @return array{id: int, reading_status: string, confidence: string, event_id: int|null}|null
     */
    public function findSame(int $vehicleId, string $readOn, string $km, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id, reading_status, confidence, event_id FROM odometer_readings
                 WHERE vehicle_id = ? AND status = 'aktiv' AND reading_status IN ('gueltig', 'strittig') AND read_on = ? AND km = ? AND garage_id IN (%s)
                 ORDER BY id LIMIT 1 FOR UPDATE",
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$vehicleId, $readOn, $km, ...$garageIds]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'reading_status' => (string) $row['reading_status'], 'confidence' => (string) $row['confidence'], 'event_id' => $row['event_id'] === null ? null : (int) $row['event_id']];
    }

    /**
     * Gültige Stände, die zum neuen nicht passen: früher oder gleich datiert und größer, später datiert und kleiner.
     * Verglichen wird in der Abfrage.
     *
     * @return list<array{id: int, read_on: string, km: int}> nach Datum, Wert
     */
    public function findConflicts(int $vehicleId, string $readOn, string $km, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id, read_on, km FROM odometer_readings
                 WHERE vehicle_id = ? AND status = 'aktiv' AND reading_status = 'gueltig' AND garage_id IN (%s)
                   AND ((read_on <= ? AND km > ?) OR (read_on > ? AND km < ?))
                 ORDER BY read_on, km, id",
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$vehicleId, ...$garageIds, $readOn, $km, $readOn, $km]);

        return self::readings($statement->fetchAll());
    }

    /**
     * Alle gültigen Stände eines Fahrzeugs, älteste zuerst (Grundlage der Kilometerprognose).
     *
     * @return list<array{id: int, read_on: string, km: int}>
     */
    public function listValid(int $vehicleId, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id, read_on, km FROM odometer_readings
                 WHERE vehicle_id = ? AND status = 'aktiv' AND reading_status = 'gueltig' AND garage_id IN (%s)
                 ORDER BY read_on, km, id",
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$vehicleId, ...$garageIds]);

        return self::readings($statement->fetchAll());
    }

    /**
     * Alle gültigen Stände mit Notiz und Herkunft, älteste zuerst (Anzeige in der Oberfläche).
     *
     * @return list<array{id: int, read_on: string, km: int, note: string|null, source: string, confidence: string}>
     */
    public function listValidDetailed(int $vehicleId, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id, read_on, km, note, source, confidence FROM odometer_readings
                 WHERE vehicle_id = ? AND status = 'aktiv' AND reading_status = 'gueltig' AND garage_id IN (%s)
                 ORDER BY read_on, km, id",
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$vehicleId, ...$garageIds]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'read_on' => (string) $row['read_on'],
            'km' => (int) $row['km'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'source' => (string) $row['source'],
            'confidence' => (string) $row['confidence'],
        ], $statement->fetchAll());
    }

    /**
     * Jüngster gültiger Stand mit Herkunft (Akte); bei gleichem Datum gewinnt der höhere.
     *
     * @return array{read_on: string, km: int, source: string, source_url: string|null, confidence: string}|null
     */
    public function latestValid(int $vehicleId, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT read_on, km, source, source_url, confidence FROM odometer_readings
                 WHERE vehicle_id = ? AND status = 'aktiv' AND reading_status = 'gueltig' AND garage_id IN (%s)
                 ORDER BY read_on DESC, km DESC, id DESC LIMIT 1",
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$vehicleId, ...$garageIds]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'read_on' => (string) $row['read_on'],
            'km' => (int) $row['km'],
            'source' => (string) $row['source'],
            'source_url' => $row['source_url'] === null ? null : (string) $row['source_url'],
            'confidence' => (string) $row['confidence'],
        ];
    }

    /** @return array{id: int, read_on: string, km: int, reading_status: string, note: string|null}|null der Stand zu einem Ereignis */
    public function findForEvent(int $eventId, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id, read_on, km, reading_status, note FROM odometer_readings
                 WHERE event_id = ? AND status = 'aktiv' AND reading_status <> 'verworfen' AND garage_id IN (%s)
                 ORDER BY id LIMIT 1",
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$eventId, ...$garageIds]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'read_on' => (string) $row['read_on'],
            'km' => (int) $row['km'],
            'reading_status' => (string) $row['reading_status'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
        ];
    }

    /** @param array{source: string, source_url: string|null, confidence: string} $provenance */
    public function insert(int $garageId, int $vehicleId, string $readOn, string $km, ?int $eventId, ?string $note, string $readingStatus, array $provenance, Actor $actor): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO odometer_readings (garage_id, vehicle_id, read_on, km, event_id, note, reading_status, source, source_url, confidence, actor, user_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            $garageId,
            $vehicleId,
            $readOn,
            $km,
            $eventId,
            $note,
            $readingStatus,
            $provenance['source'],
            $provenance['source_url'],
            $provenance['confidence'],
            $actor->name,
            $actor->userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{source: string, source_url: string|null, confidence: string} $provenance */
    public function raiseProvenance(int $id, int $garageId, array $provenance): void
    {
        $statement = $this->pdo->prepare('UPDATE odometer_readings SET source = ?, source_url = ?, confidence = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND garage_id = ?');
        $statement->execute([$provenance['source'], $provenance['source_url'], $provenance['confidence'], $id, $garageId]);
    }

    /** Verknüpft einen schon vorhandenen Stand mit dem Ereignis, das ihn erneut meldet; ein bestehender Bezug bleibt. */
    public function linkEvent(int $id, int $garageId, int $eventId): void
    {
        $statement = $this->pdo->prepare('UPDATE odometer_readings SET event_id = ? WHERE id = ? AND garage_id = ? AND event_id IS NULL');
        $statement->execute([$eventId, $id, $garageId]);
    }

    /**
     * Stände mit Herkunft, nach Datum und Wert; unabhängig vom Status (auch strittige und verworfene).
     *
     * @param list<int> $ids
     * @return list<array{id: int, read_on: string, km: int, reading_status: string, source: string, source_url: string|null, confidence: string, actor: string}>
     */
    public function findByIds(array $ids, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === [] || $ids === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id, read_on, km, reading_status, source, source_url, confidence, actor FROM odometer_readings
                 WHERE id IN (%s) AND status = 'aktiv' AND garage_id IN (%s) ORDER BY read_on, km, id",
                implode(', ', array_fill(0, count($ids), '?')),
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([...$ids, ...$garageIds]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'read_on' => (string) $row['read_on'],
            'km' => (int) $row['km'],
            'reading_status' => (string) $row['reading_status'],
            'source' => (string) $row['source'],
            'source_url' => $row['source_url'] === null ? null : (string) $row['source_url'],
            'confidence' => (string) $row['confidence'],
            'actor' => (string) $row['actor'],
        ], $statement->fetchAll());
    }

    public function setStatus(int $id, string $readingStatus, Actor $actor): void
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "UPDATE odometer_readings SET reading_status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'aktiv' AND garage_id IN (%s)",
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$readingStatus, $id, ...$garageIds]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, read_on: string, km: int}>
     */
    private static function readings(array $rows): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'read_on' => (string) $row['read_on'], 'km' => (int) $row['km']],
            $rows
        );
    }
}
