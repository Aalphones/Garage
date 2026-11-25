<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Kosten beim Lesen (ADR-022): sammelt Verträge, Plaketten, Ereignisse, Tankungen, Zubehör, Ausgaben, Wert und
 * Kilometerstände und gibt sie an die reine Klasse `CostCalculator`. Nichts davon wird gespeichert.
 */
final class CostService
{
    private const LOCAL_TIMEZONE = 'Europe/Berlin';
    private const VEHICLE_ACTIVE = 'aktiv';
    private const ELECTRIC = 'elektro';
    private const EVENT_PURCHASE = 'kauf';
    private const EVENT_INSPECTION = 'hu';
    private const INSPECTION_MONTHS = 24;
    private const MIN_YEAR = 1950;
    private const PERIOD_EXAMPLE = '{"period": "jahr:2026"}';

    private const CONTRACT_TITLES = [
        'versicherung' => 'Versicherung',
        'steuer' => 'Kfz-Steuer',
        'club' => 'Club',
        'stellplatz' => 'Stellplatz',
        'finanzierung' => 'Finanzierung',
        'sonstiges' => 'Vertrag',
    ];
    private const EXPENSE_TITLES = [
        'parken' => 'Parken',
        'waesche' => 'Wäsche',
        'pflege' => 'Pflege',
        'maut' => 'Maut',
        'reifeneinlagerung' => 'Reifeneinlagerung',
        'bussgeld' => 'Bußgeld',
        'sonstiges' => 'Ausgabe',
    ];

    private VehicleAccess $access;
    private VehicleRepository $vehicles;
    private ContractRepository $contracts;
    private RecordRepository $records;
    private EventRepository $events;
    private OdometerService $odometer;
    private ValueService $value;

    public function __construct(PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->vehicles = new VehicleRepository($pdo);
        $this->contracts = new ContractRepository($pdo);
        $this->records = new RecordRepository($pdo);
        $this->events = new EventRepository($pdo);
        $this->odometer = new OdometerService($pdo);
        $this->value = new ValueService($pdo);
    }

    /**
     * Kosten eines Fahrzeugs (`get_cost_summary` mit `vehicle_id`, `GET vehicles/{id}/costs`).
     *
     * @return array<string, mixed>
     */
    public function summary(int $vehicleId, ?string $period, ?string $group, Actor $actor): array
    {
        $vehicle = $this->access->readable($vehicleId, $actor);
        $periodKey = self::validPeriod($period);
        $groupKey = self::validGroup($group);
        $today = self::today();
        $garage = $this->garageInput($vehicle['garage_id'], $today, $actor);
        $range = CostCalculator::range($periodKey, $today, $garage['fleet'][$vehicleId]['active']['from'] ?? null);

        $input = $this->vehicleInput($vehicle, $actor);
        $result = CostCalculator::vehicle($range, $input, $garage['fleet'], $garage['contracts']);

        return CostCalculator::presentVehicle($result, $range, $groupKey)
            + ['per_km' => CostCalculator::perKmSeries($range, $input, $garage['fleet'], $garage['contracts'])];
    }

    /**
     * Kosten einer Garage (`get_cost_summary` mit `garage_id`, `GET garages/{id}/costs`); jede Rolle der Garage darf lesen.
     *
     * @return array<string, mixed>
     */
    public function garageSummary(int $garageId, ?string $period, ?string $group, Actor $actor): array
    {
        if (!$actor->canRead($garageId)) {
            throw new DomainError('not_found', 'Eine Garage mit dieser ID gibt es für dich nicht. list_vehicles nennt die Garagen deiner Fahrzeuge.', ['garage_id' => $garageId]);
        }
        $periodKey = self::validPeriod($period);
        $groupKey = self::validGroup($group);
        $today = self::today();
        $garage = $this->garageInput($garageId, $today, $actor);

        $starts = [];
        foreach ($garage['fleet'] as $member) {
            if ($member['active'] !== null && $member['active']['from'] !== null) {
                $starts[] = $member['active']['from'];
            }
        }
        $range = CostCalculator::range($periodKey, $today, $starts === [] ? null : min($starts));

        $results = [];
        foreach ($garage['vehicles'] as $vehicle) {
            $results[] = CostCalculator::vehicle($range, $this->vehicleInput($vehicle, $actor), $garage['fleet'], $garage['contracts']);
        }

        return CostCalculator::presentGarage($garageId, $results, $range, $groupKey, $garage['contracts'], $garage['fleet'], $today);
    }

