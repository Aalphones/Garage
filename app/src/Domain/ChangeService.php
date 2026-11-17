<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/** Verlauf: die jüngsten Audit-Einträge der eigenen Garagen, optional für ein Fahrzeug. */
final class ChangeService
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    private VehicleAccess $access;
    private AuditRepository $audit;

    public function __construct(PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->audit = new AuditRepository($pdo);
    }

    /**
     * @return list<array{id: int, at: string, actor: string, action: string, target: string, vehicle_id: int|null, before: array<string, mixed>|null, after: array<string, mixed>|null}>
     */
    public function list(?int $vehicleId, mixed $limit, Actor $actor): array
    {
        $count = $limit === null ? self::DEFAULT_LIMIT : (int) Validate::int('limit', $limit, '{"limit": 50}', 1, self::MAX_LIMIT);
        if ($vehicleId !== null) {
            $this->access->readable($vehicleId, $actor);
        }

        return $this->audit->recent($vehicleId, $count, $actor);
    }
}
