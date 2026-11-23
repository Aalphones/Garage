<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/**
 * Fälligkeiten und Kilometerprognose beim Lesen (ADR-011): holt Aufgaben, Ausführungen und Kilometerstände und gibt sie an
 * die reinen Klassen `OdometerForecast` und `DueCalculator`. Nichts davon wird gespeichert.
 */
final class DueService
{
    public const DEFAULT_HORIZON_MONTHS = 36;
    public const MAX_HORIZON_MONTHS = 120;

    private const STATUS_ORDER = [
        DueCalculator::STATUS_OVERDUE => 0,
        DueCalculator::STATUS_SOON => 1,
        DueCalculator::STATUS_UNKNOWN => 2,
        DueCalculator::STATUS_OK => 3,
    ];
    private const NO_DATE_SORT_KEY = '9999-12-31';

    private VehicleRepository $vehicles;
    private TaskRepository $tasks;
    private OdometerService $odometer;
    private VehicleAccess $access;

    public function __construct(PDO $pdo)
    {
        $this->vehicles = new VehicleRepository($pdo);
        $this->tasks = new TaskRepository($pdo);
        $this->odometer = new OdometerService($pdo);
        $this->access = new VehicleAccess($pdo);
    }

    /**
     * Fällige Aufgaben eines Fahrzeugs oder aller Fahrzeuge des Akteurs, dringendste zuerst. Aufgaben ohne berechenbares
     * Datum stehen immer dabei; sonst gilt der Horizont ab heute.
     *
     * @return array{today: string, thresholds: array{months: int, km: int}, items: list<array<string, mixed>>}
     */
    public function dueItems(?int $vehicleId, int $horizonMonths, Actor $actor): array
    {
        $months = (int) Validate::int('horizon_months', $horizonMonths, '{"horizon_months": 36}', 1, self::MAX_HORIZON_MONTHS);
        $vehicles = $vehicleId === null ? $this->activeVehicles($actor) : [$this->access->readable($vehicleId, $actor)];
        $today = $this->tasks->utcToday();
        $horizon = DueCalculator::addMonths($today, $months);

        $items = array_filter(
            $this->analyse($vehicles, $today, $actor)['items'],
            static fn (array $item): bool => $item['effective_date'] === null
                || $item['effective_date'] <= $horizon
                || in_array($item['status'], [DueCalculator::STATUS_OVERDUE, DueCalculator::STATUS_SOON], true)
        );

        return [
            'today' => $today,
            'thresholds' => ['months' => DueCalculator::SOON_MONTHS, 'km' => DueCalculator::SOON_KM],
            'items' => self::sorted(array_values($items)),
        ];
    }

    /**
     * Geschätzter Kilometerstand und dringendste Aufgabe je Fahrzeug (Übersicht); „unbekannt“ zählt nicht als dringend.
     *
     * @param list<array{id: int, label: string}> $vehicles
     * @return array<int, array{km_estimate: int|null, next_due: array{task_key: string, title: string, effective_date: string|null, status: string}|null}>
     */
    public function summary(array $vehicles, Actor $actor): array
    {
        $analysis = $this->analyse($vehicles, $this->tasks->utcToday(), $actor);

        $summary = [];
        foreach ($vehicles as $vehicle) {
            $summary[$vehicle['id']] = ['km_estimate' => $analysis['forecasts'][$vehicle['id']]['km_estimate'] ?? null, 'next_due' => null];
        }
        foreach (self::sorted($analysis['items']) as $item) {
            $isKnown = $item['status'] !== DueCalculator::STATUS_UNKNOWN;
            if ($isKnown && $summary[$item['vehicle_id']]['next_due'] === null) {
                $summary[$item['vehicle_id']]['next_due'] = [
                    'task_key' => $item['task_key'],
                    'title' => $item['title'],
                    'effective_date' => $item['effective_date'],
                    'status' => $item['status'],
                ];
            }
        }

        return $summary;
    }

