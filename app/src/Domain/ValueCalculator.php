<?php

declare(strict_types=1);

namespace Garage\Domain;

/**
 * Marktkorridor, Verlauf und Wertfaktoren (ADR-023). Reine Rechnung: kein Zugriff auf Datenbank oder Uhr, „heute“ kommt als
 * Text herein. Beträge in Cent, Datumswerte `YYYY-MM-DD`, Erstzulassung eines Inserats `YYYY-MM`.
 */
final class ValueCalculator
{
    public const NEEDED_FOR_CORRIDOR = 8;
    public const NEEDED_FOR_REGRESSION = 15;
    public const MAX_LISTING_AGE_DAYS = 60;
    public const MAX_YEAR_DIFFERENCE = 1;
    public const MAX_KM_SHARE = 0.25;
    public const HISTORY_MONTHS = 24;

    public const METHOD_NONE = 'none';
    public const METHOD_MEDIAN = 'median';
    public const METHOD_REGRESSION = 'regression';

    public const REASON_DRIVETRAIN = 'drivetrain';
    public const REASON_YEAR = 'year';
    public const REASON_KM = 'km';
    public const REASON_AGE = 'age';

    public const VERDICT_PLUS = 'plus';
    public const VERDICT_MINUS = 'minus';
    public const VERDICT_UNKNOWN = 'unbekannt';

    public const HISTORY_CORRIDOR = 'korridor';
    public const HISTORY_ANCHOR = 'anker';

    private const MIN_DETERMINANT = 1e-9;
    private const LOWER_QUARTILE = 0.25;
    private const MEDIAN = 0.5;
    private const UPPER_QUARTILE = 0.75;

    private const FACTOR_HORIZON_MONTHS = 12;
    private const HU_TASK_KEY = 'hu';
    private const MIN_TREAD_MM = 4.0;
    private const MAX_GOOD_HOLDERS = 2;
    private const RECALL_OPEN = 'offen';

    /**
     * Zählende Inserate und daraus Median samt mittlerer Hälfte (ab 8) oder die auf km und Alter umgerechnete Mitte (ab 15).
     *
     * @param list<array{price_cents: int, odometer_km: int, first_registration: string, same_drivetrain: bool, fetched_on: string}> $listings
     * @param array{first_registration_year: int|null, km_today: int|null, age_months: int|null} $own
     * @return array{counting: int, needed_for_corridor: int, needed_for_regression: int, method: string, median_cents: int|null, low_cents: int|null, high_cents: int|null, explanation: string}
     */
    public static function corridor(array $listings, array $own, string $today): array
    {
        $counting = array_values(array_filter($listings, static fn (array $listing): bool => self::reasonNotCounting($listing, $own, $today) === null));
        $count = count($counting);

        if ($count < self::NEEDED_FOR_CORRIDOR) {
            return self::corridorResult($count, self::METHOD_NONE, null, null, null, sprintf(
                '%d von mindestens %d passenden Inseraten der letzten %d Tage — zu wenige für einen Korridor.%s',
                $count,
                self::NEEDED_FOR_CORRIDOR,
                self::MAX_LISTING_AGE_DAYS,
                self::missingOwnData($own)
            ));
        }

        $median = self::median($counting);
        if ($count < self::NEEDED_FOR_REGRESSION) {
            return self::corridorResult($count, self::METHOD_MEDIAN, $median['median'], $median['low'], $median['high'], sprintf(
                '%d passende Inserate (ab %d Median, ab %d auf km und Alter umgerechnet): Median %s, mittlere Hälfte %s bis %s.',
                $count,
                self::NEEDED_FOR_CORRIDOR,
                self::NEEDED_FOR_REGRESSION,
                self::euro($median['median']),
                self::euro($median['low']),
                self::euro($median['high'])
            ));
        }

        $regression = self::regression($counting, $own);
        if ($regression === null) {
            return self::corridorResult($count, self::METHOD_MEDIAN, $median['median'], $median['low'], $median['high'], sprintf(
                '%d passende Inserate; km und Alter streuen zu wenig für eine Umrechnung, daher der Median: %s, mittlere Hälfte %s bis %s.',
                $count,
                self::euro($median['median']),
                self::euro($median['low']),
                self::euro($median['high'])
            ));
        }

        return self::corridorResult($count, self::METHOD_REGRESSION, $regression['median'], $regression['low'], $regression['high'], sprintf(
            '%d passende Inserate, Preis nach km und Alter umgerechnet auf %s km und %d Monate: Mitte %s, Band %s bis %s (mittlere Hälfte der Abweichungen).',
            $count,
            number_format((int) $own['km_today'], 0, ',', '.'),
            (int) $own['age_months'],
            self::euro($regression['median']),
            self::euro($regression['low']),
            self::euro($regression['high'])
        ));
    }

