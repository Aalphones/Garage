<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/** Ausstattung eines Fahrzeugs (Serie und Sonderausstattung) über den Datensatz-Abgleich. */
final class EquipmentService
{
    private const TABLE = 'equipment';
    private const CATEGORIES = ['serie', 'sonder'];
    private const MAX_ITEMS = 200;
    private const EXAMPLE = '{"items": [{"name": "Navigationssystem", "code": "7UG", "category": "sonder", "list_price_eur": "450.00"}]}';

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
     * Alles oder nichts: ein ungültiger Eintrag schreibt gar nichts. Der Name erkennt denselben Eintrag wieder.
     *
     * @param list<mixed> $items name, code?, category, list_price_eur?
     * @param array<string, mixed> $provenance source, source_url?, confidence
     * @return array{vehicle_id: int, items: list<array{name: string, equipment_id: int, result: string, open_item_ids: list<int>}>}
     */
    public function upsert(int $vehicleId, array $items, array $provenance, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($vehicleId, $items, $provenance, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $validated = self::validItems($items);
            $validProvenance = Provenance::validate($provenance, 'equipment');

            $results = [];
            foreach ($validated as $values) {
                $merged = $this->merger->merge(self::TABLE, $values, $validProvenance, $vehicle, $actor);
                $results[] = ['name' => (string) $values['name'], 'equipment_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
            }

            return ['vehicle_id' => $vehicleId, 'items' => $results];
        });
    }

    /** @return list<array{id: int, name: string, code: string|null, category: string, list_price_eur: string|null, source: string, confidence: string}> */
    public function list(int $vehicleId, Actor $actor): array
    {
        $equipment = [];
        foreach ($this->records->listForVehicle(self::TABLE, $vehicleId, $actor) as $row) {
            $equipment[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'code' => $row['code'] === null ? null : (string) $row['code'],
                'category' => (string) $row['category'],
                'list_price_eur' => $row['list_price_cents'] === null ? null : Money::centsToEuro((int) $row['list_price_cents']),
                'source' => (string) $row['source'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        return $equipment;
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string, string|null>>
     */
    private static function validItems(array $items): array
    {
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            throw Validate::invalid('items', sprintf('Erwartet werden 1 bis %d Einträge.', self::MAX_ITEMS), self::EXAMPLE);
        }

        $validated = [];
        $seen = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw Validate::invalid(sprintf('items[%d]', $index), 'Jeder Eintrag ist ein Objekt.', self::EXAMPLE);
            }
            $values = self::validItem($index, $item);
            // Die Datenbank vergleicht ohne Rücksicht auf Groß- und Kleinschreibung; zwei solche Namen wären derselbe Eintrag.
            $nameKey = mb_strtolower((string) $values['name']);
            if (isset($seen[$nameKey])) {
                throw Validate::invalid(sprintf('items[%d].name', $index), sprintf('„%s“ kommt mehrfach in einer Anfrage vor. Pro Name genau ein Eintrag.', $values['name']), self::EXAMPLE);
            }
            $seen[$nameKey] = true;
            $validated[] = $values;
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, string|null>
     */
    private static function validItem(int $index, array $item): array
    {
        $prefix = sprintf('items[%d].', $index);
        $code = Validate::optionalText($prefix . 'code', $item['code'] ?? null, 1, 20, self::EXAMPLE);

        return [
            'name' => Validate::text($prefix . 'name', $item['name'] ?? null, 1, 160, self::EXAMPLE),
            'code' => $code === null ? null : mb_strtoupper($code),
            'category' => Validate::enum($prefix . 'category', $item['category'] ?? null, self::CATEGORIES),
            'list_price_cents' => Validate::euroToCents($prefix . 'list_price_eur', $item['list_price_eur'] ?? null),
        ];
    }
}