    /**
     * Unterhalt der letzten 12 Monate je Fahrzeug für Listen (Fahrzeugliste, `list_vehicles`).
     *
     * @param list<array{id: int, garage_id: int}> $vehicles
     * @return array<int, array{month_running_cents: int, month_full_cents: int, missing: list<string>}>
     */
    public function vehicleTotals(array $vehicles, Actor $actor): array
    {
        $today = self::today();
        $byGarage = [];
        foreach ($vehicles as $vehicle) {
            $byGarage[$vehicle['garage_id']][] = $vehicle['id'];
        }

        $totals = [];
        foreach ($byGarage as $garageId => $vehicleIds) {
            $garage = $this->garageInput($garageId, $today, $actor);
            $range = CostCalculator::range(CostCalculator::PERIOD_12_MONTHS, $today, null);
            foreach ($garage['vehicles'] as $vehicle) {
                if (!in_array($vehicle['id'], $vehicleIds, true)) {
                    continue;
                }
                $result = CostCalculator::vehicle($range, $this->vehicleInput($vehicle, $actor), $garage['fleet'], $garage['contracts']);
                $presented = CostCalculator::presentVehicle($result, $range, CostCalculator::GROUP_BLOCK);
                $totals[$vehicle['id']] = [
                    'month_running_cents' => $presented['month_running_cents'],
                    'month_full_cents' => $presented['month_full_cents'],
                    'missing' => $presented['missing'],
                ];
            }
        }

        return $totals;
    }

    /**
     * Fahrzeuge der Garage mit Aktiv-Zeitraum und Kilometerständen (für Umlage und km) und die Garagen-Verträge.
     *
     * @return array{vehicles: list<array<string, mixed>>, fleet: array<int, array<string, mixed>>, contracts: list<array<string, mixed>>}
     */
    private function garageInput(int $garageId, string $today, Actor $actor): array
    {
        $vehicles = $this->vehicles->listForGarage($garageId, $actor);
        $vehicleIds = array_map(static fn (array $vehicle): int => $vehicle['id'], $vehicles);
        $firstRegistrations = $this->vehicles->currentValues($vehicleIds, ['erstzulassung'], $actor);

        $fleet = [];
        foreach ($vehicles as $vehicle) {
            $readings = array_map(
                static fn (array $reading): array => ['read_on' => $reading['read_on'], 'km' => $reading['km']],
                $this->odometer->validReadings($vehicle['id'], $actor)
            );
            $forecast = OdometerForecast::compute($readings, $firstRegistrations[$vehicle['id']]['erstzulassung'] ?? null, $today);
            $fleet[$vehicle['id']] = [
                'vehicle_id' => $vehicle['id'],
                'label' => $vehicle['label'],
                'active' => $this->activePeriod($vehicle, $today, $actor),
                'readings' => $readings,
                'per_day' => $forecast['per_day'] ?? null,
            ];
        }

        return ['vehicles' => $vehicles, 'fleet' => $fleet, 'contracts' => $this->garageContracts($garageId, $actor)];
    }