    /**
     * Warum ein Inserat nicht zählt; `null`, wenn es zählt. Fehlt am eigenen Auto das Erstzulassungsjahr oder der km-Stand,
     * zählt kein Inserat (Grund `year` bzw. `km`).
     *
     * @param array{price_cents: int, odometer_km: int, first_registration: string, same_drivetrain: bool, fetched_on: string} $listing
     * @param array{first_registration_year: int|null, km_today: int|null, age_months: int|null} $own
     */
    public static function reasonNotCounting(array $listing, array $own, string $today): ?string
    {
        if (!$listing['same_drivetrain']) {
            return self::REASON_DRIVETRAIN;
        }
        $listingYear = (int) substr($listing['first_registration'], 0, 4);
        if ($own['first_registration_year'] === null || abs($listingYear - $own['first_registration_year']) > self::MAX_YEAR_DIFFERENCE) {
            return self::REASON_YEAR;
        }
        if ($own['km_today'] === null || abs($listing['odometer_km'] - $own['km_today']) > self::MAX_KM_SHARE * $own['km_today']) {
            return self::REASON_KM;
        }
        if ($listing['fetched_on'] > $today || $listing['fetched_on'] < DueCalculator::addDays($today, -self::MAX_LISTING_AGE_DAYS)) {
            return self::REASON_AGE;
        }

        return null;
    }

    /**
     * Eigene Vergleichsdaten an einem Tag: km laut Messung bzw. Prognose aus den bis dahin abgelesenen Ständen.
     *
     * @param list<array{read_on: string, km: int}> $readings gültige Stände
     * @return array{first_registration_year: int|null, km_today: int|null, age_months: int|null}
     */
    public static function ownAt(array $readings, ?string $firstRegistration, string $date): array
    {
        $readingsSoFar = array_values(array_filter($readings, static fn (array $reading): bool => $reading['read_on'] <= $date));
        $forecast = OdometerForecast::compute($readingsSoFar, $firstRegistration, $date);

        return [
            'first_registration_year' => $firstRegistration === null ? null : (int) substr($firstRegistration, 0, 4),
            'km_today' => $forecast['km_estimate'] ?? null,
            'age_months' => $firstRegistration === null ? null : self::monthsBetween($firstRegistration, $date),
        ];
    }

    /**
     * Ein Korridor-Punkt je Monatsende der letzten 24 abgeschlossenen Monate, an dem genug Inserate zählten (jeweils mit
     * den bis dahin abgerufenen und dem km-Stand von damals), dazu jeder Ankerwert; nach Datum aufsteigend.
     *
     * @param list<array{price_cents: int, odometer_km: int, first_registration: string, same_drivetrain: bool, fetched_on: string}> $listings
     * @param list<array{read_on: string, km: int}> $readings
     * @param list<array{valued_on: string, kind: string, amount_cents: int}> $anchors
     * @return list<array<string, mixed>>
     */
    public static function history(array $listings, array $readings, ?string $firstRegistration, array $anchors, string $today): array
    {
        $points = [];
        $firstOfMonth = substr($today, 0, 8) . '01';
        for ($monthsBack = self::HISTORY_MONTHS; $monthsBack >= 1; $monthsBack--) {
            $monthEnd = DueCalculator::addDays(DueCalculator::addMonths($firstOfMonth, -$monthsBack + 1), -1);
            $corridor = self::corridor($listings, self::ownAt($readings, $firstRegistration, $monthEnd), $monthEnd);
            if ($corridor['method'] === self::METHOD_NONE) {
                continue;
            }
            $points[] = [
                'type' => self::HISTORY_CORRIDOR,
                'month' => substr($monthEnd, 0, 7),
                'on' => $monthEnd,
                'median_cents' => $corridor['median_cents'],
                'low_cents' => $corridor['low_cents'],
                'high_cents' => $corridor['high_cents'],
            ];
        }
        foreach ($anchors as $anchor) {
            $points[] = [
                'type' => self::HISTORY_ANCHOR,
                'month' => substr($anchor['valued_on'], 0, 7),
                'on' => $anchor['valued_on'],
                'kind' => $anchor['kind'],
                'amount_cents' => $anchor['amount_cents'],
            ];
        }
        usort($points, static fn (array $left, array $right): int => [$left['on'], $left['type']] <=> [$right['on'], $right['type']]);

        return $points;
    }

