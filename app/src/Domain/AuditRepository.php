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

    /** @param array<string, mixed>|null $payload */
    private function encode(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
