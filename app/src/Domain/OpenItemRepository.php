<?php

declare(strict_types=1);

namespace Garage\Domain;

use LogicException;
use PDO;

/** Klärfälle. Anlegen dürfen Agenten, lösen nur Menschen (kommt mit Phase 5 von Meilenstein 2). */
final class OpenItemRepository
{
    public const KIND_CONFLICT = 'konflikt';
    public const KIND_QUESTION = 'nachfragen';
    public const KIND_WATCH = 'beobachten';

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed>|null $proposal der Wert, den ein Mensch mit „Neuer Wert gilt“ übernehmen kann */
    public function insert(int $garageId, ?int $vehicleId, string $kind, string $text, ?string $refTable, ?int $refId, Actor $actor, ?array $proposal = null): int
    {
        if (!$actor->canRead($garageId)) {
            throw new LogicException('Klärfall in einer fremden Garage.');
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO open_items (garage_id, vehicle_id, kind, text, ref_table, ref_id, proposal_json, status, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'offen', ?, UTC_TIMESTAMP())"
        );
        $statement->execute([
            $garageId,
            $vehicleId,
            $kind,
            $text,
            $refTable,
            $refId,
            $proposal === null ? null : json_encode($proposal, self::JSON_FLAGS),
            $actor->name,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Offene Klärfälle zu einer Zeile, damit derselbe Widerspruch nicht zweimal angelegt wird.
     *
     * @return list<array{id: int, proposal: array<string, mixed>|null}>
     */
    public function findOpenByRef(string $refTable, int $refId, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id, proposal_json FROM open_items
                 WHERE ref_table = ? AND ref_id = ? AND status = 'offen' AND garage_id IN (%s)
                 ORDER BY id",
                implode(', ', array_fill(0, count($garageIds), '?'))
            )
        );
        $statement->execute([$refTable, $refId, ...$garageIds]);

        $found = [];
        foreach ($statement->fetchAll() as $row) {
            $found[] = [
                'id' => (int) $row['id'],
                'proposal' => $row['proposal_json'] === null ? null : json_decode((string) $row['proposal_json'], true, 512, JSON_THROW_ON_ERROR),
            ];
        }

        return $found;
    }
}
