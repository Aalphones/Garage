<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

final class UserRepository
{
    public const STATUS_ACTIVE = 'aktiv';
    public const STATUS_BLOCKED = 'gesperrt';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id: int, email: string, name: string, password_hash: string, status: string}|null */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, email, name, password_hash, status FROM users WHERE email = ?');
        $statement->execute([$email]);

        return $this->hydrate($statement->fetch());
    }

    /** @return array{id: int, email: string, name: string, password_hash: string, status: string}|null */
    public function findById(int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, email, name, password_hash, status FROM users WHERE id = ?');
        $statement->execute([$userId]);

        return $this->hydrate($statement->fetch());
    }

    /**
     * Sperrt die Zeile bis zum Ende der Transaktion.
     *
     * @return array{id: int, email: string, name: string, password_hash: string, status: string}|null
     */
    public function findByIdForUpdate(int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, email, name, password_hash, status FROM users WHERE id = ? FOR UPDATE');
        $statement->execute([$userId]);

        return $this->hydrate($statement->fetch());
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $statement->execute([$passwordHash, $userId]);
    }

    public function insert(string $email, string $name, string $passwordHash): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, name, password_hash, status, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $statement->execute([$email, $name, $passwordHash, self::STATUS_ACTIVE]);

        return (int) $this->pdo->lastInsertId();
    }

    public function touchLogin(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?');
        $statement->execute([$userId]);
    }

    /**
     * @param array<string, mixed>|false $row
     * @return array{id: int, email: string, name: string, password_hash: string, status: string}|null
     */
    private function hydrate(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
            'password_hash' => (string) $row['password_hash'],
            'status' => (string) $row['status'],
        ];
    }
}