    /**
     * Die acht Wertfaktoren in fester Reihenfolge; `ref` ist der Reiter der Oberfläche mit der Fundstelle.
     *
     * @param array{
     *     gaps: list<array{title: string, expected_by: string}>,
     *     due_items: list<array{task_key: string, title: string, rule: array<string, mixed>|null, effective_date: string|null, status: string}>,
     *     unfallfrei: string|null,
     *     halterzahl: int|null,
     *     tread_depths: list<array{date: string, value: float}>,
     *     recalls: list<array{title: string, status: string}>,
     *     equipment_count: int
     * } $input
     * @return list<array{key: string, label: string, verdict: string, detail: string, ref: string}>
     */
    public static function factors(array $input, string $today): array
    {
        $horizon = DueCalculator::addMonths($today, self::FACTOR_HORIZON_MONTHS);

        return [
            self::serviceHistoryFactor($input['gaps']),
            self::inspectionFactor($input['due_items'], $horizon),
            self::accidentFactor($input['unfallfrei']),
            self::holderFactor($input['halterzahl']),
            self::treadFactor($input['tread_depths']),
            self::workFactor($input['due_items'], $today, $horizon),
            self::recallFactor($input['recalls']),
            self::equipmentFactor($input['equipment_count']),
        ];
    }

    /** Ganze Monate von `$from` bis `$to`; ein angebrochener Monat zählt nicht. `$from` darf `YYYY-MM` sein (Monatserster). */
    public static function monthsBetween(string $from, string $to): int
    {
        [$fromYear, $fromMonth, $fromDay] = array_map('intval', explode('-', strlen($from) === 7 ? $from . '-01' : $from));
        [$toYear, $toMonth, $toDay] = array_map('intval', explode('-', $to));
        $months = ($toYear - $fromYear) * 12 + ($toMonth - $fromMonth);
        if ($toDay < $fromDay) {
            $months--;
        }

        return $months;
    }

    /**
     * @param list<array{price_cents: int}> $listings mindestens eines
     * @return array{median: int, low: int, high: int}
     */
    private static function median(array $listings): array
    {
        $prices = array_map(static fn (array $listing): int => $listing['price_cents'], $listings);
        sort($prices);

        return [
            'median' => (int) self::quantile($prices, self::MEDIAN),
            'low' => (int) self::quantile($prices, self::LOWER_QUARTILE),
            'high' => (int) self::quantile($prices, self::UPPER_QUARTILE),
        ];
    }

    /**
     * Wert an Rang `round(q·(n−1))` einer aufsteigend sortierten Liste.
     *
     * @param list<int|float> $sorted
     */
    private static function quantile(array $sorted, float $quantile): int|float
    {
        return $sorted[(int) round($quantile * (count($sorted) - 1))];
    }

