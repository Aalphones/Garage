<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Kosten eines Fahrzeugs und einer Garage in vier Blöcken (ADR-022). Reine Rechnung ohne Datenbank und Uhr: Zeitraum,
 * Aktiv-Zeiträume, Kilometerstände und Posten kommen herein. Datumswerte sind `YYYY-MM-DD`, Beträge Cent.
 *
 * Ein Posten verteilt seinen Betrag tagesgenau über seine Periode (beide Enden inklusive); es zählen nur Tage im Zeitraum
 * und im Aktiv-Zeitraum des Fahrzeugs. Gerundet wird einmal je Posten (kaufmännisch); die Monatswerte eines Postens
 * verteilen den gerundeten Betrag nach größtem Rest, damit Monate und Summe zusammenpassen.
 *
 * Ein Posten: `{block, kind, title, amount_cents, from, to, ref: {table, id}, source, confidence, note}`.
 * Ein Fahrzeug der Flotte: `{vehicle_id, label, active: {from: ?string, to: string}|null, readings, per_day}`; `active`
 * `null` heißt, es zählt in keinem Zeitraum, `from` `null` heißt, es zählt ab Beginn des Zeitraums.
 */
final class CostCalculator
{
    public const BLOCK_FIX = 'fix';
    public const BLOCK_RUN = 'run';
    public const BLOCK_SHOP = 'shop';
    public const BLOCK_LOSS = 'loss';
    public const BLOCKS = [self::BLOCK_FIX, self::BLOCK_RUN, self::BLOCK_SHOP, self::BLOCK_LOSS];

    public const PERIOD_12_MONTHS = '12m';
    public const PERIOD_SINCE_PURCHASE = 'seit_kauf';
    public const PERIOD_YEAR_PREFIX = 'jahr:';

    public const GROUP_BLOCK = 'block';
    public const GROUP_MONTH = 'monat';
    public const GROUPS = [self::GROUP_BLOCK, self::GROUP_MONTH];

    public const KIND_INSURANCE = 'versicherung';
    public const KIND_TAX = 'steuer';
    public const KIND_FUEL = 'kraftstoff';
    public const KIND_LOSS = 'wertverlust';

    public const MISSING_INSURANCE = 'Versicherung';
    public const MISSING_TAX = 'Steuer';
    public const MISSING_FUEL = 'Kraftstoff';
    public const MISSING_LOSS = 'Wertverlust';

    private const DAYS_PER_MONTH = 30.4375;
    private const DAYS_PER_YEAR = 365;
    public const BLOCK_NAMES = [
        self::BLOCK_FIX => 'Fixkosten',
        self::BLOCK_RUN => 'Betrieb',
        self::BLOCK_SHOP => 'Werkstatt',
        self::BLOCK_LOSS => 'Wertverlust',
    ];
    private const ALLOCATION_NAMES = [
        ContractService::ALLOCATION_EQUAL => 'gleich verteilt',
        ContractService::ALLOCATION_KM => 'nach km',
        ContractService::ALLOCATION_FIXED => 'feste Anteile',
    ];

    /**
     * Zeitraum aus dem Schlüssel. `12m`: heute − 12 Monate + 1 Tag bis heute; `seit_kauf`: Beginn des Aktiv-Zeitraums bis
     * heute (ohne bekannten Beginn wie `12m`, mit Hinweis); `jahr:JJJJ`: 1.1.–31.12., die Zukunft gekappt auf heute.
     * Der Schlüssel ist geprüft (`CostService`).
     *
     * @return array{key: string, from: string, to: string, note: string|null}
     */
    public static function range(string $period, string $today, ?string $activeStart): array
    {
        if (str_starts_with($period, self::PERIOD_YEAR_PREFIX)) {
            $year = substr($period, strlen(self::PERIOD_YEAR_PREFIX));

            return ['key' => $period, 'from' => $year . '-01-01', 'to' => min($year . '-12-31', $today), 'note' => null];
        }
        if ($period === self::PERIOD_SINCE_PURCHASE && $activeStart !== null && $activeStart <= $today) {
            return ['key' => $period, 'from' => $activeStart, 'to' => $today, 'note' => null];
        }

        $note = null;
        if ($period === self::PERIOD_SINCE_PURCHASE) {
            $note = 'Kein Kaufdatum und keine Zulassung bekannt — gerechnet über die letzten 12 Monate.';
        }

        return ['key' => $period, 'from' => DueCalculator::addDays(DueCalculator::addMonths($today, -12), 1), 'to' => $today, 'note' => $note];
    }

