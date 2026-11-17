<?php

declare(strict_types=1);

namespace Garage\Domain;

use LogicException;
use PDO;
use PDOException;

/** Werkstätten einer Garage; hängt die Garage des Akteurs selbst an (Regel 3 in AGENTS.md). */
final class WorkshopRepository
{
    private const DUPLICATE_KEY_STATE = '23000';

    private AuditRepository $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * Dieselbe Werkstatt (Name + Ort, ohne Rücksicht auf Groß- und Kleinschreibung) wird nie doppelt angelegt.
     * `manufacturer_partner` wird nur gesetzt, solange es unbekannt ist.
     */
    public function findOrCreate(int $garageId, string $name, string $city, ?bool $partner, Actor $actor): int
    {
        if (!$actor->canRead($garageId)) {
            throw new LogicException('Werkstatt in einer fremden Garage.');
        }

        $id = $this->findId($garageId, $name, $city);
        if ($id === null) {
            $id = $this->insert($garageId, $name, $city, $partner, $actor);
        } elseif ($partner !== null) {
            $statement = $this->pdo->prepare('UPDATE workshops SET manufacturer_partner = ? WHERE id = ? AND garage_id = ? AND manufacturer_partner IS NULL');
            $statement->execute([$partner ? 1 : 0, $id, $garageId]);
        }

        return $id;
    }

    /** @return array{id: int, name: string, city: string, manufacturer_partner: bool|null}|null */
    public function find(int $id, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, name, city, manufacturer_partner FROM workshops WHERE id = ? AND garage_id IN (%s)',
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$id, ...$garageIds]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'city' => (string) $row['city'],
            'manufacturer_partner' => $row['manufacturer_partner'] === null ? null : (bool) $row['manufacturer_partner'],
        ];
    }

    private function findId(int $garageId, string $name, string $city): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM workshops WHERE garage_id = ? AND name = ? AND city = ?');
        $statement->execute([$garageId, $name, $city]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function insert(int $garageId, string $name, string $city, ?bool $partner, Actor $actor): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO workshops (garage_id, name, city, manufacturer_partner, actor, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        try {
            $statement->execute([$garageId, $name, $city, $partner === null ? null : ($partner ? 1 : 0), $actor->name]);
        } catch (PDOException $exception) {
            // Eine gleichzeitige Anfrage für ein anderes Fahrzeug der Garage war schneller: deren Werkstatt gilt.
            $existingId = (string) $exception->getCode() === self::DUPLICATE_KEY_STATE ? $this->findId($garageId, $name, $city) : null;
            if ($existingId === null) {
                throw $exception;
            }

            return $existingId;
        }
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->record($actor, 'workshops.create', sprintf('workshops:%d', $id), $garageId, null, ['name' => $name, 'city' => $city]);

        return $id;
    }
}
