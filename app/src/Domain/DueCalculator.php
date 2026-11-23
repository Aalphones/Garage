<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Fälligkeit einer Wartungsaufgabe nach „was zuerst eintritt“ (ADR-011). Reine Rechnung: kein Zugriff auf Datenbank oder
 * Uhr, „heute“ kommt als Text herein. Datumswerte sind `YYYY-MM-DD`.
 *
 * Ohne eigene Abhängigkeiten, damit die Datumsrechnung allein per `php -r` prüfbar bleibt.
 */
final class DueCalculator
{
    public const SOON_MONTHS = 3;
    public const SOON_KM = 3000;

    public const STATUS_OVERDUE = 'ueberfaellig';
    public const STATUS_SOON = 'bald';
    public const STATUS_OK = 'ok';
    public const STATUS_UNKNOWN = 'unbekannt';

    public const CONFIDENCE_UNCERTAIN = 'unsicher';
    private const MODE_FLEXIBLE = 'flexibel';

    /**
     * Monate dazurechnen, ohne in den Folgemonat zu springen: der Tag wird auf den letzten Tag des Zielmonats gekürzt
     * (31.01. + 1 Monat = 28.02.). `DateTimeImmutable::modify('+1 month')` würde hier den 03.03. liefern.
     */
    public static function addMonths(string $date, int $months): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        $monthIndex = $year * 12 + ($month - 1) + $months;
        $targetYear = intdiv($monthIndex, 12);
        $targetMonth = $monthIndex % 12 + 1;
        $lastDay = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $targetYear, $targetMonth), new DateTimeZone('UTC')))->format('t');

        return sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, min($day, $lastDay));
    }

    public static function addDays(string $date, int $days): string
    {
        return self::parse($date)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /** Ganze Tage von `$from` bis `$to`; negativ, wenn `$to` früher liegt. */
    public static function daysBetween(string $from, string $to): int
    {
        $difference = self::parse($from)->diff(self::parse($to));

        return $difference->invert === 1 ? -(int) $difference->days : (int) $difference->days;
    }

    /**
     * @param array{interval_km: int|null, interval_months: int|null, first_km: int|null, first_months: int|null, mode: string, confidence: string}|null $rule
     * @param array{event_id: int, date: string, km: int|null}|null $lastDone jüngste Ausführung
     * @param array{km_estimate: int, per_day: float|null, estimated: bool, basis: array{from: array{date: string, km: int}|null, to: array{date: string, km: int}}}|null $forecast
     * @return array{due_date: string|null, due_km: int|null, km_due_date: string|null, effective_date: string|null, km_estimate: int|null, status: string, rule_uncertain: bool, explanation: string}
     */
    public static function compute(?array $rule, ?array $lastDone, ?string $firstRegistration, ?array $forecast, string $today): array
    {
        $kmEstimate = $forecast['km_estimate'] ?? null;
        if ($rule === null) {
            return self::result(null, null, null, $kmEstimate, self::STATUS_UNKNOWN, false, 'Keine Intervallregel hinterlegt (upsert_task_rule).');
        }

        $base = self::base($lastDone, $firstRegistration);
        $isFirst = $lastDone === null;
        $months = $isFirst ? ($rule['first_months'] ?? $rule['interval_months']) : $rule['interval_months'];
        $intervalKm = $isFirst ? ($rule['first_km'] ?? $rule['interval_km']) : $rule['interval_km'];

        $dueDate = $base['date'] !== null && $months !== null ? self::addMonths($base['date'], $months) : null;
        $dueKm = $base['km'] !== null && $intervalKm !== null ? $base['km'] + $intervalKm : null;
        $kmDueDate = self::kmDueDate($dueKm, $forecast);
        $effectiveDate = self::earlier($dueDate, $kmDueDate);
        $status = self::status($effectiveDate, $dueKm, $kmEstimate, $today);

        return self::result(
            $dueDate,
            $dueKm,
            $kmDueDate,
            $kmEstimate,
            $status,
            $rule['confidence'] === self::CONFIDENCE_UNCERTAIN,
            self::explain($rule, $lastDone, $base, $months, $intervalKm, $dueDate, $dueKm, $kmDueDate, $effectiveDate, $forecast),
            $effectiveDate
        );
    }

    /**
     * Ausgangspunkt der Rechnung: die letzte Ausführung, sonst die Erstzulassung bei 0 km.
     *
     * @param array{event_id: int, date: string, km: int|null}|null $lastDone
     * @return array{date: string|null, km: int|null}
     */
    private static function base(?array $lastDone, ?string $firstRegistration): array
    {
        if ($lastDone !== null) {
            return ['date' => $lastDone['date'], 'km' => $lastDone['km']];
        }
        if ($firstRegistration !== null) {
            return ['date' => $firstRegistration, 'km' => 0];
        }

        return ['date' => null, 'km' => null];
    }

    /**
     * Wann der letzte bekannte Stand bei der geschätzten Fahrleistung den Fälligkeitsstand erreicht; ein schon erreichter
     * Stand ergibt den Tag der letzten Ablesung.
     *
     * @param array{km_estimate: int, per_day: float|null, estimated: bool, basis: array{from: array{date: string, km: int}|null, to: array{date: string, km: int}}}|null $forecast
     */
    private static function kmDueDate(?int $dueKm, ?array $forecast): ?string
    {
        if ($dueKm === null || $forecast === null || $forecast['per_day'] === null || $forecast['per_day'] <= 0) {
            return null;
        }
        $lastReading = $forecast['basis']['to'];
        // Auf sechs Stellen gerundet, damit Gleitkomma-Rauschen aus einem glatten Ergebnis keinen Tag macht.
        $days = (int) ceil(round(($dueKm - $lastReading['km']) / $forecast['per_day'], 6));

        return self::addDays($lastReading['date'], max(0, $days));
    }

    private static function earlier(?string $first, ?string $second): ?string
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return min($first, $second);
    }

    private static function status(?string $effectiveDate, ?int $dueKm, ?int $kmEstimate, string $today): string
    {
        $hasReachedKm = $dueKm !== null && $kmEstimate !== null && $kmEstimate >= $dueKm;
        if (($effectiveDate !== null && $effectiveDate < $today) || $hasReachedKm) {
            return self::STATUS_OVERDUE;
        }

        $isSoonByKm = $dueKm !== null && $kmEstimate !== null && $dueKm - $kmEstimate <= self::SOON_KM;
        if (($effectiveDate !== null && $effectiveDate <= self::addMonths($today, self::SOON_MONTHS)) || $isSoonByKm) {
            return self::STATUS_SOON;
        }

        return $effectiveDate === null ? self::STATUS_UNKNOWN : self::STATUS_OK;
    }

    /** @return array{due_date: string|null, due_km: int|null, km_due_date: string|null, effective_date: string|null, km_estimate: int|null, status: string, rule_uncertain: bool, explanation: string} */
    private static function result(?string $dueDate, ?int $dueKm, ?string $kmDueDate, ?int $kmEstimate, string $status, bool $isUncertain, string $explanation, ?string $effectiveDate = null): array
    {
        return [
            'due_date' => $dueDate,
            'due_km' => $dueKm,
            'km_due_date' => $kmDueDate,
            'effective_date' => $effectiveDate,
            'km_estimate' => $kmEstimate,
            'status' => $status,
            'rule_uncertain' => $isUncertain,
            'explanation' => $explanation,
        ];
    }

    /**
     * Der Rechenweg in Worten: Basis, Monats- und Kilometerrechnung, was zuerst eintritt.
     *
     * @param array{interval_km: int|null, interval_months: int|null, first_km: int|null, first_months: int|null, mode: string, confidence: string} $rule
     * @param array{event_id: int, date: string, km: int|null}|null $lastDone
     * @param array{date: string|null, km: int|null} $base
     * @param array{km_estimate: int, per_day: float|null, estimated: bool, basis: array{from: array{date: string, km: int}|null, to: array{date: string, km: int}}}|null $forecast
     */
    private static function explain(array $rule, ?array $lastDone, array $base, ?int $months, ?int $intervalKm, ?string $dueDate, ?int $dueKm, ?string $kmDueDate, ?string $effectiveDate, ?array $forecast): string
    {
        $sentences = [self::baseSentence($lastDone, $base)];
        if ($base['date'] === null) {
            $sentences[] = 'Erstzulassung fehlt — set_vehicle_facts mit erstzulassung.';
        }
        if ($dueDate !== null && $months !== null) {
            $sentences[] = sprintf('+ %d %s = %s.', $months, $months === 1 ? 'Monat' : 'Monate', self::formatDate($dueDate));
        }
        if ($intervalKm !== null && $base['date'] !== null && $base['km'] === null) {
            $sentences[] = 'Der Kilometerstand der letzten Ausführung fehlt, die Kilometer-Fälligkeit lässt sich nicht berechnen.';
        }
        if ($dueKm !== null && $intervalKm !== null) {
            $sentences[] = self::kmSentence($intervalKm, $dueKm, $kmDueDate, $forecast);
        }
        $sentences[] = self::conclusion($dueDate, $kmDueDate, $effectiveDate, $dueKm);
        if ($rule['mode'] === self::MODE_FLEXIBLE) {
            $sentences[] = 'Flexibel nach Bordanzeige: das sind Höchstwerte.';
        }
        if ($rule['confidence'] === self::CONFIDENCE_UNCERTAIN) {
            $sentences[] = 'Regel unbelegt (unsicher).';
        }

        return implode(' ', array_filter($sentences, static fn (string $sentence): bool => $sentence !== ''));
    }

    /**
     * @param array{event_id: int, date: string, km: int|null}|null $lastDone
     * @param array{date: string|null, km: int|null} $base
     */
    private static function baseSentence(?array $lastDone, array $base): string
    {
        if ($lastDone !== null) {
            $kmPart = $lastDone['km'] === null ? '' : sprintf(' bei %s km', self::formatKm($lastDone['km']));

            return sprintf('Zuletzt am %s%s (Ereignis %d).', self::formatDate($lastDone['date']), $kmPart, $lastDone['event_id']);
        }
        if ($base['date'] !== null) {
            return sprintf('Noch nie ausgeführt; Basis ist die Erstzulassung am %s (0 km).', self::formatDate($base['date']));
        }

        return 'Noch keine Ausführung erfasst.';
    }

    /** @param array{km_estimate: int, per_day: float|null, estimated: bool, basis: array{from: array{date: string, km: int}|null, to: array{date: string, km: int}}}|null $forecast */
    private static function kmSentence(int $intervalKm, int $dueKm, ?string $kmDueDate, ?array $forecast): string
    {
        $sentence = sprintf('+ %s km = %s km', self::formatKm($intervalKm), self::formatKm($dueKm));
        if ($forecast === null) {
            return $sentence . ', ohne Kilometerstand keine Prognose.';
        }

        $lastReading = $forecast['basis']['to'];
        if ($dueKm <= $lastReading['km']) {
            $sentence .= sprintf(', laut Stand vom %s (%s km) bereits erreicht', self::formatDate($lastReading['date']), self::formatKm($lastReading['km']));
        } elseif ($forecast['per_day'] === null) {
            $sentence .= ', zu wenige Kilometerstände für eine Prognose';
        } elseif ($forecast['per_day'] <= 0) {
            $sentence .= ', ohne erkennbare Fahrleistung';
        } else {
            $sentence .= sprintf(', bei ≈ %s km/Tag am ≈ %s', number_format($forecast['per_day'], 1, ',', '.'), self::formatDate((string) $kmDueDate));
        }
        $estimate = $forecast['estimated']
            ? sprintf('geschätzt heute ≈ %s km', self::formatKm($forecast['km_estimate']))
            : sprintf('letzter Stand %s km, nicht hochgerechnet', self::formatKm($forecast['km_estimate']));

        return sprintf('%s (%s).', $sentence, $estimate);
    }

    private static function conclusion(?string $dueDate, ?string $kmDueDate, ?string $effectiveDate, ?int $dueKm): string
    {
        if ($effectiveDate === null) {
            return $dueKm === null ? 'Kein Fälligkeitsdatum berechenbar.' : sprintf('Fällig bei %s km, ein Datum lässt sich nicht berechnen.', self::formatKm($dueKm));
        }
        if ($dueDate !== null && $kmDueDate !== null) {
            return sprintf('Was zuerst eintritt: %s.', self::formatDate($effectiveDate));
        }

        return sprintf('Fällig: %s.', self::formatDate($effectiveDate));
    }

    private static function formatDate(string $date): string
    {
        return implode('.', array_reverse(explode('-', $date)));
    }

    private static function formatKm(int $km): string
    {
        return number_format($km, 0, ',', '.');
    }

    private static function parse(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }
}
