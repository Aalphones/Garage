<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Notizen und ihre Revisionen; hängt die Garagen des Akteurs selbst an (Regel 3 in AGENTS.md). */
final class NoteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Die Notiz zu einem Thema samt neuester Revision, gesperrt bis zum Ende der Transaktion; die Datenbank vergleicht
     * Themen ohne Rücksicht auf Groß- und Kleinschreibung, passend zum eindeutigen Schlüssel.
     *
     * @return array{id: int, revision: int, body: string}|null
     */
    public function findWithLatest(int $vehicleId, string $topic, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT n.id, r.revision, r.body
                 FROM notes n JOIN note_revisions r ON r.note_id = n.id
                 WHERE n.vehicle_id = ? AND n.topic = ? AND n.garage_id IN (%s)
                 ORDER BY r.revision DESC LIMIT 1 FOR UPDATE',
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$vehicleId, $topic, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : ['id' => (int) $row['id'], 'revision' => (int) $row['revision'], 'body' => (string) $row['body']];
    }

    public function insertNote(int $garageId, int $vehicleId, string $topic): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO notes (garage_id, vehicle_id, topic, status, created_at, updated_at) VALUES (?, ?, ?, 'aktiv', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute([$garageId, $vehicleId, $topic]);

        return (int) $this->pdo->lastInsertId();
    }

    public function insertRevision(int $garageId, int $noteId, int $revision, string $body, Actor $actor): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO note_revisions (garage_id, note_id, revision, body, actor, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $statement->execute([$garageId, $noteId, $revision, $body, $actor->name, $actor->userId]);
    }

    /**
     * Eine Notiz in jedem Status, für Löschen und Wiederherstellen; mit `$lock` bis zum Ende der Transaktion gesperrt.
     *
     * @return array{id: int, garage_id: int, vehicle_id: int, topic: string, status: string}|null
     */
    public function findAnyStatus(int $noteId, Actor $actor, bool $lock = false): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, garage_id, vehicle_id, topic, status FROM notes WHERE id = ? AND garage_id IN (%s)%s',
                implode(', ', array_fill(0, count($garageIds), '?')),
                $lock ? ' FOR UPDATE' : ''
            )
        );
        $statement->execute([$noteId, ...$garageIds]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'garage_id' => (int) $row['garage_id'], 'vehicle_id' => (int) $row['vehicle_id'], 'topic' => (string) $row['topic'], 'status' => (string) $row['status']];
    }

    /** `notes` hat keine Spalte für den Grund; er steht nur im Audit-Log. */
    public function setStatus(int $noteId, int $garageId, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE notes SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND garage_id = ?');
        $statement->execute([$status, $noteId, $garageId]);
    }

    public function touch(int $noteId, int $garageId): void
    {
        $statement = $this->pdo->prepare("UPDATE notes SET status = 'aktiv', updated_at = UTC_TIMESTAMP() WHERE id = ? AND garage_id = ?");
        $statement->execute([$noteId, $garageId]);
    }

    /**
     * Je Thema die neueste Revision, nach Thema sortiert.
     *
     * @return list<array{note_id: int, topic: string, revision: int, body: string, actor: string, updated_at: string}>
     */
    public function listCurrent(int $vehicleId, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($garageIds), '?'));
        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT n.id AS note_id, n.topic, r.revision, r.body, r.actor, r.created_at AS updated_at
                 FROM notes n JOIN note_revisions r ON r.note_id = n.id
                 WHERE n.vehicle_id = ? AND n.status = 'aktiv' AND n.garage_id IN (%s) AND r.garage_id IN (%s)
                   AND r.revision = (SELECT MAX(latest.revision) FROM note_revisions latest WHERE latest.note_id = n.id)
                 ORDER BY n.topic",
                $placeholders,
                $placeholders
            )
        );
        $statement->execute([$vehicleId, ...$garageIds, ...$garageIds]);

        $notes = [];
        foreach ($statement->fetchAll() as $row) {
            $notes[] = [
                'note_id' => (int) $row['note_id'],
                'topic' => (string) $row['topic'],
                'revision' => (int) $row['revision'],
                'body' => (string) $row['body'],
                'actor' => (string) $row['actor'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $notes;
    }
}
