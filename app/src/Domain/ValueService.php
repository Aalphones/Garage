<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/**
 * Wert eines Fahrzeugs beim Lesen (ADR-023): holt Bewertungen, Inserate, Kilometerstände und die Quellen der Wertfaktoren
 * und gibt sie an die reine Klasse `ValueCalculator`. Nichts davon wird gespeichert.
 */
final class ValueService
{
    public const NOTE = 'Angebotspreise sind keine Verkaufspreise.';
    public const SOURCE_CORRIDOR = 'corridor';
    public const SOURCE_VALUATION = 'valuation';

    private const VALUATIONS = 'valuations';
    private const LISTINGS = 'market_listings';
    private const PURCHASE_KIND = 'kaufpreis';
    private const TREAD_QUANTITY = 'profiltiefe';

    private VehicleAccess $access;
    private RecordRepository $records;
    private VehicleRepository $vehicles;
    private TaskRepository $tasks;
    private OdometerService $odometer;
    private DueService $due;
    private MeasurementService $measurements;
    private DamageService $damages;
    private EquipmentService $equipment;

    public function __construct(PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->records = new RecordRepository($pdo);
        $this->vehicles = new VehicleRepository($pdo);
        $this->tasks = new TaskRepository($pdo);
        $this->odometer = new OdometerService($pdo);
        $this->due = new DueService($pdo);
        $this->measurements = new MeasurementService($pdo);
        $this->damages = new DamageService($pdo);
        $this->equipment = new EquipmentService($pdo);
    }

    /**
     * Ankerwerte, Korridor von heute, Verlauf, Inserate mit Zählgrund und die acht Wertfaktoren.
     *
     * @return array{anchors: list<array<string, mixed>>, corridor: array<string, mixed>, history: list<array<string, mixed>>, listings: list<array<string, mixed>>, factors: list<array<string, string>>, note: string}
     */
    public function summary(int $vehicleId, Actor $actor): array
    {
        $this->access->readable($vehicleId, $actor);
        $today = $this->tasks->utcToday();
        $facts = $this->vehicles->currentValues([$vehicleId], ['erstzulassung', 'unfallfrei', 'halterzahl'], $actor)[$vehicleId] ?? [];
        $firstRegistration = $facts['erstzulassung'] ?? null;
        $readings = $this->odometer->validReadings($vehicleId, $actor);
        $own = ValueCalculator::ownAt($readings, $firstRegistration, $today);

        $anchors = $this->anchors($vehicleId, $actor);
        $listingRows = $this->records->listForVehicle(self::LISTINGS, $vehicleId, $actor);
        $listingInputs = array_map(static fn (array $row): array => self::listingInput($row), $listingRows);

        $listings = [];
        foreach ($listingRows as $index => $row) {
            $reason = ValueCalculator::reasonNotCounting($listingInputs[$index], $own, $today);
            $listings[] = self::listing($row) + ['counts' => $reason === null, 'reason_not_counting' => $reason];
        }

        return [
            'anchors' => $anchors,
            'corridor' => ValueCalculator::corridor($listingInputs, $own, $today),
            'history' => ValueCalculator::history($listingInputs, $readings, $firstRegistration, $anchors, $today),
            'listings' => $listings,
            'factors' => ValueCalculator::factors($this->factorInput($vehicleId, $facts, $actor), $today),
            'note' => self::NOTE,
        ];
    }

    /**
     * Korridor von heute für Listen (Fahrzeugliste, `list_vehicles`); `null`, solange zu wenige Inserate zählen.
     *
     * @return array{low_cents: int, high_cents: int, median_cents: int, listings: int}|null
     */
    public function corridorToday(int $vehicleId, Actor $actor): ?array
    {
        $this->access->readable($vehicleId, $actor);
        $today = $this->tasks->utcToday();
        $firstRegistration = $this->vehicles->currentValues([$vehicleId], ['erstzulassung'], $actor)[$vehicleId]['erstzulassung'] ?? null;
        $own = ValueCalculator::ownAt($this->odometer->validReadings($vehicleId, $actor), $firstRegistration, $today);
        $listings = array_map(static fn (array $row): array => self::listingInput($row), $this->records->listForVehicle(self::LISTINGS, $vehicleId, $actor));
        $corridor = ValueCalculator::corridor($listings, $own, $today);
        if ($corridor['median_cents'] === null || $corridor['low_cents'] === null || $corridor['high_cents'] === null) {
            return null;
        }

        return [
            'low_cents' => $corridor['low_cents'],
            'high_cents' => $corridor['high_cents'],
            'median_cents' => $corridor['median_cents'],
            'listings' => $corridor['counting'],
        ];
    }

    /**
     * Jüngster Wert für die Kostenrechnung: der jüngere von Korridor-Mitte heute und jüngster Bewertung außer Kaufpreis;
     * am selben Tag gilt die Bewertung.
     *
     * @return array{amount_cents: int, on: string, source: string}|null
     */
    public function current(int $vehicleId, Actor $actor): ?array
    {
        $this->access->readable($vehicleId, $actor);
        $today = $this->tasks->utcToday();
        $firstRegistration = $this->vehicles->currentValues([$vehicleId], ['erstzulassung'], $actor)[$vehicleId]['erstzulassung'] ?? null;
        $own = ValueCalculator::ownAt($this->odometer->validReadings($vehicleId, $actor), $firstRegistration, $today);
        $listings = array_map(static fn (array $row): array => self::listingInput($row), $this->records->listForVehicle(self::LISTINGS, $vehicleId, $actor));
        $corridor = ValueCalculator::corridor($listings, $own, $today);

        $valuation = null;
        foreach ($this->anchors($vehicleId, $actor) as $anchor) {
            if ($anchor['kind'] !== self::PURCHASE_KIND) {
                $valuation = ['amount_cents' => $anchor['amount_cents'], 'on' => $anchor['valued_on'], 'source' => self::SOURCE_VALUATION];
                break;
            }
        }

        if ($corridor['median_cents'] === null) {
            return $valuation;
        }
        if ($valuation !== null && $valuation['on'] >= $today) {
            return $valuation;
        }

        return ['amount_cents' => $corridor['median_cents'], 'on' => $today, 'source' => self::SOURCE_CORRIDOR];
    }

