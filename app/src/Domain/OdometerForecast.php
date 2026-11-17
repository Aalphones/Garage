<?php

declare(strict_types=1);

namespace Garage\Domain;

/**
 * Geschätzter Kilometerstand von heute aus den gültigen Ständen (ADR-011). Reine Rechnung: „heute“ kommt als Text herein.
 *
 * Fahrleistung pro Tag = Weg vom Vergleichsstand zum letzten Stand. Der Vergleichsstand soll rund ein Jahr zurückliegen
 * (glättet Jahreszeiten), mindestens aber 30 Tage, damit ein kurzer Abstand die Rate nicht verzerrt.
 */
final class OdometerForecast
{
    private const MIN_DAYS = 30;
    private const MAX_DAYS = 365;

    /**
     * @param list<array{read_on: string, km: int}> $readings gültige Stände
     * @return array{km_estimate: int, per_day: float|null, estimated: bool, basis: array{from: array{date: string, km: int}|null, to: array{date: string, km: int}}}|null
     */
    public static function compute(array $readings, ?string $firstRegistration, string $today): ?array
    {
        if ($readings === []) {
            return null;
        }

        usort($readings, static fn (array $left, array $right): int => [$left['read_on'], $left['km']] <=> [$right['read_on'], $right['km']]);
        $latest = $readings[array_key_last($readings)];
        $to = ['date' => $latest['read_on'], 'km' => $latest['km']];

        $from = self::comparison($readings, $to['date'], $firstRegistration);
        if ($from === null) {
            return ['km_estimate' => $to['km'], 'per_day' => null, 'estimated' => false, 'basis' => ['from' => null, 'to' => $to]];
        }

        $perDay = ($to['km'] - $from['km']) / DueCalculator::daysBetween($from['date'], $to['date']);
        $daysSinceLatest = max(0, DueCalculator::daysBetween($to['date'], $today));

        return [
            'km_estimate' => (int) round($to['km'] + $perDay * $daysSinceLatest),
            'per_day' => $perDay,
            'estimated' => true,
            'basis' => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * Frühester Stand im Fenster [letzter − 365 Tage, letzter − 30 Tage]; gibt es keinen, der früheste Stand davor; gibt
     * es auch den nicht, die Erstzulassung bei 0 km (sofern sie weit genug zurückliegt).
     *
     * @param list<array{read_on: string, km: int}> $readings aufsteigend sortiert
     * @return array{date: string, km: int}|null
     */
    private static function comparison(array $readings, string $latestDate, ?string $firstRegistration): ?array
    {
        $windowStart = DueCalculator::addDays($latestDate, -self::MAX_DAYS);
        $windowEnd = DueCalculator::addDays($latestDate, -self::MIN_DAYS);

        $earliestBeforeEnd = null;
        foreach ($readings as $reading) {
            if ($reading['read_on'] > $windowEnd) {
                break;
            }
            $earliestBeforeEnd ??= $reading;
            if ($reading['read_on'] >= $windowStart) {
                return ['date' => $reading['read_on'], 'km' => $reading['km']];
            }
        }
        if ($earliestBeforeEnd !== null) {
            return ['date' => $earliestBeforeEnd['read_on'], 'km' => $earliestBeforeEnd['km']];
        }
        if ($firstRegistration !== null && $firstRegistration <= $windowEnd) {
            return ['date' => $firstRegistration, 'km' => 0];
        }

        return null;
    }
}
