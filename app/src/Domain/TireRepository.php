<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Montagen von Reifensätzen; der Satz selbst läuft über den `RecordMerger`. Hängt die Garagen des Akteurs selbst an. */
final class TireRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Alle Montagen der Sätze, älteste zuerst.
     *
     * @param list<int> $tireSetIds
     * @return list<array{id: int, tire_set_id: int, mounted_on: string, mounted_km: int|null, removed_on: string|null, removed_km: int|null}>
     */
    public function forSets(array $tireSetIds, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($tireSetIds === [] || $garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT * FROM tire_mounts WHERE tire_set_id IN (%s) AND garage_id IN (%s) ORDER BY mounted_on, id',
                implode(', ', array_fill(0, count($tireSetIds), '?')),
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([...$tireSetIds, ...$garageIds]);

        return array_map(self::mount(...), $statement->fetchAll());
    }

    /**
     * Montagen ohne Ausbau an diesem Fahrzeug, gesperrt bis zum Ende der Transaktion.
     *
     * @return list<array{id: int, tire_set_id: int, mounted_on: string, mounted_km: int|null, removed_on: string|null, removed_km: int|null}>
     */
    public function openForVehicle(int $vehicleId, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT * FROM tire_mounts WHERE vehicle_id = ? AND removed_on IS NULL AND garage_id IN (%s) ORDER BY id FOR UPDATE',
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$vehicleId, ...$garageIds]);

        return array_map(self::mount(...), $statement->fetchAll());
    }

    /** @return array{id: int, tire_set_id: int, mounted_on: string, mounted_km: int|null, removed_on: string|null, removed_km: int|null}|null */
    public function findMountedOn(int $tireSetId, string $mountedOn, Actor $actor): ?array
    {
        return $this->findOne('tire_set_id = ? AND mounted_on = ?', [$tireSetId, $mountedOn], $actor);
    }

    /** @return array{id: int, tire_set_id: int, mounted_on: string, mounted_km: int|null, removed_on: string|null, removed_km: int|null}|null */
    public function findRemovedOn(int $tireSetId, string $removedOn, Actor $actor): ?array
    {
        return $this->findOne('tire_set_id = ? AND removed_on = ?', [$tireSetId, $removedOn], $actor);
    }

    public function insert(int $garageId, int $vehicleId, int $tireSetId, string $mountedOn, ?string $mountedKm, Actor $actor): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tire_mounts (garage_id, vehicle_id, tire_set_id, mounted_on, mounted_km, actor, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([$garageId, $vehicleId, $tireSetId, $mountedOn, $mountedKm, $actor->name]);

        return (int) $this->pdo->lastInsertId();
    }

    public function close(int $mountId, int $garageId, string $removedOn, ?string $removedKm): void
    {
        $statement = $this->pdo->prepare('UPDATE tire_mounts SET removed_on = ?, removed_km = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND garage_id = ?');
        $statement->execute([$removedOn, $removedKm, $mountId, $garageId]);
    }

    public function fillMountedKm(int $mountId, int $garageId, string $mountedKm): void
    {
        $statement = $this->pdo->prepare('UPDATE tire_mounts SET mounted_km = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND garage_id = ?');
        $statement->execute([$mountedKm, $mountId, $garageId]);
    }

    /**
     * @param list<mixed> $parameters
     * @return array{id: int, tire_set_id: int, mounted_on: string, mounted_km: int|null, removed_on: string|null, removed_km: int|null}|null
     */
    private function findOne(string $condition, array $parameters, Actor $actor): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT * FROM tire_mounts WHERE %s AND garage_id IN (%s) ORDER BY id LIMIT 1 FOR UPDATE',
                $condition,
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([...$parameters, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : self::mount($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, tire_set_id: int, mounted_on: string, mounted_km: int|null, removed_on: string|null, removed_km: int|null}
     */
    private static function mount(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tire_set_id' => (int) $row['tire_set_id'],
            'mounted_on' => (string) $row['mounted_on'],
            'mounted_km' => $row['mounted_km'] === null ? null : (int) $row['mounted_km'],
            'removed_on' => $row['removed_on'] === null ? null : (string) $row['removed_on'],
            'removed_km' => $row['removed_km'] === null ? null : (int) $row['removed_km'],
        ];
    }
}
