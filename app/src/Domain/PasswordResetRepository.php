<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Rücksetz-Links gehören einem Konto, nicht einer Garage; wer sie erzeugen und einlösen darf, prüft der Dienst. */
final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(string $tokenHash, int $userId, int $createdBy, int $hours): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO password_resets (token_hash, user_id, created_by, expires_at, created_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? HOUR, UTC_TIMESTAMP())'
        );
        $statement->execute([$tokenHash, $userId, $createdBy, $hours]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Mit `$lock` sperrt die Zeile bis zum Ende der Transaktion: ein Link lässt sich nicht zweimal einlösen.
     *
     * @return array{id: int, user_id: int, created_by: int, expires_at: string, is_expired: bool, is_used: bool}|null
     */
    public function findByHash(string $tokenHash, bool $lock): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, created_by, expires_at,
                    (expires_at <= UTC_TIMESTAMP()) AS is_expired, (used_at IS NOT NULL) AS is_used
             FROM password_resets WHERE token_hash = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$tokenHash]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'created_by' => (int) $row['created_by'],
            'expires_at' => (string) $row['expires_at'],
            'is_expired' => (int) $row['is_expired'] === 1,
            'is_used' => (int) $row['is_used'] === 1,
        ];
    }

    public function markUsed(int $resetId): void
    {
        $statement = $this->pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?');
        $statement->execute([$resetId]);
    }

    /** Macht alle noch offenen Links des Kontos ungültig. */
    public function invalidateOpen(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE password_resets SET used_at = UTC_TIMESTAMP()
             WHERE user_id = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $statement->execute([$userId]);
    }

    public function expiresAt(int $resetId): string
    {
        $statement = $this->pdo->prepare('SELECT expires_at FROM password_resets WHERE id = ?');
        $statement->execute([$resetId]);

        return (string) $statement->fetchColumn();
    }
}
