<?php

declare(strict_types=1);

namespace Garage\Domain;

/**
 * Scheckheft-Lücken (ADR-020): eine Ausführung kam später als Fälligkeit plus Toleranz (`DueCalculator::SOON_MONTHS`,
 * `SOON_KM`). Geprüft werden die Abstände zwischen aufeinanderfolgenden Ausführungen und der Abstand von der
 * Erstzulassung zur ersten. Reine Rechnung ohne Datenbank; nach der letzten Ausführung gibt es keine Lücke — das ist
 * Überfälligkeit und gehört in den Wartungsplan.
 */
final class GapCalculator
{
    /**
     * @param array{interval_km: int|null, interval_months: int|null, first_km: int|null, first_months: int|null, valid_from: string} $rule
     * @param list<array{date: string, km: int|null}> $executions aufsteigend nach Datum
     * @return list<array{expected_by: string, previous: array{date: string, km: int|null}, next: array{date: string, km: int|null}, explanation: string}>
     */
    public static function find(array $rule, array $executions, ?string $firstRegistration): array
    {
        $gaps = [];
        if ($firstRegistration !== null && $executions !== []) {
            $start = ['date' => $firstRegistration, 'km' => 0];
            $months = $rule['first_months'] ?? $rule['interval_months'];
            $intervalKm = $rule['first_km'] ?? $rule['interval_km'];
            $gaps[] = self::check($start, $executions[0], $months, $intervalKm, true);
        }
        for ($index = 1; $index < count($executions); $index++) {
            $gaps[] = self::check($executions[$index - 1], $executions[$index], $rule['interval_months'], $rule['interval_km'], false);
        }

        return array_values(array_filter(
            $gaps,
            static fn (?array $gap): bool => $gap !== null && $gap['expected_by'] >= $rule['valid_from']
        ));
    }

    /**
     * Eine Lücke zwischen `$previous` und `$next`, oder `null`, wenn `$next` rechtzeitig kam bzw. sich keine Fälligkeit
     * berechnen lässt.
     *
     * @param array{date: string, km: int|null} $previous
     * @param array{date: string, km: int|null} $next
     * @return array{expected_by: string, previous: array{date: string, km: int|null}, next: array{date: string, km: int|null}, explanation: string}|null
     */
    private static function check(array $previous, array $next, ?int $months, ?int $intervalKm, bool $isFromRegistration): ?array
    {
        $dueDate = $months === null ? null : DueCalculator::addMonths($previous['date'], $months);
        $dueKm = $intervalKm === null || $previous['km'] === null ? null : $previous['km'] + $intervalKm;

        $isLateByDate = $dueDate !== null && $next['date'] > DueCalculator::addMonths($dueDate, DueCalculator::SOON_MONTHS);
        $isLateByKm = $dueKm !== null && $next['km'] !== null && $next['km'] > $dueKm + DueCalculator::SOON_KM;
        if (!$isLateByDate && !$isLateByKm) {
            return null;
        }

        return [
            'expected_by' => $dueDate ?? $next['date'],
            'previous' => $previous,
            'next' => $next,
            'explanation' => self::explain($previous, $next, $months, $intervalKm, $isLateByDate ? $dueDate : null, $isLateByKm ? $dueKm : null, $isFromRegistration),
        ];
    }

    /**
     * Rechenweg in einem Satz, nur mit dem Maß, das überschritten wurde, z. B. „Regel 24 Monate: nach 09.03.2022 fällig bis
     * 09.03.2024, nächste Ausführung erst am 02.03.2026.“
     *
     * @param array{date: string, km: int|null} $previous
     * @param array{date: string, km: int|null} $next
     */
    private static function explain(array $previous, array $next, ?int $months, ?int $intervalKm, ?string $lateDueDate, ?int $lateDueKm, bool $isFromRegistration): string
    {
        $intervals = [];
        if ($months !== null) {
            $intervals[] = sprintf('%d %s', $months, $months === 1 ? 'Monat' : 'Monate');
        }
        if ($intervalKm !== null) {
            $intervals[] = sprintf('%s km', DossierFormat::number($intervalKm));
        }

        $basis = $isFromRegistration
            ? sprintf('nach Erstzulassung am %s', DossierFormat::date($previous['date']))
            : sprintf('nach %s', self::point($previous));

        $due = [];
        if ($lateDueDate !== null) {
            $due[] = sprintf('bis %s', DossierFormat::date($lateDueDate));
        }
        if ($lateDueKm !== null) {
            $due[] = sprintf('bei %s km', DossierFormat::number($lateDueKm));
        }

        return sprintf(
            '%s %s: %s fällig %s, nächste Ausführung erst am %s.',
            $isFromRegistration ? 'Erstintervall' : 'Regel',
            implode(' oder ', $intervals),
            $basis,
            implode(' bzw. ', $due),
            self::point($next)
        );
    }

    /** @param array{date: string, km: int|null} $execution */
    private static function point(array $execution): string
    {
        $date = DossierFormat::date($execution['date']);
        if ($execution['km'] === null) {
            return $date;
        }

        return sprintf('%s bei %s km', $date, DossierFormat::number($execution['km']));
    }
}
