<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Garage\Db\Transaction;
use PDO;

/**
 * Verträge mit Beitragsperioden, Umlage-Anteilen und Vorgängen (ADR-022). Ein Vertrag gehört zu einem Fahrzeug oder zur
 * ganzen Garage; der Abgleich läuft über `ContractMerger`.
 */
final class ContractService
{
    public const KINDS = ['versicherung', 'steuer', 'club', 'stellplatz', 'finanzierung', 'sonstiges'];
    public const PAYMENT_INTERVALS = ['jaehrlich', 'halbjaehrlich', 'vierteljaehrlich', 'monatlich'];
    public const ALLOCATION_EQUAL = 'gleich';
    public const ALLOCATION_KM = 'km';
    public const ALLOCATION_FIXED = 'fest';
    public const ALLOCATIONS = [self::ALLOCATION_EQUAL, self::ALLOCATION_KM, self::ALLOCATION_FIXED];
    public const EVENT_KINDS = ['beitragsrechnung', 'aenderung', 'schadenmeldung', 'regulierung', 'rueckstufung', 'kuendigung'];
    public const EVENT_STATUSES = ['offen', 'in_bearbeitung', 'erledigt'];
    public const LEVEL_VEHICLE = 'fahrzeug';
    public const LEVEL_GARAGE = 'garage';

    private const CONTRACTS = 'contracts';
    private const TERMS = 'contract_terms';
    private const ALLOCATION_TABLE = 'contract_allocations';
    private const EVENTS = 'contract_events';
    private const VEHICLE_ACTIVE = 'aktiv';
    private const LOCAL_TIMEZONE = 'Europe/Berlin';
    private const FULL_SHARE_HUNDREDTHS = 10000;
    private const DAYS_PER_YEAR = 365;

    private const CONTRACT_EXAMPLE = '{"vehicle_id": 12, "kind": "versicherung", "provider": "HUK-COBURG", "contract_number": "KH-4711", "main_due": "01-01", "notice_months": 1, "payment_interval": "jaehrlich"}';
    private const GARAGE_EXAMPLE = '{"garage_id": 3, "kind": "club", "provider": "ADAC", "member_number": "123456789", "allocation": "gleich", "hotline": "+49 89 20 20 4000"}';
    private const TERM_EXAMPLE = '{"contract_id": 4, "valid_from": "2026-01-01", "valid_to": "2026-12-31", "amount_eur": "612.40", "sf_liability": "SF 12"}';
    private const ALLOCATIONS_EXAMPLE = '{"allocations": [{"vehicle_id": 12, "share_percent": 60}, {"vehicle_id": 13, "share_percent": 40}]}';
    private const EVENT_EXAMPLE = '{"contract_id": 4, "kind": "schadenmeldung", "occurred_on": "2026-03-14", "claim_number": "S-2026-0815", "damage_id": 7}';

    private VehicleAccess $access;
    private VehicleRepository $vehicles;
    private ContractRepository $contracts;
    private ContractMerger $merger;
    private RecordRepository $records;

    public function __construct(private readonly PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->vehicles = new VehicleRepository($pdo);
        $this->contracts = new ContractRepository($pdo);
        $this->merger = new ContractMerger($pdo);
        $this->records = new RecordRepository($pdo);
    }