    /**
     * Kilometerprognose eines Fahrzeugs mit ihrer Grundlage; `null` ohne gültigen Stand. `per_day` ist auf eine Stelle gerundet,
     * gerechnet wird ungerundet.
     *
     * @return array{km_estimate: int, per_day: float|null, estimated: bool, basis: array{from: array{date: string, km: int}|null, to: array{date: string, km: int}}}|null
     */
    public function forecastFor(int $vehicleId, Actor $actor): ?array
    {
        $readings = $this->odometer->validReadings($vehicleId, $actor);
        $firstRegistration = $this->vehicles->currentValues([$vehicleId], ['erstzulassung'], $actor)[$vehicleId]['erstzulassung'] ?? null;
        $forecast = OdometerForecast::compute($readings, $firstRegistration, $this->tasks->utcToday());
        if ($forecast === null) {
            return null;
        }
        $forecast['per_day'] = $forecast['per_day'] === null ? null : round($forecast['per_day'], 1);

        return $forecast;
    }

    /**
     * Ohne Fahrzeug-ID zählen nur aktive Fahrzeuge: verkaufte und stillgelegte haben nichts mehr zu warten.
     *
     * @return list<array{id: int, garage_id: int, label: string, status: string}>
     */
    private function activeVehicles(Actor $actor): array
    {
        return array_values(array_filter(
            $this->vehicles->listForActor($actor),
            static fn (array $vehicle): bool => $vehicle['status'] === VehicleRepository::STATUS_ACTIVE
        ));
    }

    /**
     * @param list<array{id: int, label: string}> $vehicles
     * @return array{forecasts: array<int, array<string, mixed>|null>, items: list<array<string, mixed>>}
     */
    private function analyse(array $vehicles, string $today, Actor $actor): array
    {
        $vehicleIds = array_map(static fn (array $vehicle): int => $vehicle['id'], $vehicles);
        $firstRegistrations = $this->vehicles->currentValues($vehicleIds, ['erstzulassung'], $actor);
        $executions = $this->tasks->lastExecutions($vehicleIds, $actor);
        $tasksByVehicle = [];
        foreach ($this->tasks->listTasksWithRules($vehicleIds, $actor) as $task) {
            $tasksByVehicle[$task['vehicle_id']][] = $task;
        }

        $forecasts = [];
        $items = [];
        foreach ($vehicles as $vehicle) {
            $firstRegistration = $firstRegistrations[$vehicle['id']]['erstzulassung'] ?? null;
            $forecast = OdometerForecast::compute($this->odometer->validReadings($vehicle['id'], $actor), $firstRegistration, $today);
            $forecasts[$vehicle['id']] = $forecast;

            foreach ($tasksByVehicle[$vehicle['id']] ?? [] as $task) {
                $lastDone = $executions[$vehicle['id']][$task['task_key']] ?? null;
                $items[] = self::item($vehicle, $task, $lastDone, $firstRegistration, $forecast, $today);
            }
        }

        return ['forecasts' => $forecasts, 'items' => $items];
    }

    /**
     * @param array{id: int, label: string} $vehicle
     * @param array{task_key: string, title: string, rule: array<string, mixed>|null} $task
     * @param array{event_id: int, date: string, km: int|null}|null $lastDone
     * @param array<string, mixed>|null $forecast
     * @return array<string, mixed>
     */
    private static function item(array $vehicle, array $task, ?array $lastDone, ?string $firstRegistration, ?array $forecast, string $today): array
    {
        $rule = $task['rule'];

        return [
            'vehicle_id' => $vehicle['id'],
            'vehicle_label' => $vehicle['label'],
            'task_key' => $task['task_key'],
            'title' => $task['title'],
            'rule' => $rule === null ? null : [
                'interval_km' => $rule['interval_km'],
                'interval_months' => $rule['interval_months'],
                'first_km' => $rule['first_km'],
                'first_months' => $rule['first_months'],
                'mode' => $rule['mode'],
                'confidence' => $rule['confidence'],
                'source' => $rule['source'],
                'source_url' => $rule['source_url'],
            ],
            'last_done' => $lastDone,
        ] + DueCalculator::compute($rule, $lastDone, $firstRegistration, $forecast, $today);
    }

    /**
     * Überfällig, bald, unbekannt, ok; darin nach wirksamem Datum (ohne Datum zuletzt).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $items): array
    {
        usort($items, static fn (array $left, array $right): int => [self::STATUS_ORDER[$left['status']], $left['effective_date'] ?? self::NO_DATE_SORT_KEY, $left['vehicle_id'], $left['task_key']]
            <=> [self::STATUS_ORDER[$right['status']], $right['effective_date'] ?? self::NO_DATE_SORT_KEY, $right['vehicle_id'], $right['task_key']]);

        return $items;
    }
}
