<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/** Teile eines Fahrzeugs: was verbaut ist und welche Alternativen passen, über den Datensatz-Abgleich. */
final class PartService
{
    public const ROLES = ['verbaut', 'alternative'];

    private const TABLE = 'parts';
    private const EXAMPLE = '{"component": "Bremsbeläge vorn", "brand": "ATE", "article_number": "13.0460-7188.2", "role": "verbaut", "verified": true}';

    private VehicleAccess $access;
    private RecordMerger $merger;
    private RecordRepository $records;

    public function __construct(private readonly PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->merger = new RecordMerger($pdo);
        $this->records = new RecordRepository($pdo);
    }

    /**
     * Bauteil, Marke und Artikelnummer erkennen dasselbe Teil wieder; fehlende Marke oder Nummer zählt als leer.
     *
     * @param array<string, mixed> $input component, brand?, article_number?, oem_number?, role, verified?, note?, source, source_url?, confidence
     * @return array{part_id: int, result: string, open_item_ids: list<int>}
     */
    public function add(int $vehicleId, array $input, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($vehicleId, $input, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $values = self::values($input);
            $provenance = Provenance::validate($input, 'part');
            $merged = $this->merger->merge(self::TABLE, $values, $provenance, $vehicle, $actor);

            return ['part_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
        });
    }

    /**
     * Nach Bauteil gruppiert; je Bauteil stehen die verbauten Teile vor den Alternativen.
     *
     * @return array{components: list<array{component: string, parts: list<array<string, mixed>>}>}
     */
    public function grouped(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);

        $components = [];
        foreach ($this->records->listForVehicle(self::TABLE, $vehicleId, $actor) as $row) {
            $component = (string) $row['component'];
            $key = mb_strtolower($component);
            if (!isset($components[$key])) {
                $components[$key] = ['component' => $component, 'parts' => []];
            }
            $components[$key]['parts'][] = [
                'id' => (int) $row['id'],
                'brand' => (string) $row['brand'] === '' ? null : (string) $row['brand'],
                'article_number' => (string) $row['article_number'] === '' ? null : (string) $row['article_number'],
                'oem_number' => $row['oem_number'] === null ? null : (string) $row['oem_number'],
                'role' => (string) $row['role'],
                'verified' => $row['verified'] === null ? null : (bool) $row['verified'],
                'note' => $row['note'] === null ? null : (string) $row['note'],
                'source' => (string) $row['source'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        return ['components' => array_values($components)];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private static function values(array $input): array
    {
        return [
            'component' => Validate::text('component', $input['component'] ?? null, 1, 80, self::EXAMPLE),
            'brand' => Validate::optionalText('brand', $input['brand'] ?? null, 1, 60, '{"brand": "ATE"}') ?? '',
            'article_number' => Validate::optionalText('article_number', $input['article_number'] ?? null, 1, 60, '{"article_number": "13.0460-7188.2"}') ?? '',
            'oem_number' => Validate::optionalText('oem_number', $input['oem_number'] ?? null, 1, 60, '{"oem_number": "5Q0698151"}'),
            'role' => Validate::enum('role', $input['role'] ?? null, self::ROLES),
            'verified' => Validate::bool('verified', $input['verified'] ?? null),
            'note' => Validate::optionalText('note', $input['note'] ?? null, 1, 200, '{"note": "passt laut Händler auch für Baujahr 2019"}'),
        ];
    }
}