    /**
     * Art, Anbieter und Vertragsnummer erkennen denselben Vertrag am Fahrzeug bzw. in der Garage wieder.
     *
     * @param array<string, mixed> $input vehicle_id oder garage_id, kind, provider, contract_number?, …, source, source_url?, confidence
     * @return array{contract_id: int, result: string, open_item_ids: list<int>}
     */
    public function upsert(array $input, Actor $actor): array
    {
        $hasVehicle = isset($input['vehicle_id']);
        $isGarageContract = isset($input['garage_id']);
        if ($hasVehicle === $isGarageContract) {
            throw Validate::invalid('vehicle_id', 'Ein Vertrag gehört entweder zu einem Fahrzeug (vehicle_id) oder zur ganzen Garage (garage_id) — genau eins von beiden.', self::GARAGE_EXAMPLE);
        }
        $values = self::contractValues($input, $isGarageContract);
        $provenance = Provenance::validate($input, 'contract');

        return Transaction::run($this->pdo, function () use ($input, $isGarageContract, $values, $provenance, $actor): array {
            $scope = $isGarageContract ? $this->garageScope((int) $input['garage_id'], $actor) : $this->vehicleScope((int) $input['vehicle_id'], $actor);
            $values['vehicle_id'] = $scope['vehicle_id'] === null ? null : (string) $scope['vehicle_id'];
            $defaults = $isGarageContract ? ['allocation' => self::ALLOCATION_EQUAL] : [];
            $merged = $this->merger->merge(self::CONTRACTS, $values, $provenance, $scope, $actor, $defaults);

            return ['contract_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
        });
    }

    /**
     * Beitragsperiode; Vertrag + Beginn erkennen dieselbe Periode wieder. Bei Garagen-Verträgen mit Umlage `fest` schreiben
     * `allocations` die Anteile ab dem Beginn der Periode.
     *
     * @param array<string, mixed> $input contract_id, valid_from, valid_to, amount_eur, …, allocations?, source, source_url?, confidence
     * @return array{term_id: int, result: string, open_item_ids: list<int>}
     */
    public function recordTerm(array $input, Actor $actor): array
    {
        $values = self::termValues($input);
        $allocations = self::allocationValues($input['allocations'] ?? null);
        $provenance = Provenance::validate($input, 'contract_term');

        return Transaction::run($this->pdo, function () use ($input, $values, $allocations, $provenance, $actor): array {
            [$contract, $scope] = $this->lockedContract((int) ($input['contract_id'] ?? 0), $actor);
            $values['contract_id'] = (string) $contract['id'];
            if ($allocations !== null) {
                $this->assertAllocationsFit($contract, $allocations, $actor);
            }

            $term = $this->merger->merge(self::TERMS, $values, $provenance, $scope, $actor);
            $results = [];
            $openItemIds = $term['open_item_ids'];
            foreach ($allocations ?? [] as $allocation) {
                $merged = $this->merger->merge(
                    self::ALLOCATION_TABLE,
                    ['contract_id' => (string) $contract['id'], 'vehicle_id' => $allocation['vehicle_id'], 'valid_from' => $values['valid_from'], 'share_percent' => $allocation['share_percent']],
                    $provenance,
                    $scope,
                    $actor
                );
                $results[] = $merged['result'];
                $openItemIds = [...$openItemIds, ...$merged['open_item_ids']];
            }

            return ['term_id' => $term['id'], 'result' => self::combinedResult($term['result'], $results), 'open_item_ids' => $openItemIds];
        });
    }

    /**
     * Vorgang am Vertrag; Vertrag + Art + Datum erkennen denselben Vorgang wieder.
     *
     * @param array<string, mixed> $input contract_id, kind, occurred_on, amount_eur?, event_status?, claim_number?, damage_id?, note?, source, source_url?, confidence
     * @return array{contract_event_id: int, result: string, open_item_ids: list<int>}
     */
    public function recordEvent(array $input, Actor $actor): array
    {
        $values = self::eventValues($input);
        $provenance = Provenance::validate($input, 'contract_event');

        return Transaction::run($this->pdo, function () use ($input, $values, $provenance, $actor): array {
            [$contract, $scope] = $this->lockedContract((int) ($input['contract_id'] ?? 0), $actor);
            $values['contract_id'] = (string) $contract['id'];
            if ($values['damage_id'] !== null) {
                $this->assertDamageFits($contract, (int) $values['damage_id'], $actor);
            }
            $merged = $this->merger->merge(self::EVENTS, $values, $provenance, $scope, $actor);

            return ['contract_event_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
        });
    }

    /**
     * Verträge, die für ein Fahrzeug gelten (eigene zuerst, dann die der Garage), je mit Perioden und Vorgängen, neueste
     * zuerst; Garagen-Verträge mit dem heutigen Anteil dieses Fahrzeugs.
     *
     * @return list<array<string, mixed>>
     */
    public function forVehicle(int $vehicleId, Actor $actor): array
    {
        $vehicle = $this->access->readable($vehicleId, $actor);
        $rows = $this->contracts->forVehicle($vehicleId, $vehicle['garage_id'], $actor);
        $contractIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $terms = self::groupByContract($this->contracts->childrenOf(self::TERMS, $contractIds, $actor));
        $events = self::groupByContract($this->contracts->childrenOf(self::EVENTS, $contractIds, $actor));
        $today = self::today();

        $result = [];
        foreach ($rows as $row) {
            $contractId = (int) $row['id'];
            $contractTerms = $terms[$contractId] ?? [];
            $formatted = self::formatContract($row) + [
                'terms' => array_map(self::formatTerm(...), $contractTerms),
                'events' => array_map(self::formatEvent(...), $events[$contractId] ?? []),
            ];
            if ($row['vehicle_id'] === null) {
                $percent = $this->shareFor($row, $vehicleId, $today, $actor);
                $formatted['share'] = [
                    'method' => (string) $row['allocation'],
                    'percent' => $percent,
                    'eur_per_year' => self::shareEurPerYear($contractTerms, $percent, $today),
                ];
            }
            $result[] = $formatted;
        }

        return $result;
    }

    /**
     * Anteil eines Fahrzeugs an einem Vertrag am Tag `$date` in Prozent. `gleich`: 100 ÷ heute aktive Autos der Garage;
     * `fest`: Anteil aus dem jüngsten Satz `contract_allocations` mit Beginn bis `$date` (nicht genannt = 0), ohne Satz
     * `null`; `km`: `null` — das rechnet die Kostenrechnung aus den Kilometern des Monats.
     *
     * @param array<string, mixed> $contract Zeile aus `contracts`
     */
    public function shareFor(array $contract, int $vehicleId, string $date, Actor $actor): ?float
    {
        if ($contract['vehicle_id'] !== null) {
            return (int) $contract['vehicle_id'] === $vehicleId ? 100.0 : 0.0;
        }

        return match ($contract['allocation']) {
            self::ALLOCATION_KM => null,
            self::ALLOCATION_FIXED => $this->fixedShare((int) $contract['id'], $vehicleId, $date, $actor),
            default => $this->equalShare((int) $contract['garage_id'], $vehicleId, $actor),
        };
    }

    private function equalShare(int $garageId, int $vehicleId, Actor $actor): float
    {
        $vehicle = $this->vehicles->find($vehicleId, $actor);
        if ($vehicle === null || $vehicle['garage_id'] !== $garageId || $vehicle['status'] !== self::VEHICLE_ACTIVE) {
            return 0.0;
        }
        $activeVehicles = $this->contracts->countActiveVehicles($garageId, $actor);
        if ($activeVehicles === 0) {
            return 0.0;
        }

        return round(100 / $activeVehicles, 2);
    }

    private function fixedShare(int $contractId, int $vehicleId, string $date, Actor $actor): ?float
    {
        $valid = array_values(array_filter(
            $this->contracts->childrenOf(self::ALLOCATION_TABLE, [$contractId], $actor),
            static fn (array $row): bool => (string) $row['valid_from'] <= $date
        ));
        if ($valid === []) {
            return null;
        }
        // Sortiert nach Beginn absteigend: der erste Eintrag nennt den gültigen Satz.
        $currentSet = (string) $valid[0]['valid_from'];
        foreach ($valid as $row) {
            if ((string) $row['valid_from'] === $currentSet && (int) $row['vehicle_id'] === $vehicleId) {
                return (float) $row['share_percent'];
            }
        }

        return 0.0;
    }

    /**
     * Sperrt Fahrzeug bzw. Garage (Sperrreihenfolge wie überall: erst der Bereich, dann der Datensatz) und prüft das
     * Schreibrecht. Fremd und unbekannt sind beide `not_found`.
     *
     * @return array{0: array<string, mixed>, 1: array{garage_id: int, vehicle_id: int|null, label: string}}
     */
    private function lockedContract(int $contractId, Actor $actor): array
    {
        $known = $this->contracts->find($contractId, $actor);
        if ($known === null) {
            throw self::contractNotFound($contractId);
        }
        $scope = $known['vehicle_id'] === null
            ? $this->garageScope((int) $known['garage_id'], $actor)
            : $this->vehicleScope((int) $known['vehicle_id'], $actor);
        $contract = $this->contracts->find($contractId, $actor, true) ?? throw self::contractNotFound($contractId);

        return [$contract, $scope];
    }

    /** @return array{garage_id: int, vehicle_id: int, label: string} */
    private function vehicleScope(int $vehicleId, Actor $actor): array
    {
        $vehicle = $this->access->writable($vehicleId, $actor);

        return ['garage_id' => $vehicle['garage_id'], 'vehicle_id' => $vehicle['id'], 'label' => sprintf('am Fahrzeug „%s“', $vehicle['label'])];
    }

    /** @return array{garage_id: int, vehicle_id: null, label: string} */
    private function garageScope(int $garageId, Actor $actor): array
    {
        $garage = $this->contracts->lockGarage($garageId, $actor);
        if ($garage === null) {
            throw new DomainError('not_found', 'Eine Garage mit dieser ID gibt es bei dir nicht. list_vehicles nennt deine Garagen.', ['garage_id' => $garageId]);
        }
        $this->access->assertCanWriteGarage($garageId, $actor);

        return ['garage_id' => $garageId, 'vehicle_id' => null, 'label' => sprintf('in der Garage „%s“', $garage['name'])];
    }

    /**
     * @param array<string, mixed> $contract
     * @param list<array{vehicle_id: string, share_percent: string}> $allocations
     */
    private function assertAllocationsFit(array $contract, array $allocations, Actor $actor): void
    {
        if ($contract['vehicle_id'] !== null || $contract['allocation'] !== self::ALLOCATION_FIXED) {
            throw Validate::invalid('allocations', 'Feste Anteile gibt es nur bei einem Garagen-Vertrag mit allocation „fest“; upsert_contract setzt die Umlage.', '{"garage_id": 3, "kind": "club", "provider": "ADAC", "allocation": "fest"}');
        }
        foreach ($allocations as $allocation) {
            $vehicle = $this->vehicles->find((int) $allocation['vehicle_id'], $actor);
            if ($vehicle === null || $vehicle['garage_id'] !== (int) $contract['garage_id']) {
                throw new DomainError('not_found', 'Ein Fahrzeug aus allocations gibt es in der Garage dieses Vertrags nicht. list_vehicles zeigt die vorhandenen.', ['vehicle_id' => (int) $allocation['vehicle_id']]);
            }
        }
    }

    /**
     * Ein Fahrzeug-Vertrag nimmt nur Schäden seines Fahrzeugs, ein Garagen-Vertrag (Schutzbrief) Schäden jedes Fahrzeugs der Garage.
     *
     * @param array<string, mixed> $contract
     */
    private function assertDamageFits(array $contract, int $damageId, Actor $actor): void
    {
        $damage = $this->records->find('damages', $damageId, $actor);
        $isSameGarage = $damage !== null && (int) $damage['garage_id'] === (int) $contract['garage_id'];
        $isSameVehicle = $damage !== null && ($contract['vehicle_id'] === null || (int) $damage['vehicle_id'] === (int) $contract['vehicle_id']);
        if (!$isSameGarage || !$isSameVehicle) {
            throw Validate::invalid('damage_id', 'Der Schaden muss zum Fahrzeug des Vertrags gehören; get_damages nennt die IDs.', self::EVENT_EXAMPLE);
        }
    }

    /**
     * @param list<string> $allocationResults
     */
    private static function combinedResult(string $termResult, array $allocationResults): string
    {
        if (in_array(RecordMerger::RESULT_DISPUTE, $allocationResults, true)) {
            return RecordMerger::RESULT_DISPUTE;
        }
        if ($termResult !== RecordMerger::RESULT_UNCHANGED) {
            return $termResult;
        }
        foreach ($allocationResults as $result) {
            if ($result !== RecordMerger::RESULT_UNCHANGED) {
                return RecordMerger::RESULT_SUPPLEMENTED;
            }
        }

        return RecordMerger::RESULT_UNCHANGED;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private static function contractValues(array $input, bool $isGarageContract): array
    {
        $allocation = isset($input['allocation']) ? Validate::enum('allocation', $input['allocation'], self::ALLOCATIONS) : null;
        if ($allocation !== null && !$isGarageContract) {
            throw Validate::invalid('allocation', 'Eine Umlage gibt es nur bei Garagen-Verträgen (garage_id statt vehicle_id).', self::GARAGE_EXAMPLE);
        }
        $startsOn = Validate::optionalDate('starts_on', $input['starts_on'] ?? null, '{"starts_on": "2024-01-01"}');
        $endsOn = Validate::optionalDate('ends_on', $input['ends_on'] ?? null, '{"ends_on": "2026-12-31"}');
        if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) {
            throw Validate::invalid('ends_on', 'Das Ende liegt vor dem Beginn.', '{"starts_on": "2024-01-01", "ends_on": "2026-12-31"}');
        }

        return [
            'kind' => Validate::enum('kind', $input['kind'] ?? null, self::KINDS),
            'provider' => Validate::text('provider', $input['provider'] ?? null, 1, 120, self::CONTRACT_EXAMPLE),
            'contract_number' => Validate::optionalText('contract_number', $input['contract_number'] ?? null, 1, 60, '{"contract_number": "KH-4711"}') ?? '',
            'tariff' => Validate::optionalText('tariff', $input['tariff'] ?? null, 1, 120, '{"tariff": "Classic XL"}'),
            'starts_on' => $startsOn,
            'main_due' => self::mainDue($input['main_due'] ?? null),
            'notice_months' => Validate::optionalInt('notice_months', $input['notice_months'] ?? null, '{"notice_months": 1}', 0, 24),
            'payment_interval' => isset($input['payment_interval']) ? Validate::enum('payment_interval', $input['payment_interval'], self::PAYMENT_INTERVALS) : null,
            'ends_on' => $endsOn,
            'allocation' => $allocation,
            'hotline' => Validate::optionalText('hotline', $input['hotline'] ?? null, 1, 40, '{"hotline": "+49 89 20 20 4000"}'),
            'member_number' => Validate::optionalText('member_number', $input['member_number'] ?? null, 1, 60, '{"member_number": "123456789"}'),
            'note' => Validate::optionalText('note', $input['note'] ?? null, 1, 200, '{"note": "Rabattschutz eingeschlossen"}'),
        ];
    }

    /** Hauptfälligkeit als `MM-TT`; der 29. Februar ist erlaubt. */
    private static function mainDue(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $text = is_string($raw) ? trim($raw) : '';
        $isValid = preg_match('/^(\d{2})-(\d{2})$/', $text, $parts) === 1 && checkdate((int) $parts[1], (int) $parts[2], 2024);
        if (!$isValid) {
            throw Validate::invalid('main_due', 'Die Hauptfälligkeit hat die Form MM-TT (Monat-Tag, ohne Jahr).', '{"main_due": "01-01"}');
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private static function termValues(array $input): array
    {
        $validFrom = Validate::date('valid_from', $input['valid_from'] ?? null, self::TERM_EXAMPLE);
        $validTo = Validate::date('valid_to', $input['valid_to'] ?? null, self::TERM_EXAMPLE);
        if ($validTo < $validFrom) {
            throw Validate::invalid('valid_to', 'Das Ende der Periode liegt vor ihrem Beginn.', self::TERM_EXAMPLE);
        }
        $amount = Validate::euroToCents('amount_eur', $input['amount_eur'] ?? null) ?? throw Validate::invalid('amount_eur', 'Der Beitrag der Periode fehlt.', self::TERM_EXAMPLE);

        return [
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'amount_cents' => $amount,
            'coverage' => Validate::optionalText('coverage', $input['coverage'] ?? null, 1, 120, '{"coverage": "Vollkasko 300 € SB, Teilkasko 150 € SB"}'),
            'sf_liability' => Validate::optionalText('sf_liability', $input['sf_liability'] ?? null, 1, 10, '{"sf_liability": "SF 12"}'),
            'sf_comprehensive' => Validate::optionalText('sf_comprehensive', $input['sf_comprehensive'] ?? null, 1, 10, '{"sf_comprehensive": "SF 8"}'),
            'type_class_liability' => Validate::optionalInt('type_class_liability', $input['type_class_liability'] ?? null, '{"type_class_liability": 16}', 1, 99),
            'type_class_partial' => Validate::optionalInt('type_class_partial', $input['type_class_partial'] ?? null, '{"type_class_partial": 21}', 1, 99),
            'type_class_full' => Validate::optionalInt('type_class_full', $input['type_class_full'] ?? null, '{"type_class_full": 22}', 1, 99),
            'regional_class' => Validate::optionalInt('regional_class', $input['regional_class'] ?? null, '{"regional_class": 7}', 1, 99),
            'declared_km_per_year' => Validate::optionalInt('declared_km_per_year', $input['declared_km_per_year'] ?? null, '{"declared_km_per_year": 12000}', 0, 200000),
            'deductible_note' => Validate::optionalText('deductible_note', $input['deductible_note'] ?? null, 1, 120, '{"deductible_note": "VK 300 €, TK 150 €"}'),
        ];
    }

    /**
     * Anteile in Prozent mit höchstens zwei Nachkommastellen; zusammen genau 100, jedes Fahrzeug einmal.
     *
     * @return list<array{vehicle_id: string, share_percent: string}>|null
     */
    private static function allocationValues(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            throw Validate::invalid('allocations', 'Erwartet wird eine Liste aus vehicle_id und share_percent.', self::ALLOCATIONS_EXAMPLE);
        }

        $allocations = [];
        $totalHundredths = 0;
        foreach ($raw as $index => $item) {
            $field = sprintf('allocations[%d]', $index);
            if (!is_array($item)) {
                throw Validate::invalid($field, 'Erwartet wird ein Objekt mit vehicle_id und share_percent.', self::ALLOCATIONS_EXAMPLE);
            }
            $vehicleId = Validate::int($field . '.vehicle_id', $item['vehicle_id'] ?? null, self::ALLOCATIONS_EXAMPLE, 1);
            $share = Validate::decimal($field . '.share_percent', $item['share_percent'] ?? null, 2, self::ALLOCATIONS_EXAMPLE);
            $hundredths = (int) str_replace('.', '', $share);
            if ($hundredths <= 0 || $hundredths > self::FULL_SHARE_HUNDREDTHS) {
                throw Validate::invalid($field . '.share_percent', 'Ein Anteil liegt über 0 und höchstens bei 100.', self::ALLOCATIONS_EXAMPLE);
            }
            if (isset($allocations[$vehicleId])) {
                throw Validate::invalid($field . '.vehicle_id', 'Jedes Fahrzeug steht nur einmal in allocations.', self::ALLOCATIONS_EXAMPLE);
            }
            $allocations[$vehicleId] = ['vehicle_id' => $vehicleId, 'share_percent' => $share];
            $totalHundredths += $hundredths;
        }
        if ($totalHundredths !== self::FULL_SHARE_HUNDREDTHS) {
            throw Validate::invalid('allocations', sprintf('Die Anteile ergeben zusammen %s %% statt 100 %%.', number_format($totalHundredths / 100, 2, '.', '')), self::ALLOCATIONS_EXAMPLE);
        }

        return array_values($allocations);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private static function eventValues(array $input): array
    {
        return [
            'kind' => Validate::enum('kind', $input['kind'] ?? null, self::EVENT_KINDS),
            'occurred_on' => Validate::date('occurred_on', $input['occurred_on'] ?? null, self::EVENT_EXAMPLE),
            // Negativ: Erstattung, etwa nach einer Regulierung.
            'amount_cents' => Validate::euroToCents('amount_eur', $input['amount_eur'] ?? null, true),
            'event_status' => isset($input['event_status']) ? Validate::enum('event_status', $input['event_status'], self::EVENT_STATUSES) : null,
            'claim_number' => Validate::optionalText('claim_number', $input['claim_number'] ?? null, 1, 60, '{"claim_number": "S-2026-0815"}'),
            'damage_id' => Validate::optionalInt('damage_id', $input['damage_id'] ?? null, self::EVENT_EXAMPLE, 1),
            'note' => Validate::optionalText('note', $input['note'] ?? null, 1, 300, '{"note": "Rückstufung von SF 12 auf SF 9"}'),
        ];
    }

    /**
     * Euro pro Jahr für den Anteil: Beitrag der heute gültigen Periode (sonst der jüngsten) auf 365 Tage gerechnet.
     *
     * @param list<array<string, mixed>> $terms absteigend nach Beginn
     */
    private static function shareEurPerYear(array $terms, ?float $percent, string $today): ?string
    {
        if ($percent === null || $terms === []) {
            return null;
        }
        $term = $terms[0];
        foreach ($terms as $candidate) {
            if ((string) $candidate['valid_from'] <= $today && (string) $candidate['valid_to'] >= $today) {
                $term = $candidate;
                break;
            }
        }
        $days = (int) (new DateTimeImmutable((string) $term['valid_from']))->diff(new DateTimeImmutable((string) $term['valid_to']))->days + 1;
        $centsPerYear = (int) $term['amount_cents'] * self::DAYS_PER_YEAR / $days;

        return Money::centsToEuro((int) round($centsPerYear * $percent / 100));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, list<array<string, mixed>>>
     */
    private static function groupByContract(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['contract_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function formatContract(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'level' => $row['vehicle_id'] === null ? self::LEVEL_GARAGE : self::LEVEL_VEHICLE,
            'kind' => (string) $row['kind'],
            'provider' => (string) $row['provider'],
            'contract_number' => (string) $row['contract_number'] === '' ? null : (string) $row['contract_number'],
            'tariff' => self::nullableText($row['tariff']),
            'starts_on' => self::nullableText($row['starts_on']),
            'main_due' => self::nullableText($row['main_due']),
            'notice_months' => $row['notice_months'] === null ? null : (int) $row['notice_months'],
            'payment_interval' => self::nullableText($row['payment_interval']),
            'ends_on' => self::nullableText($row['ends_on']),
            'allocation' => self::nullableText($row['allocation']),
            'hotline' => self::nullableText($row['hotline']),
            'member_number' => self::nullableText($row['member_number']),
            'note' => self::nullableText($row['note']),
            'source' => (string) $row['source'],
            'confidence' => (string) $row['confidence'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function formatTerm(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'valid_from' => (string) $row['valid_from'],
            'valid_to' => (string) $row['valid_to'],
            'amount_eur' => Money::centsToEuro((int) $row['amount_cents']),
            'coverage' => self::nullableText($row['coverage']),
            'sf_liability' => self::nullableText($row['sf_liability']),
            'sf_comprehensive' => self::nullableText($row['sf_comprehensive']),
            'type_class_liability' => self::nullableInt($row['type_class_liability']),
            'type_class_partial' => self::nullableInt($row['type_class_partial']),
            'type_class_full' => self::nullableInt($row['type_class_full']),
            'regional_class' => self::nullableInt($row['regional_class']),
            'declared_km_per_year' => self::nullableInt($row['declared_km_per_year']),
            'deductible_note' => self::nullableText($row['deductible_note']),
            'source' => (string) $row['source'],
            'confidence' => (string) $row['confidence'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function formatEvent(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'occurred_on' => (string) $row['occurred_on'],
            'amount_eur' => $row['amount_cents'] === null ? null : Money::centsToEuro((int) $row['amount_cents']),
            'event_status' => (string) $row['event_status'],
            'claim_number' => self::nullableText($row['claim_number']),
            'damage_id' => self::nullableInt($row['damage_id']),
            'note' => self::nullableText($row['note']),
            'source' => (string) $row['source'],
            'confidence' => (string) $row['confidence'],
        ];
    }

    private static function nullableText(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TIMEZONE)))->format('Y-m-d');
    }

    private static function contractNotFound(int $contractId): DomainError
    {
        return new DomainError('not_found', 'Einen Vertrag mit dieser ID gibt es in deinen Garagen nicht. get_papers nennt die Verträge eines Fahrzeugs.', ['contract_id' => $contractId]);
    }
}
