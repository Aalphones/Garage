<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/** Plaketten und Ausweise eines Fahrzeugs (Umweltplakette, Vignette, Parkausweis …); Art + Bezeichnung erkennen dieselbe wieder. */
final class PermitService
{
    public const KINDS = ['umweltplakette', 'critair', 'vignette', 'parkausweis', 'mautbox', 'sonstiges'];

    private const TABLE = 'permits';
    private const EXAMPLE = '{"vehicle_id": 12, "kind": "vignette", "label": "Österreich 2026", "valid_from": "2025-12-01", "valid_until": "2027-01-31", "price_eur": "103.80"}';

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
     * @param array<string, mixed> $input kind, label, number?, valid_from?, valid_until?, price_eur?, source, source_url?, confidence
     * @return array{permit_id: int, result: string, open_item_ids: list<int>}
     */
    public function upsert(int $vehicleId, array $input, Actor $actor): array
    {
        $values = self::values($input);
        $provenance = Provenance::validate($input, 'permit');

        return Transaction::run($this->pdo, function () use ($vehicleId, $values, $provenance, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $merged = $this->merger->merge(self::TABLE, $values, $provenance, $vehicle, $actor);

            return ['permit_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
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
            'kind' => (string) $row['kind'],
            'label' => (string) $row['label'],
            'number' => $row['number'] === null ? null : (string) $row['number'],
            'valid_from' => $row['valid_from'] === null ? null : (string) $row['valid_from'],
            'valid_until' => $row['valid_until'] === null ? null : (string) $row['valid_until'],
            'price_eur' => $row['price_cents'] === null ? null : Money::centsToEuro((int) $row['price_cents']),
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
        $validFrom = Validate::optionalDate('valid_from', $input['valid_from'] ?? null, self::EXAMPLE);
        $validUntil = Validate::optionalDate('valid_until', $input['valid_until'] ?? null, self::EXAMPLE);
        if ($validFrom !== null && $validUntil !== null && $validUntil < $validFrom) {
            throw Validate::invalid('valid_until', 'Das Ende der Gültigkeit liegt vor ihrem Beginn.', self::EXAMPLE);
        }

        return [
            'kind' => Validate::enum('kind', $input['kind'] ?? null, self::KINDS),
            'label' => Validate::text('label', $input['label'] ?? null, 1, 80, self::EXAMPLE),
            'number' => Validate::optionalText('number', $input['number'] ?? null, 1, 60, '{"number": "B-123456"}'),
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'price_cents' => Validate::euroToCents('price_eur', $input['price_eur'] ?? null),
        ];
    }
}
