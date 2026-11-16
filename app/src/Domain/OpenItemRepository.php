<?php

declare(strict_types=1);

namespace Garage\Domain;

use LogicException;
use PDO;

/** Klärfälle. Anlegen dürfen Agenten, lösen nur Menschen (kommt mit Meilenstein 2). */
final class OpenItemRepository
{
    public const KIND_CONFLICT = 'konflikt';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(int $garageId, ?int $vehicleId, string $kind, string $text, ?string $refTable, ?int $refId, Actor $actor): int
    {
        if (!$actor->canRead($garageId)) {
            throw new LogicException('Klärfall in einer fremden Garage.');
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO open_items (garage_id, vehicle_id, kind, text, ref_table, ref_id, status, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'offen', ?, UTC_TIMESTAMP())"
        );
        $statement->execute([$garageId, $vehicleId, $kind, $text, $refTable, $refId, $actor->name]);

        return (int) $this->pdo->lastInsertId();
    }
}
