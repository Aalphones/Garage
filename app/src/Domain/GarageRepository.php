<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

final class GarageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(string $name): int
    {
        $statement = $this->pdo->prepare('INSERT INTO garages (name, created_at) VALUES (?, UTC_TIMESTAMP())');
        $statement->execute([$name]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addMember(int $garageId, int $userId, string $role): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO garage_members (garage_id, user_id, role, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
        );
        $statement->execute([$garageId, $userId, $role]);
    }

    /** @return array<int, string> garage_id => Rolle */
    public function rolesFor(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT garage_id, role FROM garage_members WHERE user_id = ?');
        $statement->execute([$userId]);

        $roles = [];
        foreach ($statement->fetchAll() as $row) {
            $roles[(int) $row['garage_id']] = (string) $row['role'];
        }

        return $roles;
    }

    /** @return list<array{id: int, name: string, role: string}> */
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT g.id, g.name, m.role FROM garage_members m
             JOIN garages g ON g.id = m.garage_id
             WHERE m.user_id = ? ORDER BY g.id'
        );
        $statement->execute([$userId]);

        $garages = [];
        foreach ($statement->fetchAll() as $row) {
            $garages[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'role' => (string) $row['role']];
        }

        return $garages;
    }

    public function exists(int $garageId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM garages WHERE id = ?');
        $statement->execute([$garageId]);

        return $statement->fetchColumn() !== false;
    }
}
