<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

final class InvitationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(string $tokenHash, ?int $garageId, ?string $garageName, string $role, ?string $email, int $days): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO invitations (token_hash, garage_id, garage_name, role, email, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY, UTC_TIMESTAMP())'
        );
        $statement->execute([$tokenHash, $garageId, $garageName, $role, $email, $days]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Mit `$lock` sperrt die Zeile bis zum Ende der Transaktion: zwei gleichzeitige Annahmen können nicht beide durch.
     *
     * @return array{id: int, garage_id: int|null, garage_name: string|null, role: string, expires_at: string, is_expired: bool, is_accepted: bool, resolved_garage_name: string|null}|null
     */
    public function findByHash(string $tokenHash, bool $lock): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id, i.garage_id, i.garage_name, i.role, i.expires_at,
                    (i.expires_at <= UTC_TIMESTAMP()) AS is_expired, (i.accepted_at IS NOT NULL) AS is_accepted,
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
            'resolved_garage_name' => $row['resolved_garage_name'] === null ? null : (string) $row['resolved_garage_name'],
        ];
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
