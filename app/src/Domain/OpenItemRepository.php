<?php

declare(strict_types=1);

namespace Garage\Domain;

use LogicException;
use PDO;

/** Klärfälle. Anlegen dürfen Agenten, lösen nur Menschen (`OpenItemService::resolve`). Hängt die Garagen des Akteurs selbst an. */
final class OpenItemRepository
{
    public const KIND_CONFLICT = 'konflikt';
    public const KIND_QUESTION = 'nachfragen';
    public const KIND_WATCH = 'beobachten';

    public const STATUS_OPEN = 'offen';
    public const STATUS_RESOLVED = 'geloest';
    public const STATUS_DISCARDED = 'verworfen';
    /** Filterwert der Liste: alle Stati. */
    public const STATUS_ALL = 'alle';

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    private const LIST_LIMIT = 200;

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
     * Neueste zuerst, höchstens 200. `$status` ist ein Stati-Wert oder `alle`.
     *
     * @return list<array{id: int, garage_id: int, vehicle_id: int|null, vehicle_label: string|null, kind: string, text: string, ref_table: string|null, ref_id: int|null, proposal: array<string, mixed>|null, status: string, resolution: string|null, resolved_by: string|null, resolved_at: string|null, created_by: string, created_at: string}>
     */
    public function list(?int $vehicleId, string $status, Actor $actor): array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return [];
        }

        $conditions = [sprintf('o.garage_id IN (%s)', self::placeholders($garageIds))];
        $parameters = $garageIds;
        if ($vehicleId !== null) {
            $conditions[] = 'o.vehicle_id = ?';
            $parameters[] = $vehicleId;
        }
        if ($status !== self::STATUS_ALL) {
            $conditions[] = 'o.status = ?';
            $parameters[] = $status;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT o.*, v.label AS vehicle_label FROM open_items o LEFT JOIN vehicles v ON v.id = o.vehicle_id
                 WHERE %s ORDER BY o.id DESC LIMIT %d',
                implode(' AND ', $conditions),
                self::LIST_LIMIT
            )
        );
        $statement->execute($parameters);

        return array_map(self::row(...), $statement->fetchAll());
    }

    /**
     * Mit `$lock` bis zum Ende der Transaktion gesperrt (ohne Fahrzeug-Beschriftung, damit die Fahrzeugzeile frei bleibt).
     *
     * @return array{id: int, garage_id: int, vehicle_id: int|null, vehicle_label: string|null, kind: string, text: string, ref_table: string|null, ref_id: int|null, proposal: array<string, mixed>|null, status: string, resolution: string|null, resolved_by: string|null, resolved_at: string|null, created_by: string, created_at: string}|null
     */
    public function find(int $id, Actor $actor, bool $lock = false): ?array
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf('SELECT * FROM open_items WHERE id = ? AND garage_id IN (%s)%s', self::placeholders($garageIds), $lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$id, ...$garageIds]);
        $row = $statement->fetch();

        return $row === false ? null : self::row($row);
    }

    /** Offener Klärfall mit demselben Text an demselben Fahrzeug, damit ein Agent ihn nicht zweimal anlegt. */
    public function findOpenByText(int $vehicleId, string $kind, string $text, Actor $actor): ?int
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return null;
        }

        // Binärer Vergleich: nur derselbe Wortlaut zählt, nicht „ölfilter“ gegen „Ölfilter“.
        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT id FROM open_items
                 WHERE vehicle_id = ? AND kind = ? AND status = 'offen' AND text = ? COLLATE utf8mb4_bin AND garage_id IN (%s)
                 ORDER BY id LIMIT 1",
                self::placeholders($garageIds)
            )
        );
        $statement->execute([$vehicleId, $kind, $text, ...$garageIds]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Schließt einen offenen Klärfall; was die Entscheidung an den Daten ändert, macht der Aufrufer. */
    public function resolve(int $id, string $status, string $resolution, Actor $actor): void
    {
        $garageIds = $actor->garageIds();
        if ($garageIds === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                "UPDATE open_items SET status = ?, resolution = ?, resolved_by = ?, resolved_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'offen' AND garage_id IN (%s)",
                self::placeholders($garageIds)
            )
        );
        $statement->execute([$status, $resolution, $actor->name, $id, ...$garageIds]);
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
                self::placeholders($garageIds)
            )
        );
        $statement->execute([$refTable, $refId, ...$garageIds]);

        $found = [];
        foreach ($statement->fetchAll() as $row) {
            $found[] = [
                'id' => (int) $row['id'],
                'proposal' => self::decode($row['proposal_json']),
            ];
        }

        return $found;
    }

    /** @param list<int|string> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /** @return array<string, mixed>|null */
    private static function decode(mixed $json): ?array
    {
        return $json === null ? null : json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, garage_id: int, vehicle_id: int|null, vehicle_label: string|null, kind: string, text: string, ref_table: string|null, ref_id: int|null, proposal: array<string, mixed>|null, status: string, resolution: string|null, resolved_by: string|null, resolved_at: string|null, created_by: string, created_at: string}
     */
    private static function row(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'garage_id' => (int) $row['garage_id'],
            'vehicle_id' => $row['vehicle_id'] === null ? null : (int) $row['vehicle_id'],
            'vehicle_label' => isset($row['vehicle_label']) ? (string) $row['vehicle_label'] : null,
            'kind' => (string) $row['kind'],
            'text' => (string) $row['text'],
            'ref_table' => $row['ref_table'] === null ? null : (string) $row['ref_table'],
            'ref_id' => $row['ref_id'] === null ? null : (int) $row['ref_id'],
            'proposal' => self::decode($row['proposal_json']),
            'status' => (string) $row['status'],
            'resolution' => $row['resolution'] === null ? null : (string) $row['resolution'],
            'resolved_by' => $row['resolved_by'] === null ? null : (string) $row['resolved_by'],
            'resolved_at' => $row['resolved_at'] === null ? null : (string) $row['resolved_at'],
            'created_by' => (string) $row['created_by'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