    /**
     * Kosten eines Fahrzeugs im Zeitraum, mit seinen Anteilen an den Garagen-Verträgen.
     *
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @param array{vehicle_id: int, label: string, is_electric: bool, posts: list<array<string, mixed>>} $vehicle
     * @param array<int, array<string, mixed>> $fleet alle Fahrzeuge der Garage (auch dieses), Schlüssel Fahrzeug-ID
     * @param list<array<string, mixed>> $garageContracts Garagen-Verträge mit `terms` und `allocations`
     * @return array<string, mixed> Rohergebnis für `present`
     */
    public static function vehicle(array $range, array $vehicle, array $fleet, array $garageContracts): array
    {
        $vehicleId = $vehicle['vehicle_id'];
        $window = self::windowOf($fleet[$vehicleId] ?? ['active' => null], $range);
        $kmByMonth = self::kmByMonth($fleet, $range);

        $entries = [];
        foreach ($vehicle['posts'] as $post) {
            $entry = self::spread($post, $window);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        $shares = [];
        foreach ($garageContracts as $contract) {
            $contractCents = 0;
            $isCounted = false;
            foreach ($contract['terms'] as $term) {
                $entry = self::shareOfTerm($contract, $term, $vehicleId, $window, $fleet, $kmByMonth, $range);
                if ($entry === null) {
                    continue;
                }
                $entries[] = $entry;
                $contractCents += $entry['cents'];
                $isCounted = true;
            }
            if ($isCounted) {
                $shares[] = ['contract_id' => $contract['contract_id'], 'provider' => $contract['provider'], 'cents' => $contractCents];
            }
        }

        $days = $window === null ? 0 : DueCalculator::daysBetween($window['from'], $window['to']) + 1;
        $km = $window === null ? null : self::kmBetween($fleet[$vehicleId], $window['from'], $window['to']);

        return [
            'vehicle_id' => $vehicleId,
            'label' => $vehicle['label'],
            'active' => $window,
            'days' => $days,
            'km' => $km,
            'entries' => $entries,
            'shares' => $shares,
            'missing' => self::missing($vehicle, $range),
        ];
    }

    /**
     * Ausgabe eines Fahrzeugs: je Monat Cent je Block, Summen, pro Monat, pro km, fehlende Blöcke, Posten und Anteile.
     *
     * @param array<string, mixed> $result aus `vehicle`
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @return array<string, mixed>
     */
    public static function presentVehicle(array $result, array $range, string $group): array
    {
        $entries = $result['entries'];

        return [
            'scope' => 'fahrzeug',
            'vehicle_id' => $result['vehicle_id'],
            'label' => $result['label'],
            'period' => self::periodOut($range),
            'active' => $result['active'],
            'group' => $group,
        ] + self::figures($entries, $range, $result['days'], $result['km'], $result['missing']) + [
            'posts' => self::posts($entries, $group, false),
            'shares' => $result['shares'],
        ];
    }

    /**
     * Kosten pro km mit Wertverlust je Monat des Zeitraums: Fenster sind die 12 Monate bis zum Monatsende (im letzten
     * Monat bis zum Ende des Zeitraums); bei kürzerer Haltedauer beginnt es mit dem Aktiv-Zeitraum. Monate mit weniger
     * als zwei Monaten Haltedauer oder ohne gefahrene km fehlen — dort verzerrt der erste Posten den Wert.
     *
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @param array{vehicle_id: int, label: string, is_electric: bool, posts: list<array<string, mixed>>} $vehicle
     * @param array<int, array<string, mixed>> $fleet
     * @param list<array<string, mixed>> $garageContracts
     * @return list<array{month: string, from: string, to: string, full_cents: int, km: int, per_km_full_cents: string}>
     */
    public static function perKmSeries(array $range, array $vehicle, array $fleet, array $garageContracts): array
    {
        $ownWindow = self::windowOf($fleet[$vehicle['vehicle_id']] ?? ['active' => null], $range);
        if ($ownWindow === null) {
            return [];
        }

        $series = [];
        foreach (self::monthsOf($ownWindow['from'], $ownWindow['to']) as $month) {
            $end = min(self::monthEnd($month . '-01'), $ownWindow['to']);
            $rolling = ['key' => self::PERIOD_12_MONTHS, 'from' => DueCalculator::addDays(DueCalculator::addMonths($end, -12), 1), 'to' => $end, 'note' => null];
            $result = self::vehicle($rolling, $vehicle, $fleet, $garageContracts);
            $window = $result['active'];
            if ($window === null || $window['from'] > DueCalculator::addMonths($end, -2) || $result['km'] === null || $result['km'] <= 0) {
                continue;
            }
            $fullCents = (int) array_sum(array_column($result['entries'], 'cents'));
            $series[] = [
                'month' => $month,
                'from' => $window['from'],
                'to' => $window['to'],
                'full_cents' => $fullCents,
                'km' => $result['km'],
                'per_km_full_cents' => number_format($fullCents / 100 / $result['km'], 2, '.', ''),
            ];
        }

        return $series;
    }

    /**
     * Ausgabe der Garage: Summe der Fahrzeuge, die im Zeitraum aktiv waren (mit umgelegten Anteilen), dazu je Fahrzeug die
     * Monatswerte für das gestapelte Diagramm und die Garagen-Verträge mit dem Anteil von heute.
     *
     * @param list<array<string, mixed>> $results aus `vehicle`
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @param list<array<string, mixed>> $garageContracts
     * @param array<int, array<string, mixed>> $fleet
     * @return array<string, mixed>
     */
    public static function presentGarage(int $garageId, array $results, array $range, string $group, array $garageContracts, array $fleet, string $today): array
    {
        $entries = [];
        $missing = [];
        $km = null;
        $vehicles = [];
        foreach ($results as $result) {
            if ($result['active'] === null) {
                continue;
            }
            foreach ($result['entries'] as $entry) {
                $entries[] = $entry + ['vehicle_id' => $result['vehicle_id']];
            }
            $missing = array_merge($missing, $result['missing']);
            if ($result['km'] !== null) {
                $km = ($km ?? 0) + $result['km'];
            }
            $figures = self::figures($result['entries'], $range, $result['days'], $result['km'], $result['missing']);
            $vehicles[] = [
                'vehicle_id' => $result['vehicle_id'],
                'label' => $result['label'],
                'month_running_cents' => $figures['month_running_cents'],
                'month_full_cents' => $figures['month_full_cents'],
                'missing' => $result['missing'],
                'months' => array_map(
                    static fn (array $month): array => ['month' => $month['month'], 'running_cents' => $month['running_cents'], 'full_cents' => $month['full_cents']],
                    $figures['months']
                ),
            ];
        }
        $rangeDays = DueCalculator::daysBetween($range['from'], $range['to']) + 1;

        return [
            'scope' => 'garage',
            'garage_id' => $garageId,
            'period' => self::periodOut($range),
            'group' => $group,
        ] + self::figures($entries, $range, $rangeDays, $km, array_values(array_unique($missing))) + [
            'posts' => self::posts($entries, $group, true),
            'vehicles' => $vehicles,
            'garage_contracts' => self::garageContractsToday($garageContracts, $fleet, $range, $today),
        ];
    }

    /**
     * Fahrzeug-Posten (Vertragsperiode, Plakette, HU, Tankung, Ausgabe, Wertverlust …) auf die gezählten Tage verteilen.
     *
     * @param array<string, mixed> $post
     * @param array{from: string, to: string}|null $window Zeitraum ∩ Aktiv-Zeitraum
     * @return array<string, mixed>|null `null`, wenn kein Tag des Postens zählt
     */
    private static function spread(array $post, ?array $window): ?array
    {
        $counted = self::overlap(['from' => $post['from'], 'to' => $post['to']], $window);
        if ($counted === null) {
            return null;
        }
        $totalDays = DueCalculator::daysBetween($post['from'], $post['to']) + 1;
        $weights = self::daysPerMonth($counted['from'], $counted['to']);
        $days = (int) array_sum($weights);
        $cents = self::divideRounded($post['amount_cents'] * $days, $totalDays);

        $amount = self::euro($post['amount_cents']);
        if ($post['from'] === $post['to']) {
            $explanation = sprintf('%s am %s: %s', $post['title'], self::date($post['from']), $amount);
        } else {
            $explanation = sprintf(
                '%s %s für %s–%s, davon %d %s im Zeitraum = %s',
                $post['title'],
                $amount,
                self::date($post['from']),
                self::date($post['to']),
                $days,
                $days === 1 ? 'Tag' : 'Tage',
                self::euro($cents)
            );
        }
        if ($post['note'] !== null) {
            $explanation .= ' (' . $post['note'] . ')';
        }

        return self::entry($post, $cents, $weights, $explanation . '.');
    }

    /**
     * Anteil eines Fahrzeugs an einer Periode eines Garagen-Vertrags: Tagesbetrag × Anteil am Tag, summiert über die Tage,
     * an denen das Fahrzeug im Zeitraum aktiv ist.
     *
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $term
     * @param array{from: string, to: string}|null $window
     * @param array<int, array<string, mixed>> $fleet
     * @param array<int, array<string, int>> $kmByMonth
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @return array<string, mixed>|null
     */
    private static function shareOfTerm(array $contract, array $term, int $vehicleId, ?array $window, array $fleet, array $kmByMonth, array $range): ?array
    {
        $counted = self::overlap(['from' => $term['from'], 'to' => $term['to']], $window);
        if ($counted === null) {
            return null;
        }

        $weights = [];
        $days = 0;
        $hasNoFixedSet = false;
        $day = self::parse($counted['from']);
        $last = self::parse($counted['to']);
        while ($day <= $last) {
            $date = $day->format('Y-m-d');
            $month = substr($date, 0, 7);
            $share = self::shareOn($contract, $vehicleId, $date, $fleet, $kmByMonth[$month] ?? [], $range);
            if ($share === null) {
                $hasNoFixedSet = true;
                $share = 0.0;
            }
            $weights[$month] = ($weights[$month] ?? 0.0) + $share;
            $days++;
            $day = $day->modify('+1 day');
        }

        $totalDays = DueCalculator::daysBetween($term['from'], $term['to']) + 1;
        $weightSum = array_sum($weights);
        $cents = (int) round($term['amount_cents'] * $weightSum / $totalDays);
        $title = $contract['title'];
        $explanation = sprintf(
            '%s %s für %s–%s, Umlage %s: im Schnitt %s %% an %d %s im Zeitraum = %s',
            $title,
            self::euro($term['amount_cents']),
            self::date($term['from']),
            self::date($term['to']),
            self::ALLOCATION_NAMES[$contract['allocation']] ?? self::ALLOCATION_NAMES[ContractService::ALLOCATION_EQUAL],
            number_format($weightSum / $days * 100, 1, ',', '.'),
            $days,
            $days === 1 ? 'Tag' : 'Tagen',
            self::euro($cents)
        );
        if ($hasNoFixedSet) {
            $explanation .= ' (für einen Teil der Tage sind keine festen Anteile hinterlegt, dort 0 %)';
        }

        $post = [
            'block' => self::BLOCK_FIX,
            'kind' => $contract['kind'],
            'title' => $title,
            'from' => $term['from'],
            'to' => $term['to'],
            'ref' => ['table' => 'contract_terms', 'id' => $term['id']],
            'source' => $term['source'],
            'confidence' => $term['confidence'],
            'contract_id' => $contract['contract_id'],
        ];

        return self::entry($post, $cents, $weights, $explanation . '.');
    }

    /**
     * Anteil (0–1) eines aktiven Fahrzeugs an einem Tag; `null`, wenn bei festen Anteilen kein Satz gilt.
     *
     * @param array<string, mixed> $contract
     * @param array<int, array<string, mixed>> $fleet
     * @param array<int, int> $kmInMonth Fahrzeug-ID → km im Monat des Tages
     * @param array{key: string, from: string, to: string, note: string|null} $range
     */
    private static function shareOn(array $contract, int $vehicleId, string $date, array $fleet, array $kmInMonth, array $range): ?float
    {
        $activeIds = [];
        foreach ($fleet as $id => $member) {
            $window = self::windowOf($member, $range);
            if ($window !== null && $window['from'] <= $date && $date <= $window['to']) {
                $activeIds[] = $id;
            }
        }
        if (!in_array($vehicleId, $activeIds, true)) {
            return 0.0;
        }

        if ($contract['allocation'] === ContractService::ALLOCATION_FIXED) {
            return self::fixedShare($contract['allocations'], $vehicleId, $date);
        }
        if ($contract['allocation'] === ContractService::ALLOCATION_KM) {
            $totalKm = 0;
            foreach ($activeIds as $id) {
                $totalKm += $kmInMonth[$id] ?? 0;
            }
            if ($totalKm > 0) {
                return ($kmInMonth[$vehicleId] ?? 0) / $totalKm;
            }
        }

        return 1 / count($activeIds);
    }

    /**
     * Fester Anteil aus dem Satz mit dem jüngsten Beginn bis `$date` (wie `ContractService::shareFor`); ein im Satz nicht
     * genanntes Fahrzeug hat 0 %.
     *
     * @param list<array{vehicle_id: int, share_percent: float, valid_from: string}> $allocations
     */
    private static function fixedShare(array $allocations, int $vehicleId, string $date): ?float
    {
        $currentSet = null;
        foreach ($allocations as $allocation) {
            if ($allocation['valid_from'] <= $date && ($currentSet === null || $allocation['valid_from'] > $currentSet)) {
                $currentSet = $allocation['valid_from'];
            }
        }
        if ($currentSet === null) {
            return null;
        }
        foreach ($allocations as $allocation) {
            if ($allocation['valid_from'] === $currentSet && $allocation['vehicle_id'] === $vehicleId) {
                return $allocation['share_percent'] / 100;
            }
        }

        return 0.0;
    }

    /**
     * Garagen-Verträge für die Garagen-Ansicht: Betrag/Jahr aus der heute gültigen Periode (auf 365 Tage umgerechnet) und
     * der Anteil jedes heute aktiven Fahrzeugs. Bei `km` zählen die km im gewählten Zeitraum.
     *
     * @param list<array<string, mixed>> $garageContracts
     * @param array<int, array<string, mixed>> $fleet
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @return list<array<string, mixed>>
     */
    private static function garageContractsToday(array $garageContracts, array $fleet, array $range, string $today): array
    {
        $activeToday = [];
        foreach ($fleet as $id => $member) {
            $active = $member['active'];
            if ($active !== null && ($active['from'] === null || $active['from'] <= $today) && $today <= $active['to']) {
                $activeToday[] = $id;
            }
        }

        $result = [];
        foreach ($garageContracts as $contract) {
            $amountYear = null;
            foreach ($contract['terms'] as $term) {
                if ($term['from'] <= $today && $today <= $term['to']) {
                    $amountYear = self::divideRounded($term['amount_cents'] * self::DAYS_PER_YEAR, DueCalculator::daysBetween($term['from'], $term['to']) + 1);
                    break;
                }
            }
            [$shares, $explanation] = self::sharesToday($contract, $activeToday, $fleet, $range, $today);
            $perVehicle = [];
            foreach ($shares as $vehicleId => $share) {
                $perVehicle[] = ['vehicle_id' => $vehicleId, 'year_cents' => $amountYear === null ? null : (int) round($amountYear * $share)];
            }
            if ($amountYear === null) {
                $explanation .= ' Heute gilt keine Beitragsperiode (record_contract_term).';
            }

            $result[] = [
                'contract_id' => $contract['contract_id'],
                'kind' => $contract['kind'],
                'provider' => $contract['provider'],
                'amount_year_cents' => $amountYear,
                'allocation' => $contract['allocation'],
                'explanation' => $explanation,
                'per_vehicle' => $perVehicle,
                'source' => $contract['source'],
                'confidence' => $contract['confidence'],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $contract
     * @param list<int> $activeToday
     * @param array<int, array<string, mixed>> $fleet
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @return array{0: array<int, float>, 1: string} Fahrzeug-ID → Anteil 0–1, Satz zur Umlage
     */
    private static function sharesToday(array $contract, array $activeToday, array $fleet, array $range, string $today): array
    {
        if ($activeToday === []) {
            return [[], 'Heute ist kein Auto der Garage aktiv.'];
        }

        if ($contract['allocation'] === ContractService::ALLOCATION_FIXED) {
            $shares = [];
            foreach ($activeToday as $vehicleId) {
                $share = self::fixedShare($contract['allocations'], $vehicleId, $today);
                if ($share === null) {
                    return [array_fill_keys($activeToday, 0.0), 'Feste Anteile: heute ist kein Satz hinterlegt (record_contract_term mit Anteilen), die Autos tragen nichts.'];
                }
                $shares[$vehicleId] = $share;
            }

            return [$shares, 'Feste Anteile laut Vertrag.'];
        }

        if ($contract['allocation'] === ContractService::ALLOCATION_KM) {
            $kms = [];
            foreach ($activeToday as $vehicleId) {
                $window = self::windowOf($fleet[$vehicleId], $range);
                $kms[$vehicleId] = $window === null ? 0 : (self::kmBetween($fleet[$vehicleId], $window['from'], $window['to']) ?? 0);
            }
            $totalKm = array_sum($kms);
            if ($totalKm > 0) {
                return [
                    array_map(static fn (int $km): float => $km / $totalKm, $kms),
                    sprintf('Nach km im Zeitraum %s–%s (zusammen %s km); umgelegt wird tagesgenau nach den km je Monat.', self::date($range['from']), self::date($range['to']), number_format($totalKm, 0, ',', '.')),
                ];
            }
        }

        $count = count($activeToday);

        return [
            array_fill_keys($activeToday, 1 / $count),
            sprintf('Gleich auf %d %s.', $count, $count === 1 ? 'aktives Auto' : 'aktive Autos'),
        ];
    }

    /**
     * Fehlende Blöcke: Versicherung und Steuer, wenn keine Periode eines Fahrzeug-Vertrags den Zeitraum schneidet (Steuer
     * entfällt beim Elektroauto); Kraftstoff ohne Tankung im Zeitraum; Wertverlust ohne Posten.
     *
     * @param array{is_electric: bool, posts: list<array<string, mixed>>} $vehicle
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @return list<string>
     */
    private static function missing(array $vehicle, array $range): array
    {
        $found = [];
        foreach ($vehicle['posts'] as $post) {
            if ($post['kind'] === self::KIND_LOSS) {
                $found[self::KIND_LOSS] = true;
                continue;
            }
            if ($post['from'] <= $range['to'] && $range['from'] <= $post['to']) {
                $found[$post['kind']] = true;
            }
        }

        $missing = [];
        if (!isset($found[self::KIND_INSURANCE])) {
            $missing[] = self::MISSING_INSURANCE;
        }
        if (!isset($found[self::KIND_TAX]) && !$vehicle['is_electric']) {
            $missing[] = self::MISSING_TAX;
        }
        if (!isset($found[self::KIND_FUEL])) {
            $missing[] = self::MISSING_FUEL;
        }
        if (!isset($found[self::KIND_LOSS])) {
            $missing[] = self::MISSING_LOSS;
        }

        return $missing;
    }

    /**
     * Monate, Blöcke, Summen und Kennzahlen aus den Posten.
     *
     * @param list<array<string, mixed>> $entries
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @param list<string> $missing
     * @return array<string, mixed>
     */
    private static function figures(array $entries, array $range, int $days, ?int $km, array $missing): array
    {
        $months = [];
        foreach (self::monthsOf($range['from'], $range['to']) as $month) {
            $months[$month] = array_fill_keys(self::BLOCKS, 0);
        }
        $blockCents = array_fill_keys(self::BLOCKS, 0);
        $blockCounts = array_fill_keys(self::BLOCKS, 0);
        foreach ($entries as $entry) {
            $blockCents[$entry['block']] += $entry['cents'];
            $blockCounts[$entry['block']]++;
            foreach ($entry['months'] as $month => $cents) {
                $months[$month][$entry['block']] += $cents;
            }
        }

        $monthRows = [];
        foreach ($months as $month => $cents) {
            $running = $cents[self::BLOCK_FIX] + $cents[self::BLOCK_RUN] + $cents[self::BLOCK_SHOP];
            $monthRows[] = ['month' => $month] + $cents + ['running_cents' => $running, 'full_cents' => $running + $cents[self::BLOCK_LOSS]];
        }

        $blocks = [];
        foreach (self::BLOCKS as $block) {
            $blocks[$block] = ['cents' => $blockCents[$block], 'explanation' => self::blockSentence($block, $blockCents[$block], $blockCounts[$block], $missing)];
        }

        $running = $blockCents[self::BLOCK_FIX] + $blockCents[self::BLOCK_RUN] + $blockCents[self::BLOCK_SHOP];
        $full = $running + $blockCents[self::BLOCK_LOSS];
        $monthCount = $days / self::DAYS_PER_MONTH;
        foreach ($blocks as $block => $figure) {
            $blocks[$block]['month_cents'] = $monthCount > 0 ? (int) round($figure['cents'] / $monthCount) : 0;
        }
        $perKm = null;
        if ($km !== null && $km > 0) {
            $perKm = number_format($full / 100 / $km, 2, '.', '');
        }

        return [
            'months' => $monthRows,
            'blocks' => $blocks,
            'running_cents' => $running,
            'full_cents' => $full,
            'month_running_cents' => $monthCount > 0 ? (int) round($running / $monthCount) : 0,
            'month_full_cents' => $monthCount > 0 ? (int) round($full / $monthCount) : 0,
            'per_km_full_cents' => $perKm,
            'km' => $km,
            'missing' => $missing,
            'explanation' => sprintf(
                'Pro Monat = Summe ÷ (%d Tage ÷ 30,4375); pro km = Summe mit Wertverlust ÷ %s km.',
                $days,
                $km === null ? 'unbekannte' : number_format($km, 0, ',', '.')
            ),
        ];
    }

    /** @param list<string> $missing */
    private static function blockSentence(string $block, int $cents, int $count, array $missing): string
    {
        $name = self::BLOCK_NAMES[$block];
        if ($block === self::BLOCK_LOSS && in_array(self::MISSING_LOSS, $missing, true)) {
            return 'Wertverlust fehlt: es braucht einen Kaufpreis (add_valuation mit kind kaufpreis) und einen jüngeren Wert (Bewertung oder Marktkorridor).';
        }
        if ($count === 0) {
            return sprintf('%s: keine Posten im Zeitraum.', $name);
        }

        return sprintf('%s: %d Posten im Zeitraum, zusammen %s.', $name, $count, self::euro($cents));
    }

    /**
     * Posten je Block oder je Monat; je Monat trägt ein Posten nur seinen Monatsbetrag.
     *
     * @param list<array<string, mixed>> $entries
     * @return array<string, list<array<string, mixed>>>
     */
    private static function posts(array $entries, string $group, bool $withVehicle): array
    {
        $posts = [];
        if ($group === self::GROUP_BLOCK) {
            $posts = array_fill_keys(self::BLOCKS, []);
        }
        foreach ($entries as $entry) {
            $out = [
                'title' => $entry['title'],
                'kind' => $entry['kind'],
                'cents' => $entry['cents'],
                'from' => $entry['from'],
                'to' => $entry['to'],
                'explanation' => $entry['explanation'],
                'ref' => $entry['ref'],
                'source' => $entry['source'],
                'confidence' => $entry['confidence'],
            ];
            if ($withVehicle) {
                $out['vehicle_id'] = $entry['vehicle_id'];
            }
            if ($group === self::GROUP_BLOCK) {
                $posts[$entry['block']][] = $out;
                continue;
            }
            foreach ($entry['months'] as $month => $cents) {
                if ($cents !== 0) {
                    $posts[$month][] = ['block' => $entry['block'], 'month_cents' => $cents] + $out;
                }
            }
        }
        if ($group === self::GROUP_MONTH) {
            ksort($posts);
        }

        return $posts;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, float|int> $weights Monat → gezählte Tage (bzw. Anteils-Tage)
     * @return array<string, mixed>
     */
    private static function entry(array $post, int $cents, array $weights, string $explanation): array
    {
        return [
            'block' => $post['block'],
            'kind' => $post['kind'],
            'title' => $post['title'],
            'from' => $post['from'],
            'to' => $post['to'],
            'cents' => $cents,
            'months' => self::allocate($cents, $weights),
            'explanation' => $explanation,
            'ref' => $post['ref'],
            'source' => $post['source'],
            'confidence' => $post['confidence'],
        ];
    }

    /**
     * Verteilt einen gerundeten Betrag nach größtem Rest auf die Monate, damit die Monate genau die Summe ergeben.
     *
     * @param array<string, float|int> $weights
     * @return array<string, int>
     */
    private static function allocate(int $cents, array $weights): array
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return array_map(static fn (float|int $weight): int => 0, $weights);
        }

        $shares = [];
        $remainders = [];
        foreach ($weights as $month => $weight) {
            $exact = $cents * $weight / $total;
            $floor = (int) floor($exact);
            $shares[$month] = $floor;
            $remainders[$month] = $exact - $floor;
        }
        arsort($remainders);
        $left = $cents - array_sum($shares);
        foreach (array_keys($remainders) as $month) {
            if ($left <= 0) {
                break;
            }
            $shares[$month]++;
            $left--;
        }

        return $shares;
    }

    /**
     * km je Kalendermonat des Zeitraums für jedes Fahrzeug der Flotte (Grundlage der Umlage nach km).
     *
     * @param array<int, array<string, mixed>> $fleet
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @return array<string, array<int, int>> Monat → Fahrzeug-ID → km
     */
    private static function kmByMonth(array $fleet, array $range): array
    {
        $result = [];
        foreach (self::monthsOf($range['from'], $range['to']) as $month) {
            $first = $month . '-01';
            $last = self::monthEnd($first);
            foreach ($fleet as $id => $member) {
                $result[$month][$id] = self::kmBetween($member, DueCalculator::addDays($first, -1), $last) ?? 0;
            }
        }

        return $result;
    }

    /**
     * km zwischen zwei Tagen: Stand am Ende − Stand am Anfang, nie negativ; ohne gültigen Stand `null`.
     *
     * @param array{readings: list<array{read_on: string, km: int}>, per_day: float|null} $member
     */
    private static function kmBetween(array $member, string $from, string $to): ?int
    {
        $start = self::kmAt($member['readings'], $member['per_day'], $from);
        $end = self::kmAt($member['readings'], $member['per_day'], $to);
        if ($start === null || $end === null) {
            return null;
        }

        return max(0, (int) round($end - $start));
    }

    /**
     * Stand an einem Tag: zwischen zwei gültigen Ständen linear, vor dem ersten der erste, nach dem letzten die Prognose
     * (letzter Stand + km pro Tag aus `OdometerForecast`).
     *
     * @param list<array{read_on: string, km: int}> $readings aufsteigend
     */
    private static function kmAt(array $readings, ?float $perDay, string $date): ?float
    {
        if ($readings === []) {
            return null;
        }
        $first = $readings[0];
        if ($date <= $first['read_on']) {
            return (float) $first['km'];
        }

        $previous = $first;
        foreach ($readings as $reading) {
            if ($reading['read_on'] < $date) {
                $previous = $reading;
                continue;
            }
            if ($reading['read_on'] === $date) {
                return (float) $reading['km'];
            }
            $span = DueCalculator::daysBetween($previous['read_on'], $reading['read_on']);

            return $previous['km'] + ($reading['km'] - $previous['km']) * DueCalculator::daysBetween($previous['read_on'], $date) / $span;
        }

        return $previous['km'] + ($perDay ?? 0.0) * DueCalculator::daysBetween($previous['read_on'], $date);
    }

    /**
     * Zeitraum ∩ Aktiv-Zeitraum eines Fahrzeugs; `null`, wenn es in keinem Tag des Zeitraums zählt.
     *
     * @param array{active: array{from: string|null, to: string}|null} $member
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @return array{from: string, to: string}|null
     */
    private static function windowOf(array $member, array $range): ?array
    {
        $active = $member['active'];
        if ($active === null) {
            return null;
        }

        return self::overlap(['from' => $active['from'] ?? $range['from'], 'to' => $active['to']], $range);
    }

    /**
     * @param array{from: string, to: string} $first
     * @param array{from: string, to: string}|null $second
     * @return array{from: string, to: string}|null
     */
    private static function overlap(array $first, ?array $second): ?array
    {
        if ($second === null) {
            return null;
        }
        $from = max($first['from'], $second['from']);
        $to = min($first['to'], $second['to']);

        return $from <= $to ? ['from' => $from, 'to' => $to] : null;
    }

    /** @return array<string, int> Monat `YYYY-MM` → Tage von `$from` bis `$to` (inklusive) in diesem Monat */
    private static function daysPerMonth(string $from, string $to): array
    {
        $result = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $segmentEnd = min(self::monthEnd($cursor), $to);
            $result[substr($cursor, 0, 7)] = DueCalculator::daysBetween($cursor, $segmentEnd) + 1;
            $cursor = DueCalculator::addDays($segmentEnd, 1);
        }

        return $result;
    }

    /** @return list<string> Kalendermonate `YYYY-MM`, die den Zeitraum schneiden */
    private static function monthsOf(string $from, string $to): array
    {
        return array_keys(self::daysPerMonth($from, $to));
    }

    private static function monthEnd(string $date): string
    {
        $firstOfMonth = substr($date, 0, 7) . '-01';

        return substr($date, 0, 7) . '-' . self::parse($firstOfMonth)->format('t');
    }

    /** Ganzzahlige Division, kaufmännisch gerundet (0,5 weg von null) — ohne Gleitkomma, damit der halbe Cent sicher fällt. */
    private static function divideRounded(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;
        $absoluteNumerator = abs($numerator);
        $absoluteDenominator = abs($denominator);

        return $sign * intdiv(2 * $absoluteNumerator + $absoluteDenominator, 2 * $absoluteDenominator);
    }

    /**
     * @param array{key: string, from: string, to: string, note: string|null} $range
     * @return array{key: string, from: string, to: string, days: int, note: string|null}
     */
    private static function periodOut(array $range): array
    {
        return [
            'key' => $range['key'],
            'from' => $range['from'],
            'to' => $range['to'],
            'days' => DueCalculator::daysBetween($range['from'], $range['to']) + 1,
            'note' => $range['note'],
        ];
    }

    private static function euro(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }

    private static function date(string $date): string
    {
        [$year, $month, $day] = explode('-', $date);

        return sprintf('%s.%s.%s', $day, $month, $year);
    }

    private static function parse(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
