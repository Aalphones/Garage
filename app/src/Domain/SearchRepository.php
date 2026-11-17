<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/**
 * Volltext-light über LIKE: je Quelle eine Abfrage, immer mit den Garagen des Akteurs. `%`, `_` und der Maskierer `|`
 * im Suchtext sind schon maskiert (`like`); der Maskierer ist `|` statt Backslash, weil der in SQL-Text und PHP doppelt
 * gedeutet würde.
 *
 * @phpstan-type Hit array{id: int, vehicle_id: int|null, title: string, texts: list<string>, event_id?: int}
 */
final class SearchRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Maskiert einen Suchtext für `LIKE ? ESCAPE '|'` und umschließt ihn mit `%`. */
    public static function like(string $query): string
    {
        return '%' . strtr($query, ['|' => '||', '%' => '|%', '_' => '|_']) . '%';
    }

    /** @return list<array{id: int, vehicle_id: int|null, title: string, texts: list<string>}> */
    public function events(string $like, ?int $vehicleId, int $limit, Actor $actor): array
    {
        return $this->run(
            'SELECT e.id, e.vehicle_id, e.title, e.invoice_number FROM events e WHERE e.status = \'aktiv\' AND %s AND %s ORDER BY e.performed_on DESC, e.id DESC LIMIT %d',
            ['e.title', 'e.invoice_number'],
            'e.garage_id',
            'e.vehicle_id',
            $like,
            $vehicleId,
            $limit,
            $actor,
            static fn (array $row): array => ['title' => (string) $row['title'], 'texts' => [(string) $row['title'], (string) ($row['invoice_number'] ?? '')]]
        );
    }

    /** @return list<array{id: int, vehicle_id: int|null, title: string, texts: list<string>, event_id: int}> */
    public function eventItems(string $like, ?int $vehicleId, int $limit, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $conditions = [
            "i.status = 'aktiv'",
            "e.status = 'aktiv'",
            sprintf('i.garage_id IN (%s)', self::placeholders($garageIds)),
            sprintf('e.garage_id IN (%s)', self::placeholders($garageIds)),
            "(i.description LIKE ? ESCAPE '|' OR i.part_number LIKE ? ESCAPE '|')",
        ];
        $parameters = [...$garageIds, ...$garageIds, $like, $like];
        if ($vehicleId !== null) {
            $conditions[] = 'e.vehicle_id = ?';
            $parameters[] = $vehicleId;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT i.id, i.event_id, e.vehicle_id, i.description, i.part_number
                 FROM event_items i JOIN events e ON e.id = i.event_id
                 WHERE %s ORDER BY e.performed_on DESC, i.id DESC LIMIT %d',
                implode(' AND ', $conditions),
                $limit
            )
        );
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'event_id' => (int) $row['event_id'],
            'vehicle_id' => (int) $row['vehicle_id'],
            'title' => (string) $row['description'],
            'texts' => [(string) $row['description'], (string) ($row['part_number'] ?? '')],
        ], $statement->fetchAll());
    }

    /** @return list<array{id: int, vehicle_id: int|null, title: string, texts: list<string>}> */
    public function parts(string $like, ?int $vehicleId, int $limit, Actor $actor): array
    {
        return $this->run(
            'SELECT p.id, p.vehicle_id, p.component, p.brand, p.article_number, p.oem_number FROM parts p WHERE p.status = \'aktiv\' AND %s AND %s ORDER BY p.id DESC LIMIT %d',
            ['p.component', 'p.oem_number', 'p.article_number'],
            'p.garage_id',
            'p.vehicle_id',
            $like,
            $vehicleId,
            $limit,
            $actor,
            static fn (array $row): array => [
                'title' => trim((string) $row['component'] . ' ' . (string) $row['brand']),
                'texts' => [(string) $row['component'], (string) ($row['oem_number'] ?? ''), (string) $row['article_number']],
            ]
        );
    }

    /** Thema und neueste Revision. @return list<array{id: int, vehicle_id: int|null, title: string, texts: list<string>}> */
    public function notes(string $like, ?int $vehicleId, int $limit, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $conditions = [
            "n.status = 'aktiv'",
            sprintf('n.garage_id IN (%s)', self::placeholders($garageIds)),
            "(n.topic LIKE ? ESCAPE '|' OR r.body LIKE ? ESCAPE '|')",
        ];
        $parameters = [...$garageIds, $like, $like];
        if ($vehicleId !== null) {
            $conditions[] = 'n.vehicle_id = ?';
            $parameters[] = $vehicleId;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT n.id, n.vehicle_id, n.topic, r.body
                 FROM notes n JOIN note_revisions r ON r.note_id = n.id AND r.revision = (SELECT MAX(x.revision) FROM note_revisions x WHERE x.note_id = n.id)
                 WHERE %s ORDER BY n.updated_at DESC, n.id DESC LIMIT %d',
                implode(' AND ', $conditions),
                $limit
            )
        );
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'vehicle_id' => (int) $row['vehicle_id'],
            'title' => (string) $row['topic'],
            'texts' => [(string) $row['topic'], (string) $row['body']],
        ], $statement->fetchAll());
    }

    /** Offene Klärfälle. @return list<array{id: int, vehicle_id: int|null, title: string, texts: list<string>}> */
    public function openItems(string $like, ?int $vehicleId, int $limit, Actor $actor): array
    {
        return $this->run(
            'SELECT o.id, o.vehicle_id, o.kind, o.text FROM open_items o WHERE o.status = \'offen\' AND %s AND %s ORDER BY o.id DESC LIMIT %d',
            ['o.text'],
            'o.garage_id',
            'o.vehicle_id',
            $like,
            $vehicleId,
            $limit,
            $actor,
            static fn (array $row): array => ['title' => ucfirst((string) $row['kind']), 'texts' => [(string) $row['text']]]
        );
    }

    /**
     * @param list<string> $likeColumns
     * @param callable(array<string, mixed>): array{title: string, texts: list<string>} $describe
     * @return list<array{id: int, vehicle_id: int|null, title: string, texts: list<string>}>
     */
    private function run(string $sqlTemplate, array $likeColumns, string $garageColumn, string $vehicleColumn, string $like, ?int $vehicleId, int $limit, Actor $actor, callable $describe): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $matches = array_map(static fn (string $column): string => sprintf("%s LIKE ? ESCAPE '|'", $column), $likeColumns);
        $conditions = [sprintf('(%s)', implode(' OR ', $matches)), sprintf('%s IN (%s)', $garageColumn, self::placeholders($garageIds))];
        $parameters = [...array_fill(0, count($likeColumns), $like), ...$garageIds];
        if ($vehicleId !== null) {
            $conditions[] = $vehicleColumn . ' = ?';
            $parameters[] = $vehicleId;
        }

        // Die Vorlage hat zwei `%s` (Suchbedingung, Garagen/Fahrzeug-Bedingungen) und `%d` (Grenze).
        $statement = $this->pdo->prepare(sprintf($sqlTemplate, $conditions[0], implode(' AND ', array_slice($conditions, 1)), $limit));
        $statement->execute($parameters);

        $hits = [];
        foreach ($statement->fetchAll() as $row) {
            $hits[] = ['id' => (int) $row['id'], 'vehicle_id' => $row['vehicle_id'] === null ? null : (int) $row['vehicle_id']] + $describe($row);
        }

        return $hits;
    }

    /** @param list<int|string> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