    /**
     * Jüngste Bewertung der Art Kaufpreis.
     *
     * @return array{amount_cents: int, on: string}|null
     */
    public function purchase(int $vehicleId, Actor $actor): ?array
    {
        $this->access->readable($vehicleId, $actor);
        foreach ($this->anchors($vehicleId, $actor) as $anchor) {
            if ($anchor['kind'] === self::PURCHASE_KIND) {
                return ['amount_cents' => $anchor['amount_cents'], 'on' => $anchor['valued_on']];
            }
        }

        return null;
    }

    /**
     * Ob ein Inserat heute für den Korridor dieses Fahrzeugs zählt.
     *
     * @param array{price_cents: int, odometer_km: int, first_registration: string, same_drivetrain: bool, fetched_on: string} $listing
     */
    public function countsToday(int $vehicleId, array $listing, Actor $actor): bool
    {
        $today = $this->tasks->utcToday();
        $firstRegistration = $this->vehicles->currentValues([$vehicleId], ['erstzulassung'], $actor)[$vehicleId]['erstzulassung'] ?? null;
        $own = ValueCalculator::ownAt($this->odometer->validReadings($vehicleId, $actor), $firstRegistration, $today);

        return ValueCalculator::reasonNotCounting($listing, $own, $today) === null;
    }

    /**
     * @param array<string, mixed> $row Zeile aus `market_listings`
     * @return array{price_cents: int, odometer_km: int, first_registration: string, same_drivetrain: bool, fetched_on: string}
     */
    public static function listingInput(array $row): array
    {
        return [
            'price_cents' => (int) $row['price_cents'],
            'odometer_km' => (int) $row['odometer_km'],
            'first_registration' => (string) $row['first_registration'],
            'same_drivetrain' => (int) $row['same_drivetrain'] === 1,
            'fetched_on' => (string) $row['fetched_on'],
        ];
    }

    /**
     * Bewertungen, jüngste zuerst.
     *
     * @return list<array{id: int, valued_on: string, kind: string, value_type: string|null, amount_cents: int, range_low_cents: int|null, range_high_cents: int|null, odometer_km: int|null, provider: string|null, note: string|null, source: string, source_url: string|null, confidence: string}>
     */
    private function anchors(int $vehicleId, Actor $actor): array
    {
        $anchors = [];
        foreach ($this->records->listForVehicle(self::VALUATIONS, $vehicleId, $actor) as $row) {
            $anchors[] = [
                'id' => (int) $row['id'],
                'valued_on' => (string) $row['valued_on'],
                'kind' => (string) $row['kind'],
                'value_type' => $row['value_type'] === null ? null : (string) $row['value_type'],
                'amount_cents' => (int) $row['amount_cents'],
                'range_low_cents' => $row['range_low_cents'] === null ? null : (int) $row['range_low_cents'],
                'range_high_cents' => $row['range_high_cents'] === null ? null : (int) $row['range_high_cents'],
                'odometer_km' => $row['odometer_km'] === null ? null : (int) $row['odometer_km'],
                'provider' => (string) $row['provider'] === '' ? null : (string) $row['provider'],
                'note' => $row['note'] === null ? null : (string) $row['note'],
                'source' => (string) $row['source'],
                'source_url' => $row['source_url'] === null ? null : (string) $row['source_url'],
                'confidence' => (string) $row['confidence'],
            ];
        }

        return $anchors;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function listing(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'url' => (string) $row['url'],
            'fetched_on' => (string) $row['fetched_on'],
            'price_cents' => (int) $row['price_cents'],
            'odometer_km' => (int) $row['odometer_km'],
            'first_registration' => (string) $row['first_registration'],
            'same_drivetrain' => (int) $row['same_drivetrain'] === 1,
            'equipment_note' => $row['equipment_note'] === null ? null : (string) $row['equipment_note'],
            'postal_code' => $row['postal_code'] === null ? null : (string) $row['postal_code'],
            'source' => (string) $row['source'],
            'confidence' => (string) $row['confidence'],
        ];
    }

    /**
     * @param array<string, string|null> $facts
     * @return array<string, mixed>
     */
    private function factorInput(int $vehicleId, array $facts, Actor $actor): array
    {
        $treadDepths = [];
        foreach ($this->measurements->series($vehicleId, self::TREAD_QUANTITY, $actor)['series'] as $series) {
            foreach ($series['points'] as $point) {
                $treadDepths[] = ['date' => $point['date'], 'value' => $point['value']];
            }
        }
        $holders = $facts['halterzahl'] ?? null;

        return [
            'gaps' => $this->due->gaps($vehicleId, $actor),
            'due_items' => $this->due->dueItems($vehicleId, DueService::MAX_HORIZON_MONTHS, $actor)['items'],
            'unfallfrei' => $facts['unfallfrei'] ?? null,
            'halterzahl' => $holders === null ? null : (int) $holders,
            'tread_depths' => $treadDepths,
            'recalls' => $this->damages->listRecalls($vehicleId, $actor),
            'equipment_count' => count($this->equipment->list($vehicleId, $actor)),
        ];
    }
}