    /**
     * Kleinste Quadrate `preis = a + b·km + c·alter_monate`. Gerechnet wird mit standardisierten km und Monaten, damit die
     * Determinante unabhängig von der Größenordnung sagt, ob km und Alter zu wenig oder zu gleichförmig streuen; dann `null`.
     *
     * @param list<array{price_cents: int, odometer_km: int, first_registration: string, fetched_on: string}> $listings
     * @param array{first_registration_year: int|null, km_today: int|null, age_months: int|null} $own
     * @return array{median: int, low: int, high: int}|null
     */
    private static function regression(array $listings, array $own): ?array
    {
        if ($own['km_today'] === null || $own['age_months'] === null) {
            return null;
        }
        $kms = array_map(static fn (array $listing): float => (float) $listing['odometer_km'], $listings);
        $ages = array_map(static fn (array $listing): float => (float) self::monthsBetween($listing['first_registration'], $listing['fetched_on']), $listings);
        $prices = array_map(static fn (array $listing): float => (float) $listing['price_cents'], $listings);
        $kmScale = self::scale($kms);
        $ageScale = self::scale($ages);
        if ($kmScale === null || $ageScale === null) {
            return null;
        }

        $rows = [];
        foreach ($listings as $index => $listing) {
            $rows[] = [1.0, ($kms[$index] - $kmScale['mean']) / $kmScale['deviation'], ($ages[$index] - $ageScale['mean']) / $ageScale['deviation']];
        }
        $coefficients = self::leastSquares($rows, $prices);
        if ($coefficients === null) {
            return null;
        }

        $predict = static fn (array $row): float => $coefficients[0] * $row[0] + $coefficients[1] * $row[1] + $coefficients[2] * $row[2];
        $residuals = [];
        foreach ($rows as $index => $row) {
            $residuals[] = $prices[$index] - $predict($row);
        }
        sort($residuals);
        $middle = $predict([1.0, ($own['km_today'] - $kmScale['mean']) / $kmScale['deviation'], ($own['age_months'] - $ageScale['mean']) / $ageScale['deviation']]);

        return [
            'median' => max(0, (int) round($middle)),
            'low' => max(0, (int) round($middle + self::quantile($residuals, self::LOWER_QUARTILE))),
            'high' => max(0, (int) round($middle + self::quantile($residuals, self::UPPER_QUARTILE))),
        ];
    }

