<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/** Tankbuch: jede Tankung trägt ihren Kilometerstand mit; der Verbrauch wird beim Lesen gerechnet, nie gespeichert (ADR-025). */
final class FuelService
{
    public const MIN_LITERS = 0.5;
    public const MAX_LITERS = 200.0;
    public const MIN_CENTS = 1;
    public const MAX_CENTS = 100000;

    private const TABLE = 'fuel_logs';
    private const ODOMETER_NOTE = 'Tankung';
    private const EXAMPLE = '{"fueled_on": "2026-11-20", "odometer_km": 27410, "liters": "42.35", "amount_eur": "71.80", "is_full": true, "station": "Aral Friedrichstraße"}';

    private VehicleAccess $access;
    private RecordMerger $merger;
    private RecordRepository $records;
    private OdometerService $odometer;

    public function __construct(private readonly PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->merger = new RecordMerger($pdo);
        $this->records = new RecordRepository($pdo);
        $this->odometer = new OdometerService($pdo);
    }

    /**
     * Datum und Kilometerstand erkennen dieselbe Tankung wieder. Der Kilometerstand entsteht in derselben Transaktion;
     * ein Rückwärts-Stand wird dort ein Klärfall, die Tankung bleibt gespeichert.
     *
     * @param array<string, mixed> $input fueled_on, odometer_km, liters, amount_eur, is_full, station?, source, source_url?, confidence
     * @return array{fuel_id: int, result: string, odometer: array{reading_id: int, result: string, open_item_id?: int}, consumption_l_100km: float|null, open_item_ids: list<int>}
     */
    public function record(int $vehicleId, array $input, Actor $actor): array
    {
        $values = self::values($input);
        $provenance = Provenance::validate($input, 'fuel');

        return Transaction::run($this->pdo, function () use ($vehicleId, $values, $provenance, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $odometer = $this->odometer->recordInTransaction($vehicle, $values['fueled_on'], $values['odometer_km'], null, self::ODOMETER_NOTE, $provenance, $actor);
            $merged = $this->merger->merge(self::TABLE, $values + ['odometer_reading_id' => (string) $odometer['reading_id']], $provenance, $vehicle, $actor);

            return [
                'fuel_id' => $merged['id'],
                'result' => $merged['result'],
                'odometer' => $odometer,
                'consumption_l_100km' => $this->consumptionOf($merged['id'], $vehicleId, $actor),
                'open_item_ids' => $merged['open_item_ids'],
            ];
        });
    }

    /**
     * Tankung eines Menschen im Browser (ADR-025): Herkunft `nutzer`/`bestaetigt`, Datum nicht in der Zukunft.
     *
     * @param array<string, mixed> $input fueled_on, odometer_km, liters, amount_eur, is_full, station?
     * @return array{fuel_id: int, result: string, odometer: array{reading_id: int, result: string, open_item_id?: int}, consumption_l_100km: float|null, open_item_ids: list<int>}
     */
    public function recordByHuman(int $vehicleId, array $input, Actor $actor): array
    {
        $fueledOn = Validate::dateNotInFuture('fueled_on', $input['fueled_on'] ?? null, self::EXAMPLE, 'Das Datum der Tankung liegt in der Zukunft.');
        $human = [
            'fueled_on' => $fueledOn,
            'odometer_km' => $input['odometer_km'] ?? null,
            'liters' => $input['liters'] ?? null,
            'amount_eur' => $input['amount_eur'] ?? null,
            'is_full' => $input['is_full'] ?? null,
            'station' => $input['station'] ?? null,
        ];

        return $this->record($vehicleId, $human + Provenance::BY_HUMAN, $actor);
    }

    /**
     * Neueste zuerst; der Verbrauch gehört zu einer Volltankung und fehlt ohne vorherige Volltankung.
     *
     * @return array{fuel: list<array<string, mixed>>}
     */
    public function list(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);

        $rows = $this->records->listForVehicle(self::TABLE, $vehicleId, $actor);
        $consumption = self::consumptions($rows);
        $fuel = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $liters = (float) $row['liters'];
            $fuel[] = [
                'id' => $id,
                'fueled_on' => (string) $row['fueled_on'],
                'odometer_km' => (int) $row['odometer_km'],
                'liters' => number_format($liters, 2, '.', ''),
                'amount_eur' => Money::centsToEuro((int) $row['amount_cents']),
                'price_per_liter' => number_format((int) $row['amount_cents'] / 100 / $liters, 3, '.', ''),
                'is_full' => (bool) $row['is_full'],
                'station' => $row['station'] === null ? null : (string) $row['station'],
                'consumption_l_100km' => $consumption[$id] ?? null,
                'source' => (string) $row['source'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        return ['fuel' => $fuel];
    }

    /**
     * Verbrauch je Volltankung: Liter aller Tankungen nach der vorherigen Volltankung bis einschließlich dieser, geteilt
     * durch die Kilometer seit der vorherigen Volltankung. `$rows` stehen neueste zuerst.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, float> Tankung-ID → l/100 km
     */
    private static function consumptions(array $rows): array
    {
        $consumption = [];
        $previousFullKm = null;
        $litersSinceFull = 0.0;
        foreach (array_reverse($rows) as $row) {
            $litersSinceFull += (float) $row['liters'];
            if (!(bool) $row['is_full']) {
                continue;
            }
            $km = (int) $row['odometer_km'];
            if ($previousFullKm !== null && $km > $previousFullKm) {
                $consumption[(int) $row['id']] = round($litersSinceFull / ($km - $previousFullKm) * 100, 1);
            }
            $previousFullKm = $km;
            $litersSinceFull = 0.0;
        }

        return $consumption;
    }

    private function consumptionOf(int $fuelId, int $vehicleId, Actor $actor): ?float
    {
        return self::consumptions($this->records->listForVehicle(self::TABLE, $vehicleId, $actor))[$fuelId] ?? null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{fueled_on: string, odometer_km: string, liters: string, amount_cents: string, is_full: string, station: string|null}
     */
    private static function values(array $input): array
    {
        $liters = Validate::decimal('liters', $input['liters'] ?? null, 2, '{"liters": "42.35"}');
        if ((float) $liters < self::MIN_LITERS || (float) $liters > self::MAX_LITERS) {
            throw Validate::invalid('liters', 'Erwartet werden 0,5 bis 200 Liter.', '{"liters": "42.35"}');
        }
        $cents = Validate::euroToCents('amount_eur', $input['amount_eur'] ?? null);
        if ($cents === null || (int) $cents < self::MIN_CENTS || (int) $cents > self::MAX_CENTS) {
            throw Validate::invalid('amount_eur', 'Erwartet wird ein Betrag von 0,01 bis 1.000 Euro.', '{"amount_eur": "71.80"}');
        }
        $isFull = Validate::bool('is_full', $input['is_full'] ?? null);
        if ($isFull === null) {
            throw Validate::invalid('is_full', 'Pflicht: true bei voll getankt, sonst false.', '{"is_full": true}');
        }

        return [
            'fueled_on' => Validate::date('fueled_on', $input['fueled_on'] ?? null, self::EXAMPLE),
            'odometer_km' => OdometerService::validKm('odometer_km', $input['odometer_km'] ?? null),
            'liters' => $liters,
            'amount_cents' => $cents,
            'is_full' => $isFull,
            'station' => Validate::optionalText('station', $input['station'] ?? null, 1, 80, '{"station": "Aral Friedrichstraße"}'),
        ];
    }
}
