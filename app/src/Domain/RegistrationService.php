<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/** Zulassungen eines Fahrzeugs (Kennzeichen, Halter, Gültigkeit) über den Datensatz-Abgleich. */
final class RegistrationService
{
    private const TABLE = 'registrations';
    private const PLATE_EXAMPLE = '{"plate": "B-AB 1234", "valid_from": "2020-05-12"}';

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
     * Dieselbe Zulassung (Kennzeichen + gültig ab) wird ergänzt, nie verdoppelt.
     *
     * @param array<string, mixed> $input plate, valid_from, valid_to?, holder?, holder_address?, source, source_url?, confidence
     * @return array{registration_id: int, result: string, open_item_ids: list<int>}
     */
    public function record(int $vehicleId, array $input, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($vehicleId, $input, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $values = self::values($input);
            $provenance = Provenance::validate($input, 'registration');
            $merged = $this->merger->merge(self::TABLE, $values, $provenance, $vehicle, $actor);

            return ['registration_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
        });
    }

    /**
     * Neueste zuerst.
     *
     * @return list<array{id: int, plate: string, valid_from: string, valid_to: string|null, holder: string|null, holder_address: string|null, source: string, source_url: string|null, confidence: string}>
     */
    public function list(int $vehicleId, Actor $actor): array
    {
        $registrations = [];
        foreach ($this->records->listForVehicle(self::TABLE, $vehicleId, $actor) as $row) {
            $registrations[] = [
                'id' => (int) $row['id'],
                'plate' => (string) $row['plate'],
                'valid_from' => (string) $row['valid_from'],
                'valid_to' => $row['valid_to'] === null ? null : (string) $row['valid_to'],
                'holder' => $row['holder'] === null ? null : (string) $row['holder'],
                'holder_address' => $row['holder_address'] === null ? null : (string) $row['holder_address'],
                'source' => (string) $row['source'],
                'source_url' => $row['source_url'] === null ? null : (string) $row['source_url'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        return $registrations;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private static function values(array $input): array
    {
        $validFrom = Validate::date('valid_from', $input['valid_from'] ?? null, self::PLATE_EXAMPLE);
        $validTo = ($input['valid_to'] ?? null) === null ? null : Validate::date('valid_to', $input['valid_to'], '{"valid_to": "2024-12-31"}');
        if ($validTo !== null && $validTo < $validFrom) {
            throw Validate::invalid('valid_to', 'Das Ende liegt vor dem Beginn (valid_from).', '{"valid_from": "2020-05-12", "valid_to": "2024-12-31"}');
        }

        return [
            'plate' => Validate::text('plate', self::normalizedPlate($input['plate'] ?? null), 1, 15, self::PLATE_EXAMPLE),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'holder' => Validate::optionalText('holder', $input['holder'] ?? null, 1, 120, '{"holder": "Erika Mustermann"}'),
            'holder_address' => Validate::optionalText('holder_address', $input['holder_address'] ?? null, 1, 200, '{"holder_address": "Musterstraße 1, 12345 Musterstadt"}'),
        ];
    }

    /** Großbuchstaben, ein Leerzeichen zwischen den Teilen; alles andere prüft `Validate::text`. */
    private static function normalizedPlate(mixed $raw): mixed
    {
        if (!is_string($raw)) {
            return $raw;
        }

        return mb_strtoupper((string) preg_replace('/[ ]{2,}/', ' ', trim($raw)));
    }
}