    /**
     * @param list<float> $values
     * @return array{mean: float, deviation: float}|null `null`, wenn alle Werte gleich sind
     */
    private static function scale(array $values): ?array
    {
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $values)) / count($values);
        if ($variance <= 0.0) {
            return null;
        }

        return ['mean' => $mean, 'deviation' => sqrt($variance)];
    }

    /**
     * Normalgleichungen (XᵀX/n)·β = Xᵀy/n, gelöst nach Gauß mit Pivotsuche; Determinante = Produkt der Pivots.
     *
     * @param list<list<float>> $rows
     * @param list<float> $targets
     * @return list<float>|null
     */
    private static function leastSquares(array $rows, array $targets): ?array
    {
        $size = count($rows[0]);
        $count = count($rows);
        $matrix = array_fill(0, $size, array_fill(0, $size + 1, 0.0));
        foreach ($rows as $index => $row) {
            for ($line = 0; $line < $size; $line++) {
                for ($column = 0; $column < $size; $column++) {
                    $matrix[$line][$column] += $row[$line] * $row[$column] / $count;
                }
                $matrix[$line][$size] += $row[$line] * $targets[$index] / $count;
            }
        }

        $determinant = 1.0;
        for ($pivot = 0; $pivot < $size; $pivot++) {
            $best = $pivot;
            for ($line = $pivot + 1; $line < $size; $line++) {
                if (abs($matrix[$line][$pivot]) > abs($matrix[$best][$pivot])) {
                    $best = $line;
                }
            }
            if ($best !== $pivot) {
                [$matrix[$pivot], $matrix[$best]] = [$matrix[$best], $matrix[$pivot]];
                $determinant = -$determinant;
            }
            $determinant *= $matrix[$pivot][$pivot];
            if (abs($determinant) < self::MIN_DETERMINANT) {
                return null;
            }
            for ($line = $pivot + 1; $line < $size; $line++) {
                $factor = $matrix[$line][$pivot] / $matrix[$pivot][$pivot];
                for ($column = $pivot; $column <= $size; $column++) {
                    $matrix[$line][$column] -= $factor * $matrix[$pivot][$column];
                }
            }
        }

        $solution = array_fill(0, $size, 0.0);
        for ($line = $size - 1; $line >= 0; $line--) {
            $sum = $matrix[$line][$size];
            for ($column = $line + 1; $column < $size; $column++) {
                $sum -= $matrix[$line][$column] * $solution[$column];
            }
            $solution[$line] = $sum / $matrix[$line][$line];
        }

        return $solution;
    }

    /** @return array{counting: int, needed_for_corridor: int, needed_for_regression: int, method: string, median_cents: int|null, low_cents: int|null, high_cents: int|null, explanation: string} */
    private static function corridorResult(int $count, string $method, ?int $median, ?int $low, ?int $high, string $explanation): array
    {
        return [
            'counting' => $count,
            'needed_for_corridor' => self::NEEDED_FOR_CORRIDOR,
            'needed_for_regression' => self::NEEDED_FOR_REGRESSION,
            'method' => $method,
            'median_cents' => $median,
            'low_cents' => $low,
            'high_cents' => $high,
            'explanation' => $explanation,
        ];
    }

    /** @param array{first_registration_year: int|null, km_today: int|null, age_months: int|null} $own */
    private static function missingOwnData(array $own): string
    {
        if ($own['first_registration_year'] === null) {
            return ' Am eigenen Auto fehlt die Erstzulassung, daher zählt kein Inserat.';
        }
        if ($own['km_today'] === null) {
            return ' Am eigenen Auto fehlt ein Kilometerstand, daher zählt kein Inserat.';
        }

        return '';
    }

    private static function euro(int $cents): string
    {
        return number_format($cents / 100, 0, ',', '.') . ' €';
    }

    /**
     * @param list<array{title: string, expected_by: string}> $gaps
     * @return array{key: string, label: string, verdict: string, detail: string, ref: string}
     */
    private static function serviceHistoryFactor(array $gaps): array
    {
        if ($gaps === []) {
            return self::factor('scheckheft', 'Scheckheft', self::VERDICT_PLUS, 'Keine Lücke im Scheckheft.', 'scheckheft');
        }
        $first = $gaps[0];

        return self::factor('scheckheft', 'Scheckheft', self::VERDICT_UNKNOWN, sprintf('Lücke: %s, erwartet bis %s.', $first['title'], $first['expected_by']), 'scheckheft');
    }

    /**
     * @param list<array{task_key: string, title: string, rule: array<string, mixed>|null, effective_date: string|null, status: string}> $dueItems
     * @return array{key: string, label: string, verdict: string, detail: string, ref: string}
     */
    private static function inspectionFactor(array $dueItems, string $horizon): array
    {
        foreach ($dueItems as $item) {
            if ($item['task_key'] !== self::HU_TASK_KEY || $item['rule'] === null || $item['effective_date'] === null) {
                continue;
            }
            if ($item['effective_date'] >= $horizon) {
                return self::factor('hu', 'Hauptuntersuchung', self::VERDICT_PLUS, sprintf('HU fällig %s, mindestens zwölf Monate hin.', $item['effective_date']), 'wartung');
            }

            return self::factor('hu', 'Hauptuntersuchung', self::VERDICT_MINUS, sprintf('HU fällig %s, weniger als zwölf Monate hin.', $item['effective_date']), 'wartung');
        }

        return self::factor('hu', 'Hauptuntersuchung', self::VERDICT_UNKNOWN, 'Keine Regel für die HU hinterlegt.', 'wartung');
    }

    /** @return array{key: string, label: string, verdict: string, detail: string, ref: string} */
    private static function accidentFactor(?string $accidentFree): array
    {
        return match ($accidentFree) {
            'ja' => self::factor('unfallfrei', 'Unfallfrei', self::VERDICT_PLUS, 'Als unfallfrei eingetragen.', 'uebersicht'),
            'nein' => self::factor('unfallfrei', 'Unfallfrei', self::VERDICT_MINUS, 'Als nicht unfallfrei eingetragen.', 'uebersicht'),
            default => self::factor('unfallfrei', 'Unfallfrei', self::VERDICT_UNKNOWN, 'Nicht eingetragen.', 'uebersicht'),
        };
    }

    /** @return array{key: string, label: string, verdict: string, detail: string, ref: string} */
    private static function holderFactor(?int $holders): array
    {
        if ($holders === null) {
            return self::factor('halterzahl', 'Halter', self::VERDICT_UNKNOWN, 'Halterzahl nicht eingetragen.', 'uebersicht');
        }
        $verdict = self::VERDICT_MINUS;
        if ($holders <= self::MAX_GOOD_HOLDERS) {
            $verdict = self::VERDICT_PLUS;
        }

        return self::factor('halterzahl', 'Halter', $verdict, sprintf('%d Halter laut Eintrag.', $holders), 'uebersicht');
    }

    /**
     * @param list<array{date: string, value: float}> $treadDepths
     * @return array{key: string, label: string, verdict: string, detail: string, ref: string}
     */
    private static function treadFactor(array $treadDepths): array
    {
        if ($treadDepths === []) {
            return self::factor('profiltiefe', 'Profiltiefe', self::VERDICT_UNKNOWN, 'Keine Messung der Profiltiefe.', 'messwerte');
        }
        $latestDate = max(array_map(static fn (array $point): string => $point['date'], $treadDepths));
        $smallest = min(array_map(static fn (array $point): float => $point['value'], array_filter($treadDepths, static fn (array $point): bool => $point['date'] === $latestDate)));
        $detail = sprintf('Kleinster Wert am %s: %s mm.', $latestDate, number_format($smallest, 1, ',', ''));
        if ($smallest >= self::MIN_TREAD_MM) {
            return self::factor('profiltiefe', 'Profiltiefe', self::VERDICT_PLUS, $detail, 'messwerte');
        }

        return self::factor('profiltiefe', 'Profiltiefe', self::VERDICT_MINUS, $detail, 'messwerte');
    }

    /**
     * @param list<array{task_key: string, title: string, rule: array<string, mixed>|null, effective_date: string|null, status: string}> $dueItems
     * @return array{key: string, label: string, verdict: string, detail: string, ref: string}
     */
    private static function workFactor(array $dueItems, string $today, string $horizon): array
    {
        $withRule = array_values(array_filter($dueItems, static fn (array $item): bool => $item['rule'] !== null));
        if ($withRule === []) {
            return self::factor('arbeiten', 'Anstehende Arbeiten', self::VERDICT_UNKNOWN, 'Keine Wartungsregeln hinterlegt.', 'wartung');
        }
        $upcoming = array_filter(
            $withRule,
            static fn (array $item): bool => $item['status'] === DueCalculator::STATUS_OVERDUE || ($item['effective_date'] !== null && $item['effective_date'] <= $horizon)
        );
        if ($upcoming === []) {
            return self::factor('arbeiten', 'Anstehende Arbeiten', self::VERDICT_PLUS, 'In den nächsten zwölf Monaten ist nichts fällig.', 'wartung');
        }
        $titles = implode(', ', array_map(static fn (array $item): string => $item['title'], $upcoming));

        return self::factor('arbeiten', 'Anstehende Arbeiten', self::VERDICT_MINUS, sprintf('Fällig bis %s: %s.', $horizon, $titles), 'wartung');
    }

    /**
     * @param list<array{title: string, status: string}> $recalls
     * @return array{key: string, label: string, verdict: string, detail: string, ref: string}
     */
    private static function recallFactor(array $recalls): array
    {
        foreach ($recalls as $recall) {
            if ($recall['status'] === self::RECALL_OPEN) {
                return self::factor('rueckrufe', 'Rückrufe', self::VERDICT_MINUS, sprintf('Offener Rückruf: %s.', $recall['title']), 'uebersicht');
            }
        }

        return self::factor('rueckrufe', 'Rückrufe', self::VERDICT_PLUS, 'Kein offener Rückruf.', 'uebersicht');
    }

    /** @return array{key: string, label: string, verdict: string, detail: string, ref: string} */
    private static function equipmentFactor(int $count): array
    {
        if ($count === 0) {
            return self::factor('ausstattung', 'Ausstattung', self::VERDICT_UNKNOWN, 'Keine Ausstattung eingetragen.', 'uebersicht');
        }

        return self::factor('ausstattung', 'Ausstattung', self::VERDICT_PLUS, sprintf('%d %s eingetragen.', $count, $count === 1 ? 'Ausstattungsmerkmal' : 'Ausstattungsmerkmale'), 'uebersicht');
    }

    /** @return array{key: string, label: string, verdict: string, detail: string, ref: string} */
    private static function factor(string $key, string $label, string $verdict, string $detail, string $ref): array
    {
        return ['key' => $key, 'label' => $label, 'verdict' => $verdict, 'detail' => $detail, 'ref' => $ref];
    }
}
