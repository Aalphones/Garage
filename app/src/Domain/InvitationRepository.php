<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

final class InvitationRepository
{
    private const OPEN_CONDITION = 'i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > UTC_TIMESTAMP()';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(string $tokenHash, ?int $garageId, ?string $garageName, string $role, ?string $email, int $days, ?int $createdBy): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO invitations (token_hash, garage_id, garage_name, role, email, created_by, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY, UTC_TIMESTAMP())'
        );
        $statement->execute([$tokenHash, $garageId, $garageName, $role, $email, $createdBy, $days]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Mit `$lock` sperrt die Zeile bis zum Ende der Transaktion: zwei gleichzeitige Annahmen können nicht beide durch.
     *
     * @return array{id: int, garage_id: int|null, garage_name: string|null, role: string, expires_at: string, is_expired: bool, is_accepted: bool, is_revoked: bool, resolved_garage_name: string|null}|null
     */
    public function findByHash(string $tokenHash, bool $lock): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id, i.garage_id, i.garage_name, i.role, i.expires_at,
                    (i.expires_at <= UTC_TIMESTAMP()) AS is_expired, (i.accepted_at IS NOT NULL) AS is_accepted,
                    (i.revoked_at IS NOT NULL) AS is_revoked,
                    g.name AS resolved_garage_name
             FROM invitations i LEFT JOIN garages g ON g.id = i.garage_id
             WHERE i.token_hash = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$tokenHash]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'garage_id' => $row['garage_id'] === null ? null : (int) $row['garage_id'],
            'garage_name' => $row['garage_name'] === null ? null : (string) $row['garage_name'],
            'role' => (string) $row['role'],
            'expires_at' => (string) $row['expires_at'],
            'is_expired' => (int) $row['is_expired'] === 1,
            'is_accepted' => (int) $row['is_accepted'] === 1,
            'is_revoked' => (int) $row['is_revoked'] === 1,
            'resolved_garage_name' => $row['resolved_garage_name'] === null ? null : (string) $row['resolved_garage_name'],
        ];
    }

    /**
     * Offen heißt: nicht angenommen, nicht widerrufen, nicht abgelaufen.
     *
     * @return list<array{id: int, role: string, email: string|null, expires_at: string, created_at: string, created_by_name: string|null}>
     */
    public function listOpen(int $garageId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id, i.role, i.email, i.expires_at, i.created_at, u.name AS created_by_name
             FROM invitations i LEFT JOIN users u ON u.id = i.created_by
             WHERE i.garage_id = ? AND ' . self::OPEN_CONDITION . ' ORDER BY i.id DESC'
        );
        $statement->execute([$garageId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'role' => (string) $row['role'],
            'email' => $row['email'] === null ? null : (string) $row['email'],
            'expires_at' => (string) $row['expires_at'],
            'created_at' => (string) $row['created_at'],
            'created_by_name' => $row['created_by_name'] === null ? null : (string) $row['created_by_name'],
        ], $statement->fetchAll());
    }

    /** @return list<int> */
    public function openIdsCreatedBy(int $garageId, int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id FROM invitations i WHERE i.garage_id = ? AND i.created_by = ? AND ' . self::OPEN_CONDITION . ' FOR UPDATE'
        );
        $statement->execute([$garageId, $userId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** False, wenn es die Einladung in dieser Garage nicht gibt oder sie schon angenommen oder widerrufen ist. */
    public function revoke(int $invitationId, int $garageId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE invitations SET revoked_at = UTC_TIMESTAMP()
             WHERE id = ? AND garage_id = ? AND accepted_at IS NULL AND revoked_at IS NULL'
        );
        $statement->execute([$invitationId, $garageId]);

        return $statement->rowCount() === 1;
    }

    public function markAccepted(int $invitationId, int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE invitations SET accepted_at = UTC_TIMESTAMP(), accepted_by = ? WHERE id = ?');
        $statement->execute([$userId, $invitationId]);
    }

    public function expiresAt(int $invitationId): string
    {
        $statement = $this->pdo->prepare('SELECT expires_at FROM invitations WHERE id = ?');
        $statement->execute([$invitationId]);

        return (string) $statement->fetchColumn();
    }
}
