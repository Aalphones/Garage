<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Aus DISPATCH (ADR-005). */
final class AuditRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(Actor $actor, string $action, string $target, ?int $garageId = null, ?array $before = null, ?array $after = null, ?int $vehicleId = null): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (garage_id, vehicle_id, user_id, actor, action, target, before_json, after_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $statement->execute([
            $garageId,
            $vehicleId,
            $actor->userId,
            $actor->name,
            $action,
            $target,
            $this->encode($before),
            $this->encode($after),
        ]);
    }

    /**
     * Die jüngsten Einträge zu den Garagen des Akteurs, neueste zuerst. Einträge ohne Garage (Anmeldung, Zugänge) gehören keinem Fahrzeug und bleiben draußen.
     *
     * @return list<array{id: int, at: string, actor: string, action: string, target: string, vehicle_id: int|null, before: array<string, mixed>|null, after: array<string, mixed>|null}>
     */
    public function recent(?int $vehicleId, int $limit, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $conditions = [sprintf('garage_id IN (%s)', implode(', ', array_fill(0, count($garageIds), '?')))];
        $parameters = $garageIds;
        if ($vehicleId !== null) {
            $conditions[] = 'vehicle_id = ?';
            $parameters[] = $vehicleId;
        }

        // `$limit` ist ein geprüfter int; die Datenbank nimmt in LIMIT keinen gebundenen Text.
        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, created_at, actor, action, target, vehicle_id, before_json, after_json FROM audit_log WHERE %s ORDER BY id DESC LIMIT %d',
                implode(' AND ', $conditions),
                $limit
            )
        );
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'at' => (string) $row['created_at'],
            'actor' => (string) $row['actor'],
            'action' => (string) $row['action'],
            'target' => (string) $row['target'],
            'vehicle_id' => $row['vehicle_id'] === null ? null : (int) $row['vehicle_id'],
            'before' => $row['before_json'] === null ? null : json_decode((string) $row['before_json'], true, 512, JSON_THROW_ON_ERROR),
            'after' => $row['after_json'] === null ? null : json_decode((string) $row['after_json'], true, 512, JSON_THROW_ON_ERROR),
        ], $statement->fetchAll());
    }

    /** @param array<string, mixed>|null $payload */
    private function encode(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
