<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/**
 * Belege, Anhänge und Upload-Tickets; hängt die Garagen des Akteurs selbst an (Regel 3 in AGENTS.md).
 * Ausnahmen mit Kommentar: `findById` und `findTicketByHash` — dort ist der signierte Link bzw. der Ticket-Slug die Berechtigung.
 */
final class DocumentRepository
{
    public const STATUS_WAITING = 'wartet';
    public const STATUS_UNASSIGNED = 'unzugeordnet';
    public const STATUS_ASSIGNED = 'zugeordnet';
    public const STATUS_DISCARDED = 'verworfen';

    private const COLUMNS = 'id, garage_id, vehicle_id, uid, doc_type, photo_tag, title, mime, bytes, sha256, width, height, page_count, captured_on, text_content, status, status_reason, channel, actor, created_at, updated_at';
    private const WRITABLE = [
        'vehicle_id', 'doc_type', 'photo_tag', 'title', 'mime', 'bytes', 'sha256', 'width', 'height', 'page_count',
        'captured_on', 'text_content', 'status', 'status_reason', 'channel', 'actor', 'user_id',
    ];
    private const LIST_LIMIT = 500;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $row Spalten aus WRITABLE plus `garage_id` und `uid` */
    public function insert(array $row): int
    {
        $columns = ['garage_id', 'uid', ...array_keys(array_intersect_key($row, array_flip(self::WRITABLE)))];
        $statement = $this->pdo->prepare(
            sprintf(
                'INSERT INTO documents (%s, created_at, updated_at) VALUES (%s, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?'))
            )
        );
        $statement->execute(array_map(static fn (string $column): mixed => $row[$column], $columns));

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, Actor $actor, bool $lock = false): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT %s FROM documents WHERE id = ? AND garage_id IN (%s)%s',
                self::COLUMNS,
                self::placeholders($garageIds),
                $lock ? ' FOR UPDATE' : ''
            )
        );
        $statement->execute([$id, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : self::document($row);
    }

    /**
     * Ohne Akteur: nur für die Auslieferung über einen signierten Link, der selbst die Berechtigung ist (E3).
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(sprintf('SELECT %s FROM documents WHERE id = ?', self::COLUMNS));
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : self::document($row);
    }

    /** Die Garage ist durch Ticket bzw. Akteur schon geprüft; Dubletten gibt es nur je Garage. @return array<string, mixed>|null */
    public function findBySha(int $garageId, string $sha256, bool $lock = false): ?array
    {
        $statement = $this->pdo->prepare(
            sprintf('SELECT %s FROM documents WHERE garage_id = ? AND sha256 = ?%s', self::COLUMNS, $lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$garageId, $sha256]);
        $row = $statement->fetch();

        return $row === false ? null : self::document($row);
    }

    /** @param array<string, mixed> $fields Spalten aus WRITABLE */
    public function update(int $id, int $garageId, array $fields): void
    {
        $columns = array_keys(array_intersect_key($fields, array_flip(self::WRITABLE)));
        if ($columns === []) {
            return;
        }

        $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', $columns));
        $statement = $this->pdo->prepare(sprintf('UPDATE documents SET %s, updated_at = UTC_TIMESTAMP() WHERE id = ? AND garage_id = ?', $assignments));
        $statement->execute([...array_map(static fn (string $column): mixed => $fields[$column], $columns), $id, $garageId]);
    }

    /**
     * @param string|null $status `offen` = wartet und unzugeordnet, `alle` = alles außer verworfen, sonst genau ein Status; `null` wie `alle`
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor, ?int $vehicleId, ?string $status, ?string $docType): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $conditions = [sprintf('garage_id IN (%s)', self::placeholders($garageIds))];
        $parameters = $garageIds;
        if ($vehicleId !== null) {
            $conditions[] = 'vehicle_id = ?';
            $parameters[] = $vehicleId;
        }
        if ($docType !== null) {
            $conditions[] = 'doc_type = ?';
            $parameters[] = $docType;
        }
        if ($status === 'offen') {
            $conditions[] = 'status IN (?, ?)';
            array_push($parameters, self::STATUS_WAITING, self::STATUS_UNASSIGNED);
        } elseif ($status === null || $status === 'alle') {
            $conditions[] = 'status <> ?';
            $parameters[] = self::STATUS_DISCARDED;
        } else {
            $conditions[] = 'status = ?';
            $parameters[] = $status;
        }

        $statement = $this->pdo->prepare(
            sprintf('SELECT %s FROM documents WHERE %s ORDER BY id DESC LIMIT %d', self::COLUMNS, implode(' AND ', $conditions), self::LIST_LIMIT)
        );
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => self::document($row), $statement->fetchAll());
    }

    /**
     * @param array{user_id: int, garage_id: int, vehicle_id: int|null, document_id: int|null, max_bytes: int, ttl_seconds: int} $ticket
     * @return array{id: int, expires_at: string}
     */
    public function insertTicket(string $ticketHash, array $ticket): array
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO upload_tickets (ticket_hash, user_id, garage_id, vehicle_id, document_id, max_bytes, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP())'
        );
        $statement->execute([$ticketHash, $ticket['user_id'], $ticket['garage_id'], $ticket['vehicle_id'], $ticket['document_id'], $ticket['max_bytes'], $ticket['ttl_seconds']]);
        $id = (int) $this->pdo->lastInsertId();

        $read = $this->pdo->prepare('SELECT expires_at FROM upload_tickets WHERE id = ?');
        $read->execute([$id]);

        return ['id' => $id, 'expires_at' => (string) $read->fetchColumn()];
    }

    /**
     * Bewusst ohne Akteur: der Slug im Pfad ist die Berechtigung (E4), der Aufrufer baut den Akteur aus dem Ticket.
     * Ablauf wird in der Abfrage verglichen, nie in PHP.
     *
     * @return array{id: int, user_id: int, garage_id: int, vehicle_id: int|null, document_id: int|null, max_bytes: int, is_expired: bool, is_redeemed: bool}|null
     */
    public function findTicketByHash(string $ticketHash, bool $lock): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, garage_id, vehicle_id, document_id, max_bytes, expires_at <= UTC_TIMESTAMP() AS is_expired, redeemed_at IS NOT NULL AS is_redeemed
             FROM upload_tickets WHERE ticket_hash = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$ticketHash]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'garage_id' => (int) $row['garage_id'],
            'vehicle_id' => $row['vehicle_id'] === null ? null : (int) $row['vehicle_id'],
            'document_id' => $row['document_id'] === null ? null : (int) $row['document_id'],
            'max_bytes' => (int) $row['max_bytes'],
            'is_expired' => (bool) $row['is_expired'],
            'is_redeemed' => (bool) $row['is_redeemed'],
        ];
    }

    /** Ein Ticket gehört dem Nutzer, der es angefordert hat (store_document mit ticket_id). @return array{id: int, garage_id: int, vehicle_id: int|null, document_id: int|null, is_redeemed: bool, is_duplicate: bool}|null */
    public function findTicketForUser(int $ticketId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, garage_id, vehicle_id, document_id, redeemed_at IS NOT NULL AS is_redeemed, is_duplicate FROM upload_tickets WHERE id = ? AND user_id = ?'
        );
        $statement->execute([$ticketId, $userId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'garage_id' => (int) $row['garage_id'],
            'vehicle_id' => $row['vehicle_id'] === null ? null : (int) $row['vehicle_id'],
            'document_id' => $row['document_id'] === null ? null : (int) $row['document_id'],
            'is_redeemed' => (bool) $row['is_redeemed'],
            'is_duplicate' => (bool) $row['is_duplicate'],
        ];
    }

    public function redeemTicket(int $ticketId, int $documentId, bool $isDuplicate): void
    {
        $statement = $this->pdo->prepare('UPDATE upload_tickets SET redeemed_at = UTC_TIMESTAMP(), document_id = ?, is_duplicate = ? WHERE id = ?');
        $statement->execute([$documentId, $isDuplicate ? 1 : 0, $ticketId]);
    }

    /** @return list<array{id: int, target_table: string, target_id: int, role: string}> */
    public function attachmentsFor(int $documentId, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, target_table, target_id, role FROM attachments WHERE document_id = ? AND garage_id IN (%s) ORDER BY id',
                self::placeholders($garageIds)
            )
        );
        $statement->execute([$documentId, ...$garageIds]);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'target_table' => (string) $row['target_table'], 'target_id' => (int) $row['target_id'], 'role' => (string) $row['role']],
            $statement->fetchAll()
        );
    }

    /**
     * Zeile eines Anhangsziels samt Garage und Fahrzeug; `$table` stammt aus `DocumentService::TARGETS` (nie aus Eingaben).
     * `vehicle_id` ist `null` bei einem Garagen-Vertrag und seinen Vorgängen; Vorgänge erben das Fahrzeug ihres Vertrags.
     *
     * @return array{id: int, garage_id: int, vehicle_id: int|null}|null
     */
    public function findTarget(string $table, int $id, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $sql = match ($table) {
            'vehicles' => 'SELECT id, garage_id, id AS vehicle_id FROM vehicles WHERE id = ? AND garage_id IN (%s)',
            'contract_events' => 'SELECT e.id, e.garage_id, c.vehicle_id FROM contract_events e JOIN contracts c ON c.id = e.contract_id WHERE e.id = ? AND e.garage_id IN (%s)',
            default => 'SELECT id, garage_id, vehicle_id FROM ' . $table . ' WHERE id = ? AND garage_id IN (%s)',
        };
        $statement = $this->pdo->prepare(sprintf($sql, self::placeholders($garageIds)));
        $statement->execute([$id, ...$garageIds]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'garage_id' => (int) $row['garage_id'], 'vehicle_id' => $row['vehicle_id'] === null ? null : (int) $row['vehicle_id']];
    }

    /** @return bool `true`, wenn der Anhang neu entstand; `false`, wenn es ihn schon gab */
    public function insertAttachment(int $garageId, int $documentId, string $table, int $targetId, string $role, Actor $actor): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO attachments (garage_id, document_id, target_table, target_id, role, actor, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $statement->execute([$garageId, $documentId, $table, $targetId, $role, $actor->name, $actor->userId]);

        return $statement->rowCount() === 1;
    }

    public function findAttachmentId(int $documentId, string $table, int $targetId, string $role): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM attachments WHERE document_id = ? AND target_table = ? AND target_id = ? AND role = ?');
        $statement->execute([$documentId, $table, $targetId, $role]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Ein neues Titelbild löst das alte ab: dessen Anhang wird Rolle `foto` (gibt es den schon, entfällt die doppelte Zeile).
     */
    public function demoteCover(int $garageId, int $vehicleId, int $exceptDocumentId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT id, document_id FROM attachments WHERE garage_id = ? AND target_table = 'vehicles' AND target_id = ? AND role = 'titelbild' AND document_id <> ? FOR UPDATE"
        );
        $statement->execute([$garageId, $vehicleId, $exceptDocumentId]);
        foreach ($statement->fetchAll() as $row) {
            $demote = $this->pdo->prepare("UPDATE IGNORE attachments SET role = 'foto' WHERE id = ?");
            $demote->execute([(int) $row['id']]);
            if ($demote->rowCount() === 0) {
                $drop = $this->pdo->prepare('DELETE FROM attachments WHERE id = ?');
                $drop->execute([(int) $row['id']]);
            }
        }
    }

    /** Setzt `source_document_id` nur, wenn die Spalte noch leer ist. @return bool `true`, wenn der Wert gesetzt wurde */
    public function setSourceDocument(string $table, int $targetId, int $garageId, int $documentId): bool
    {
        $statement = $this->pdo->prepare(sprintf('UPDATE %s SET source_document_id = ? WHERE id = ? AND garage_id = ? AND source_document_id IS NULL', $table));
        $statement->execute([$documentId, $targetId, $garageId]);

        return $statement->rowCount() === 1;
    }

    /**
     * Belege an einer Zeile, für `get_event` und Co.
     *
     * @return list<array{id: int, doc_type: string, title: string, role: string, mime: string|null, page_count: int}>
     */
    public function documentsFor(string $table, int $targetId, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT d.id, d.doc_type, d.title, a.role, d.mime, d.page_count
                 FROM attachments a JOIN documents d ON d.id = a.document_id
                 WHERE a.target_table = ? AND a.target_id = ? AND a.garage_id IN (%s) AND d.garage_id = a.garage_id AND d.status <> ?
                 ORDER BY a.id',
                self::placeholders($garageIds)
            )
        );
        $statement->execute([$table, $targetId, ...$garageIds, self::STATUS_DISCARDED]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'doc_type' => (string) $row['doc_type'],
            'title' => (string) $row['title'],
            'role' => (string) $row['role'],
            'mime' => $row['mime'] === null ? null : (string) $row['mime'],
            'page_count' => (int) $row['page_count'],
        ], $statement->fetchAll());
    }

    /** @return array{id: int, mime: string|null, page_count: int}|null das Titelbild des Fahrzeugs */
    public function coverFor(int $vehicleId, Actor $actor): ?array
    {
        foreach ($this->documentsFor('vehicles', $vehicleId, $actor) as $document) {
            if ($document['role'] === 'titelbild') {
                return ['id' => $document['id'], 'mime' => $document['mime'], 'page_count' => $document['page_count']];
            }
        }

        return null;
    }

    /** @return array{count: int, open: int} Belege des Fahrzeugs ohne verworfene; `open` = wartet oder unzugeordnet */
    public function countsFor(int $vehicleId, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return ['count' => 0, 'open' => 0];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT COUNT(*) AS total, COALESCE(SUM(status IN (?, ?)), 0) AS open_count FROM documents WHERE vehicle_id = ? AND garage_id IN (%s) AND status <> ?',
                self::placeholders($garageIds)
            )
        );
        $statement->execute([self::STATUS_WAITING, self::STATUS_UNASSIGNED, $vehicleId, ...$garageIds, self::STATUS_DISCARDED]);
        $row = $statement->fetch();

        return ['count' => (int) $row['total'], 'open' => (int) $row['open_count']];
    }

    /**
     * Anhänge mehrerer Dokumente in einer Abfrage, für die Liste.
     *
     * @param list<int> $documentIds
     * @return array<int, list<array{target_table: string, target_id: int, role: string}>> je Dokument-ID
     */
    public function attachmentsForMany(array $documentIds, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($documentIds === [] || $garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT document_id, target_table, target_id, role FROM attachments WHERE document_id IN (%s) AND garage_id IN (%s) ORDER BY id',
                self::placeholders($documentIds),
                self::placeholders($garageIds)
            )
        );
        $statement->execute([...$documentIds, ...$garageIds]);

        $byDocument = [];
        foreach ($statement->fetchAll() as $row) {
            $byDocument[(int) $row['document_id']][] = ['target_table' => (string) $row['target_table'], 'target_id' => (int) $row['target_id'], 'role' => (string) $row['role']];
        }

        return $byDocument;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function document(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'garage_id' => (int) $row['garage_id'],
            'vehicle_id' => $row['vehicle_id'] === null ? null : (int) $row['vehicle_id'],
            'uid' => (string) $row['uid'],
            'doc_type' => (string) $row['doc_type'],
            'photo_tag' => $row['photo_tag'],
            'title' => (string) $row['title'],
            'mime' => $row['mime'],
            'bytes' => $row['bytes'] === null ? null : (int) $row['bytes'],
            'sha256' => $row['sha256'],
            'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
            'page_count' => (int) $row['page_count'],
            'captured_on' => $row['captured_on'],
            'text_content' => $row['text_content'],
            'status' => (string) $row['status'],
            'status_reason' => $row['status_reason'],
            'channel' => (string) $row['channel'],
            'actor' => (string) $row['actor'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param list<int> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