    /**
     * Aktiv-Zeitraum: ab dem jüngsten Kaufpreis, sonst der ältesten Zulassung, sonst ab Beginn des Zeitraums (`from`
     * `null`); bis `status_since`, wenn das Auto nicht mehr aktiv ist — fehlt das Datum, zählt es nie (`null`).
     *
     * @param array{id: int, status: string, status_since: string|null} $vehicle
     * @return array{from: string|null, to: string}|null
     */
    private function activePeriod(array $vehicle, string $today, Actor $actor): ?array
    {
        $to = $today;
        if ($vehicle['status'] !== self::VEHICLE_ACTIVE) {
            if ($vehicle['status_since'] === null) {
                return null;
            }
            $to = min($vehicle['status_since'], $today);
        }

        $from = $this->value->purchase($vehicle['id'], $actor)['on'] ?? null;
        if ($from === null) {
            $registrations = $this->records->listForVehicle('registrations', $vehicle['id'], $actor);
            if ($registrations !== []) {
                $from = (string) $registrations[array_key_last($registrations)]['valid_from'];
            }
        }
        if ($from !== null && $from > $to) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    /** @return list<array<string, mixed>> */
    private function garageContracts(int $garageId, Actor $actor): array
    {
        $rows = $this->contracts->forGarage($garageId, $actor);
        $contractIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $terms = $this->contracts->childrenOf('contract_terms', $contractIds, $actor);
        $allocations = $this->contracts->childrenOf('contract_allocations', $contractIds, $actor);

        $contracts = [];
        foreach ($rows as $row) {
            $contractId = (int) $row['id'];
            $contracts[] = [
                'contract_id' => $contractId,
                'kind' => (string) $row['kind'],
                'provider' => (string) $row['provider'],
                'title' => self::contractTitle($row),
                'allocation' => (string) ($row['allocation'] ?? ContractService::ALLOCATION_EQUAL),
                'source' => (string) $row['source'],
                'confidence' => (string) $row['confidence'],
                'terms' => array_values(array_map(
                    static fn (array $term): array => [
                        'id' => (int) $term['id'],
                        'from' => (string) $term['valid_from'],
                        'to' => (string) $term['valid_to'],
                        'amount_cents' => (int) $term['amount_cents'],
                        'source' => (string) $term['source'],
                        'confidence' => (string) $term['confidence'],
                    ],
                    array_filter($terms, static fn (array $term): bool => (int) $term['contract_id'] === $contractId)
                )),
                'allocations' => array_values(array_map(
                    static fn (array $allocation): array => [
                        'vehicle_id' => (int) $allocation['vehicle_id'],
                        'share_percent' => (float) $allocation['share_percent'],
                        'valid_from' => (string) $allocation['valid_from'],
                    ],
                    array_filter($allocations, static fn (array $allocation): bool => (int) $allocation['contract_id'] === $contractId)
                )),
            ];
        }

        return $contracts;
    }

    /**
     * Alle Posten eines Fahrzeugs in der Form des Rechners; der Rechner zählt davon nur die Tage im Zeitraum.
     *
     * @param array{id: int, garage_id: int, label: string} $vehicle
     * @return array{vehicle_id: int, label: string, is_electric: bool, posts: list<array<string, mixed>>}
     */
    private function vehicleInput(array $vehicle, Actor $actor): array
    {
        $vehicleId = $vehicle['id'];
        $fuelType = $this->vehicles->currentValues([$vehicleId], ['kraftstoff'], $actor)[$vehicleId]['kraftstoff'] ?? '';

        $posts = [
            ...$this->contractPosts($vehicleId, $vehicle['garage_id'], $actor),
            ...$this->permitPosts($vehicleId, $actor),
            ...$this->eventPosts($vehicleId, $actor),
            ...$this->fuelPosts($vehicleId, $actor),
            ...$this->accessoryPosts($vehicleId, $actor),
            ...$this->expensePosts($vehicleId, $actor),
        ];
        $loss = $this->lossPost($vehicleId, $actor);
        if ($loss !== null) {
            $posts[] = $loss;
        }

        return [
            'vehicle_id' => $vehicleId,
            'label' => $vehicle['label'],
            'is_electric' => mb_strtolower(trim($fuelType)) === self::ELECTRIC,
            'posts' => $posts,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function contractPosts(int $vehicleId, int $garageId, Actor $actor): array
    {
        $contracts = array_values(array_filter(
            $this->contracts->forVehicle($vehicleId, $garageId, $actor),
            static fn (array $row): bool => $row['vehicle_id'] !== null
        ));
        $byId = [];
        foreach ($contracts as $contract) {
            $byId[(int) $contract['id']] = $contract;
        }

        $posts = [];
        foreach ($this->contracts->childrenOf('contract_terms', array_keys($byId), $actor) as $term) {
            $contract = $byId[(int) $term['contract_id']];
            $posts[] = self::post(
                CostCalculator::BLOCK_FIX,
                (string) $contract['kind'],
                self::contractTitle($contract),
                (int) $term['amount_cents'],
                (string) $term['valid_from'],
                (string) $term['valid_to'],
                'contract_terms',
                $term
            );
        }

        return $posts;
    }

    /**
     * Plaketten über ihre Gültigkeit; ohne Ende der ganze Betrag am ersten Tag. Ohne Preis oder Beginn kein Posten.
     *
     * @return list<array<string, mixed>>
     */
    private function permitPosts(int $vehicleId, Actor $actor): array
    {
        $posts = [];
        foreach ($this->records->listForVehicle('permits', $vehicleId, $actor) as $row) {
            if ($row['price_cents'] === null || $row['valid_from'] === null) {
                continue;
            }
            $from = (string) $row['valid_from'];
            $to = $row['valid_until'] === null ? $from : max($from, (string) $row['valid_until']);
            $posts[] = self::post(CostCalculator::BLOCK_FIX, 'plakette', 'Plakette ' . $row['label'], (int) $row['price_cents'], $from, $to, 'permits', $row);
        }

        return $posts;
    }

    /**
     * HU über 24 Monate ab Leistungsdatum (Fixkosten), übrige Ereignisse außer Kauf am Leistungsdatum (Werkstatt).
     *
     * @return list<array<string, mixed>>
     */
    private function eventPosts(int $vehicleId, Actor $actor): array
    {
        $posts = [];
        foreach ($this->events->listForVehicle($vehicleId, null, null, null, $actor) as $row) {
            $kind = (string) $row['kind'];
            if ($kind === self::EVENT_PURCHASE) {
                continue;
            }
            $performedOn = (string) $row['performed_on'];
            $note = null;
            if ($row['gross_cents'] !== null) {
                $cents = (int) $row['gross_cents'];
            } elseif ($row['net_cents'] !== null && $row['vat_cents'] !== null) {
                $cents = (int) $row['net_cents'] + (int) $row['vat_cents'];
            } else {
                $cents = 0;
                $note = 'kein Betrag am Ereignis erfasst';
            }

            if ($kind === self::EVENT_INSPECTION) {
                $to = DueCalculator::addDays(DueCalculator::addMonths($performedOn, self::INSPECTION_MONTHS), -1);
                $posts[] = self::post(CostCalculator::BLOCK_FIX, $kind, 'HU ' . $row['title'], $cents, $performedOn, $to, 'events', $row, $note);
                continue;
            }
            $posts[] = self::post(CostCalculator::BLOCK_SHOP, $kind, (string) $row['title'], $cents, $performedOn, $performedOn, 'events', $row, $note);
        }

        return $posts;
    }

    /** @return list<array<string, mixed>> */
    private function fuelPosts(int $vehicleId, Actor $actor): array
    {
        $posts = [];
        foreach ($this->records->listForVehicle('fuel_logs', $vehicleId, $actor) as $row) {
            $title = $row['station'] === null ? 'Tankung' : 'Tankung ' . $row['station'];
            $date = (string) $row['fueled_on'];
            $posts[] = self::post(CostCalculator::BLOCK_RUN, CostCalculator::KIND_FUEL, $title, (int) $row['amount_cents'], $date, $date, 'fuel_logs', $row);
        }

        return $posts;
    }

    /** @return list<array<string, mixed>> */
    private function accessoryPosts(int $vehicleId, Actor $actor): array
    {
        $posts = [];
        foreach ($this->records->listForVehicle('accessories', $vehicleId, $actor) as $row) {
            if ($row['bought_on'] === null || $row['price_cents'] === null) {
                continue;
            }
            $date = (string) $row['bought_on'];
            $posts[] = self::post(CostCalculator::BLOCK_RUN, 'zubehoer', 'Zubehör ' . $row['name'], (int) $row['price_cents'], $date, $date, 'accessories', $row);
        }

        return $posts;
    }

    /**
     * Ausgaben gleichmäßig über `spread_months` Monate ab dem Tag (1 = nur am Tag).
     *
     * @return list<array<string, mixed>>
     */
    private function expensePosts(int $vehicleId, Actor $actor): array
    {
        $posts = [];
        foreach ($this->records->listForVehicle('expenses', $vehicleId, $actor) as $row) {
            $from = (string) $row['spent_on'];
            $months = max(1, (int) $row['spread_months']);
            $to = $months === 1 ? $from : DueCalculator::addDays(DueCalculator::addMonths($from, $months), -1);
            $title = self::EXPENSE_TITLES[(string) $row['category']] ?? 'Ausgabe';
            if ($row['note'] !== null) {
                $title .= ' (' . $row['note'] . ')';
            }
            $posts[] = self::post(CostCalculator::BLOCK_RUN, 'ausgabe', $title, (int) $row['amount_cents'], $from, $to, 'expenses', $row);
        }

        return $posts;
    }

    /**
     * Wertverlust linear vom Kaufpreis bis zum jüngsten Wert, auf die Tage dazwischen; fehlt einer oder liegt der Wert
     * nicht nach dem Kauf, gibt es keinen Posten (`missing` nennt dann `Wertverlust`).
     *
     * @return array<string, mixed>|null
     */
    private function lossPost(int $vehicleId, Actor $actor): ?array
    {
        $purchase = $this->value->purchase($vehicleId, $actor);
        if ($purchase === null) {
            return null;
        }
        $current = $this->value->current($vehicleId, $actor);
        if ($current === null || $current['on'] <= $purchase['on']) {
            return null;
        }

        $sourceName = $current['source'] === ValueService::SOURCE_CORRIDOR ? 'Marktkorridor' : 'Bewertung';
        $title = sprintf(
            'Wertverlust (Kaufpreis %s → %s %s)',
            number_format($purchase['amount_cents'] / 100, 2, ',', '.') . ' €',
            $sourceName,
            number_format($current['amount_cents'] / 100, 2, ',', '.') . ' €'
        );

        return [
            'block' => CostCalculator::BLOCK_LOSS,
            'kind' => CostCalculator::KIND_LOSS,
            'title' => $title,
            'amount_cents' => $purchase['amount_cents'] - $current['amount_cents'],
            'from' => $purchase['on'],
            'to' => DueCalculator::addDays($current['on'], -1),
            'ref' => ['table' => 'valuations', 'id' => null],
            'source' => $current['source'],
            'confidence' => null,
            'note' => null,
        ];
    }

    /**
     * @param array<string, mixed> $row Datensatz mit `id`, `source`, `confidence`
     * @return array<string, mixed>
     */
    private static function post(string $block, string $kind, string $title, int $cents, string $from, string $to, string $table, array $row, ?string $note = null): array
    {
        return [
            'block' => $block,
            'kind' => $kind,
            'title' => $title,
            'amount_cents' => $cents,
            'from' => $from,
            'to' => $to,
            'ref' => ['table' => $table, 'id' => (int) $row['id']],
            'source' => (string) $row['source'],
            'confidence' => (string) $row['confidence'],
            'note' => $note,
        ];
    }

    /** @param array<string, mixed> $contract */
    private static function contractTitle(array $contract): string
    {
        return sprintf('%s (%s)', self::CONTRACT_TITLES[(string) $contract['kind']] ?? 'Vertrag', $contract['provider']);
    }

    private static function validPeriod(?string $period): string
    {
        if ($period === null || $period === '') {
            return CostCalculator::PERIOD_12_MONTHS;
        }
        if ($period === CostCalculator::PERIOD_12_MONTHS || $period === CostCalculator::PERIOD_SINCE_PURCHASE) {
            return $period;
        }
        $currentYear = (int) substr(self::today(), 0, 4);
        if (preg_match('/^jahr:(\d{4})$/', $period, $match) === 1 && (int) $match[1] >= self::MIN_YEAR && (int) $match[1] <= $currentYear) {
            return $period;
        }

        throw Validate::invalid('period', sprintf('Erlaubt sind 12m, seit_kauf oder jahr:JJJJ (%d bis %d).', self::MIN_YEAR, $currentYear), self::PERIOD_EXAMPLE);
    }

    private static function validGroup(?string $group): string
    {
        if ($group === null || $group === '') {
            return CostCalculator::GROUP_BLOCK;
        }

        return Validate::enum('group', $group, CostCalculator::GROUPS);
    }

    private static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TIMEZONE)))->format('Y-m-d');
    }
}
