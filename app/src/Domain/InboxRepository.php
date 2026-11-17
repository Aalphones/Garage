<?php

declare(strict_types=1);

namespace Garage\Domain;

use LogicException;
use PDO;

/**
 * Eingangs-Einträge und ihre Dokumente; hängt die Garagen des Akteurs selbst an (Regel 3 in AGENTS.md).
 * Die Frist wird nur hier in SQL gegen `UTC_TIMESTAMP()` verglichen und kommt als `is_claim_live` nach oben (E11, ADR-016).
 */
final class InboxRepository
{
    public const STATUS_NEW = 'neu';
    public const STATUS_WORKING = 'in_arbeit';
    public const STATUS_DONE = 'erfasst';
    public const STATUS_QUESTION = 'klaerfall';
    public const STATUS_DISCARDED = 'verworfen';

    private const LIST_LIMIT = 200;
    private const DISCARDED_VISIBLE_DAYS = 30;
    private const COLUMNS = 'i.id, i.garage_id, i.vehicle_id, i.note, i.status, i.claimed_by, i.claimed_user_id, i.claimed_until, i.result, i.open_item_id, i.created_at,
        (i.claimed_until IS NOT NULL AND i.claimed_until >= UTC_TIMESTAMP()) AS is_claim_live,
        (SELECT COALESCE(SUM(GREATEST(d.page_count, 1)), 0) FROM inbox_documents x JOIN documents d ON d.id = x.document_id WHERE x.inbox_item_id = i.id) AS page_count';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(int $garageId, ?int $vehicleId, string $note, Actor $actor): int
    {
        if (!$actor->canRead($garageId)) {
            throw new LogicException('Eingang in einer fremden Garage.');
        }
        $statement = $this->pdo->prepare(
            "INSERT INTO inbox_items (garage_id, vehicle_id, note, status, actor, user_id, created_at, updated_at)
             VALUES (?, ?, ?, 'neu', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute([$garageId, $vehicleId, $note, $actor->name, $actor->userId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<int> $documentIds in der Reihenfolge der Seiten */
    public function addDocuments(int $itemId, array $documentIds): void
    {
        $statement = $this->pdo->prepare('INSERT INTO inbox_documents (inbox_item_id, document_id, position) VALUES (?, ?, ?)');
        foreach ($documentIds as $index => $documentId) {
            $statement->execute([$itemId, $documentId, $index + 1]);
        }
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, Actor $actor, bool $lock = false): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }
        $statement = $this->pdo->prepare(
            sprintf('SELECT %s FROM inbox_items i WHERE i.id = ? AND i.garage_id IN (%s)%s', self::COLUMNS, self::placeholders($garageIds), $lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$id, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : self::row($row);
    }

    /**
     * Die Einträge der Garagen des Akteurs. `$status` ist der **wirksame** Status: `neu` schließt abgelaufene Reservierungen ein,
     * `in_arbeit` nur laufende; `null` und `alle` filtern nicht. `$newestFirst` für Menschen, sonst der älteste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor, ?string $status, ?int $garageId, bool $newestFirst): array
    {
        $garageIds = $garageId === null ? $actor->garageIds() : array_values(array_intersect($actor->garageIds(), [$garageId]));
        if ($garageIds === []) {
            return [];
        }

        $conditions = [sprintf('i.garage_id IN (%s)', self::placeholders($garageIds))];
        $parameters = $garageIds;
        if ($status === self::STATUS_NEW) {
            $conditions[] = "(i.status = 'neu' OR (i.status = 'in_arbeit' AND i.claimed_until < UTC_TIMESTAMP()))";
        } elseif ($status === self::STATUS_WORKING) {
            $conditions[] = "(i.status = 'in_arbeit' AND i.claimed_until >= UTC_TIMESTAMP())";
        } elseif ($status !== null && $status !== 'alle') {
            $conditions[] = 'i.status = ?';
            $parameters[] = $status;
        }
        if ($newestFirst) {
            $conditions[] = sprintf("(i.status <> 'verworfen' OR i.updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY))", self::DISCARDED_VISIBLE_DAYS);
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT %s FROM inbox_items i WHERE %s ORDER BY i.created_at %s, i.id %s LIMIT %d',
                self::COLUMNS,
                implode(' AND ', $conditions),
                $newestFirst ? 'DESC' : 'ASC',
                $newestFirst ? 'DESC' : 'ASC',
                self::LIST_LIMIT
            )
        );
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => self::row($row), $statement->fetchAll());
    }

    /**
     * ID des ältesten Eintrags, den der Akteur reservieren kann (`neu` oder Frist abgelaufen), und sperrt die Zeile.
     *
     * @param list<int> $garageIds Garagen mit Schreibrecht
     */
    public function oldestClaimable(array $garageIds): ?int
    {
        if ($garageIds === []) {
            return null;
        }
        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id FROM inbox_items
                 WHERE garage_id IN (%s) AND (status = 'neu' OR (status = 'in_arbeit' AND claimed_until < UTC_TIMESTAMP()))
                 ORDER BY created_at, id LIMIT 1 FOR UPDATE",
                self::placeholders($garageIds)
            )
        );
        $statement->execute($garageIds);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return list<int> Dokument-IDs in Seitenreihenfolge */
    public function documentIds(int $itemId): array
    {
        $statement = $this->pdo->prepare('SELECT document_id FROM inbox_documents WHERE inbox_item_id = ? ORDER BY position');
        $statement->execute([$itemId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Eintrag reservieren oder die Reservierung verlängern; die Frist rechnet MySQL aus der eigenen Uhr. */
    public function claim(int $id, string $by, int $userId, int $minutes): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE inbox_items SET status = 'in_arbeit', claimed_by = ?, claimed_user_id = ?, claimed_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), updated_at = UTC_TIMESTAMP() WHERE id = ?"
        );
        $statement->execute([$by, $userId, $minutes, $id]);
    }

    /** Beendet die Reservierung: Status, Ergebnis und optional der angelegte Klärfall. */
    public function finish(int $id, string $status, ?string $result, ?int $openItemId): void
    {
        $keepsClaim = $status !== self::STATUS_NEW;
        $statement = $this->pdo->prepare(
            sprintf(
                'UPDATE inbox_items SET status = ?, result = ?, open_item_id = ?, claimed_by = %s, claimed_user_id = %s, claimed_until = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                $keepsClaim ? 'claimed_by' : 'NULL',
                $keepsClaim ? 'claimed_user_id' : 'NULL'
            )
        );
        $statement->execute([$status, $result, $openItemId, $id]);
    }

    /** Aus der Oberfläche: verwerfen oder zurück nach `neu`. */
    public function setStatus(int $id, string $status): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE inbox_items SET status = ?, claimed_by = NULL, claimed_user_id = NULL, claimed_until = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?"
        );
        $statement->execute([$status, $id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'garage_id' => (int) $row['garage_id'],
            'vehicle_id' => $row['vehicle_id'] === null ? null : (int) $row['vehicle_id'],
            'note' => (string) $row['note'],
            'status' => (string) $row['status'],
            'claimed_by' => $row['claimed_by'] === null ? null : (string) $row['claimed_by'],
            'claimed_user_id' => $row['claimed_user_id'] === null ? null : (int) $row['claimed_user_id'],
            'claimed_until' => $row['claimed_until'] === null ? null : (string) $row['claimed_until'],
            'is_claim_live' => (bool) $row['is_claim_live'],
            'result' => $row['result'] === null ? null : (string) $row['result'],
            'open_item_id' => $row['open_item_id'] === null ? null : (int) $row['open_item_id'],
            'page_count' => (int) $row['page_count'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @param list<int> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
