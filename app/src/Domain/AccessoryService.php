<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/** Zubehör eines Fahrzeugs (Träger, Kindersitz, Matten …), über den Datensatz-Abgleich; der Name erkennt dasselbe Stück wieder. */
final class AccessoryService
{
    public const CATEGORIES = ['traeger', 'kindersitz', 'matten', 'dashcam', 'kabel', 'sonstiges'];
    public const MOUNT_STATES = ['montiert', 'eingelagert'];

    private const TABLE = 'accessories';
    private const EXAMPLE = '{"vehicle_id": 12, "name": "Thule EasyFold XT 2", "category": "traeger", "bought_on": "2024-05-02", "price_eur": "649.00", "mount_state": "eingelagert", "storage_location": "Keller", "goes_with_sale": false}';

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
     * @param array<string, mixed> $input name, category, manufacturer?, article_number?, bought_on?, price_eur?, warranty_until?, mount_state?, storage_location?, goes_with_sale?, source, source_url?, confidence
     * @return array{accessory_id: int, result: string, open_item_ids: list<int>}
     */
    public function upsert(int $vehicleId, array $input, Actor $actor): array
    {
        $values = self::values($input);
        $provenance = Provenance::validate($input, 'accessory');

        return Transaction::run($this->pdo, function () use ($vehicleId, $values, $provenance, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $merged = $this->merger->merge(self::TABLE, $values, $provenance, $vehicle, $actor);

            return ['accessory_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
        });
    }

    /**
     * Der Aufrufer hat das Fahrzeug über `VehicleAccess::readable` geprüft.
     *
     * @return list<array<string, mixed>>
     */
    public function forVehicle(int $vehicleId, Actor $actor): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'manufacturer' => $row['manufacturer'] === null ? null : (string) $row['manufacturer'],
            'article_number' => $row['article_number'] === null ? null : (string) $row['article_number'],
            'bought_on' => $row['bought_on'] === null ? null : (string) $row['bought_on'],
            'price_eur' => $row['price_cents'] === null ? null : Money::centsToEuro((int) $row['price_cents']),
            'warranty_until' => $row['warranty_until'] === null ? null : (string) $row['warranty_until'],
            'mount_state' => $row['mount_state'] === null ? null : (string) $row['mount_state'],
            'storage_location' => $row['storage_location'] === null ? null : (string) $row['storage_location'],
            'goes_with_sale' => $row['goes_with_sale'] === null ? null : (bool) $row['goes_with_sale'],
            'source' => (string) $row['source'],
            'confidence' => (string) $row['confidence'],
        ], $this->records->listForVehicle(self::TABLE, $vehicleId, $actor));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private static function values(array $input): array
    {
        return [
            'name' => Validate::text('name', $input['name'] ?? null, 1, 120, self::EXAMPLE),
            'category' => Validate::enum('category', $input['category'] ?? null, self::CATEGORIES),
            'manufacturer' => Validate::optionalText('manufacturer', $input['manufacturer'] ?? null, 1, 80, '{"manufacturer": "Thule"}'),
            'article_number' => Validate::optionalText('article_number', $input['article_number'] ?? null, 1, 60, '{"article_number": "933100"}'),
            'bought_on' => Validate::optionalDate('bought_on', $input['bought_on'] ?? null, '{"bought_on": "2024-05-02"}'),
            'price_cents' => Validate::euroToCents('price_eur', $input['price_eur'] ?? null),
            'warranty_until' => Validate::optionalDate('warranty_until', $input['warranty_until'] ?? null, '{"warranty_until": "2026-05-02"}'),
            'mount_state' => isset($input['mount_state']) ? Validate::enum('mount_state', $input['mount_state'], self::MOUNT_STATES) : null,
            'storage_location' => Validate::optionalText('storage_location', $input['storage_location'] ?? null, 1, 120, '{"storage_location": "Keller, Regal links"}'),
            'goes_with_sale' => Validate::bool('goes_with_sale', $input['goes_with_sale'] ?? null),
        ];
    }
}
