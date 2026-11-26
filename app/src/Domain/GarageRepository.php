<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

final class GarageRepository
{
    private const MEMBER_QUERY = 'SELECT m.user_id, u.name, u.email, m.role, m.created_at FROM garage_members m JOIN users u ON u.id = m.user_id';

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

    /** @return list<array{user_id: int, name: string, email: string, role: string, since: string}> */
    public function members(int $garageId): array
    {
        $statement = $this->pdo->prepare(self::MEMBER_QUERY . ' WHERE m.garage_id = ? ORDER BY m.created_at, m.user_id');
        $statement->execute([$garageId]);

        return array_map(self::hydrateMember(...), $statement->fetchAll());
    }

    /** @return array{user_id: int, name: string, email: string, role: string, since: string}|null */
    public function member(int $garageId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(self::MEMBER_QUERY . ' WHERE m.garage_id = ? AND m.user_id = ?');
        $statement->execute([$garageId, $userId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return self::hydrateMember($row);
    }

    /**
     * Nur in einer Transaktion sinnvoll: die Sperre hält bis zu deren Ende.
     *
     * @return list<int>
     */
    public function ownerIdsForUpdate(int $garageId): array
    {
        $statement = $this->pdo->prepare('SELECT user_id FROM garage_members WHERE garage_id = ? AND role = ? FOR UPDATE');
        $statement->execute([$garageId, Actor::ROLE_OWNER]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function changeRole(int $garageId, int $userId, string $role): void
    {
        $statement = $this->pdo->prepare('UPDATE garage_members SET role = ? WHERE garage_id = ? AND user_id = ?');
        $statement->execute([$role, $garageId, $userId]);
    }

    public function removeMember(int $garageId, int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM garage_members WHERE garage_id = ? AND user_id = ?');
        $statement->execute([$garageId, $userId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{user_id: int, name: string, email: string, role: string, since: string}
     */
    private static function hydrateMember(array $row): array
    {
        return [
            'user_id' => (int) $row['user_id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'since' => (string) $row['created_at'],
        ];
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

    /** @return array{id: int, name: string, calendar_version: int}|null */
    public function find(int $garageId, Actor $actor): ?array
    {
        if (!$actor->canRead($garageId)) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT id, name, calendar_version FROM garages WHERE id = ?');
        $statement->execute([$garageId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'calendar_version' => (int) $row['calendar_version']];
    }

    /**
     * Version des Kalender-Abos einer Garage; ohne Akteur, weil der Abruf des Abos keinen Nutzer kennt — die Version ist
     * kein Fakt, sie prüft nur die Signatur des Links.
     */
    public function calendarVersion(int $garageId): ?int
    {
        $statement = $this->pdo->prepare('SELECT calendar_version FROM garages WHERE id = ?');
        $statement->execute([$garageId]);
        $version = $statement->fetchColumn();

        return $version === false ? null : (int) $version;
    }

    /** Zählt die Version hoch und sperrt dabei die Zeile; liefert die neue Version. */
    public function bumpCalendarVersion(int $garageId, Actor $actor): int
    {
        if (!$actor->isOwner($garageId)) {
            throw new DomainError('forbidden', 'Den Kalender-Link erneuert nur der Inhaber der Garage.', ['garage_id' => $garageId]);
        }

        $this->pdo->prepare('UPDATE garages SET calendar_version = calendar_version + 1 WHERE id = ?')->execute([$garageId]);

        return (int) $this->calendarVersion($garageId);
    }
}
