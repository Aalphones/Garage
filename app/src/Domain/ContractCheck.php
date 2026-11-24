<?php

declare(strict_types=1);

namespace Garage\Domain;

/**
 * Prüfzeile je Vertrag im Reiter Papiere: rechnet nach, ob ein Beitrag zum Fahrzeug passt. Reine Rechnung ohne Datenbank;
 * `PaperService` liefert Fakten, Prognose und Periode.
 *
 * @phpstan-type Check array{status: string, text: string, explanation: string}
 */
final class ContractCheck
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARN = 'warn';
    public const STATUS_UNKNOWN = 'unbekannt';

    public const TAX_FACTS = ['erstzulassung', 'kraftstoff', 'hubraum_ccm', 'co2_g_km'];

    private const FACT_LABELS = [
        'erstzulassung' => 'Erstzulassung (Feld B)',
        'kraftstoff' => 'Kraftstoff (Feld P.3)',
        'hubraum_ccm' => 'Hubraum (Feld P.1)',
        'co2_g_km' => 'CO₂ (Feld V.7)',
    ];
    /** Ab hier gilt die CO₂-Steuer (§ 8 Nr. 1 Buchst. b KraftStG); ältere Autos zahlen nach Schadstoffklasse. */
    private const CO2_TAX_SINCE = '2009-07-01';
    /** Ab hier steigt der Satz je Gramm stufenweise (§ 9 Abs. 1 Nr. 2 Buchst. c KraftStG, Erstzulassung ab 2021). */
    private const STAGED_CO2_SINCE = '2021-01-01';
    private const PETROL_CENTS_PER_100CCM = 200;
    private const DIESEL_CENTS_PER_100CCM = 950;
    private const FLAT_CO2_CENTS_PER_GRAM = 200;
    /** Obergrenze in g/km => Cent je Gramm über der Freigrenze von 95 g/km. */
    private const STAGED_CO2_BANDS = [115 => 200, 135 => 220, 155 => 250, 175 => 290, 195 => 340, PHP_INT_MAX => 400];
    private const TOLERANCE_CENTS = 100;
    private const DAYS_PER_YEAR = 365;
    private const KM_ROUNDING = 100;

    /**
     * Kfz-Steuer gegen den Betrag der jüngsten Periode, anteilig auf deren Länge gerechnet.
     *
     * @param array<string, string> $facts aktuelle Fakten des Fahrzeugs
     * @param array{amount_cents: int, days: int}|null $term
     * @return Check
     */
    public static function tax(array $facts, ?array $term): array
    {
        foreach (self::TAX_FACTS as $field) {
            if (($facts[$field] ?? '') === '') {
                return self::unknown(self::FACT_LABELS[$field] . ' fehlt', 'Ohne ' . self::FACT_LABELS[$field] . ' lässt sich die Steuer nicht nachrechnen.');
            }
        }
        if ($term === null) {
            return self::unknown('Kein Beitrag erfasst', 'Es gibt noch keine Beitragsperiode (record_contract_term) zum Vergleich.');
        }
        $firstRegistration = substr($facts['erstzulassung'], 0, 10);
        if ($firstRegistration < self::CO2_TAX_SINCE) {
            return self::unknown('Erstzulassung vor 07/2009', 'Für ältere Autos gilt die Steuer nach Schadstoffklasse; sie wird nicht nachgerechnet.');
        }
        $fuel = mb_strtolower($facts['kraftstoff']);
        $centsPer100ccm = match (true) {
            str_contains($fuel, 'diesel') => self::DIESEL_CENTS_PER_100CCM,
            str_contains($fuel, 'benzin'), str_contains($fuel, 'super') => self::PETROL_CENTS_PER_100CCM,
            default => null,
        };
        if ($centsPer100ccm === null) {
            return self::unknown(sprintf('Kraftstoff „%s“ wird nicht geprüft', $facts['kraftstoff']), 'Nachgerechnet werden nur Benziner und Diesel.');
        }

        $displacement = (int) $facts['hubraum_ccm'];
        $co2 = (int) $facts['co2_g_km'];
        $started100ccm = (int) ceil($displacement / 100);
        $displacementCents = $started100ccm * $centsPer100ccm;
        $freeGrams = self::freeGrams($firstRegistration);
        $co2Cents = $firstRegistration >= self::STAGED_CO2_SINCE ? self::stagedCo2Cents($co2, $freeGrams) : max(0, $co2 - $freeGrams) * self::FLAT_CO2_CENTS_PER_GRAM;
        $annualCents = $displacementCents + $co2Cents;
        $expectedCents = (int) round($annualCents * $term['days'] / self::DAYS_PER_YEAR);

        $explanation = sprintf(
            '%d × %s je angefangene 100 cm³ (%s cm³) + %d g über %d g/km%s = %s im Jahr%s; Beitrag %s.',
            $started100ccm,
            self::euro($centsPer100ccm),
            DossierFormat::number($displacement),
            max(0, $co2 - $freeGrams),
            $freeGrams,
            $firstRegistration >= self::STAGED_CO2_SINCE ? ' gestaffelt (2,00 € bis 4,00 € je g)' : ' × ' . self::euro(self::FLAT_CO2_CENTS_PER_GRAM),
            self::euro($annualCents),
            $term['days'] === self::DAYS_PER_YEAR ? '' : sprintf(', für %d Tage %s', $term['days'], self::euro($expectedCents)),
            self::euro($term['amount_cents'])
        );
        if (abs($expectedCents - $term['amount_cents']) > self::TOLERANCE_CENTS) {
            return self::check(self::STATUS_WARN, sprintf('Beitrag %s, gerechnet %s', self::euro($term['amount_cents']), self::euro($expectedCents)), $explanation);
        }

        return self::check(self::STATUS_OK, 'Steuer stimmt: ' . self::euro($expectedCents), $explanation);
    }

    /**
     * Versicherung: Kilometerprognose gegen die vereinbarte Fahrleistung der jüngsten Periode; ohne Angabe keine Prüfzeile.
     *
     * @return Check|null
     */
    public static function insurance(?float $kmPerDay, ?int $declaredKmPerYear): ?array
    {
        if ($declaredKmPerYear === null) {
            return null;
        }
        if ($kmPerDay === null) {
            return self::unknown('Keine Kilometerprognose', 'Für eine Prognose braucht es mindestens zwei gültige km-Stände.');
        }
        $forecast = (int) (round($kmPerDay * self::DAYS_PER_YEAR / self::KM_ROUNDING) * self::KM_ROUNDING);
        $explanation = sprintf(
            '%s km am Tag × 365 = %s km im Jahr (auf 100 gerundet), vereinbart %s km.',
            DossierFormat::decimal($kmPerDay),
            DossierFormat::number($forecast),
            DossierFormat::number($declaredKmPerYear)
        );
        if ($forecast > $declaredKmPerYear) {
            return self::check(self::STATUS_WARN, sprintf('Prognose %s km über vereinbarten %s km', DossierFormat::number($forecast), DossierFormat::number($declaredKmPerYear)), $explanation);
        }

        return self::check(self::STATUS_OK, sprintf('Fahrleistung passt: %s von %s km', DossierFormat::number($forecast), DossierFormat::number($declaredKmPerYear)), $explanation);
    }

    /**
     * Garagen-Vertrag: der Umlage-Rechenweg für dieses Fahrzeug.
     *
     * @param array{method: string, percent: float|null, eur_per_year: string|null} $share
     * @return Check
     */
    public static function garageShare(array $share, ?int $termCents): array
    {
        if ($share['method'] === ContractService::ALLOCATION_KM) {
            return self::check(self::STATUS_OK, 'Umlage nach km', 'Der Anteil richtet sich nach den gefahrenen Kilometern des Monats; die Kostenrechnung legt ihn um.');
        }
        if ($share['percent'] === null || $termCents === null || $share['eur_per_year'] === null) {
            return self::check(self::STATUS_OK, 'Umlage: ' . $share['method'], 'Noch kein Beitrag oder Anteil erfasst; die Umlage folgt mit der ersten Beitragsperiode.');
        }
        $explanation = sprintf(
            'Beitrag %s, Schlüssel %s, Anteil %s %% = %s im Jahr.',
            self::euro($termCents),
            $share['method'],
            DossierFormat::decimal($share['percent']),
            DossierFormat::euro($share['eur_per_year'])
        );

        return self::check(self::STATUS_OK, 'Anteil ' . DossierFormat::euro($share['eur_per_year']) . ' im Jahr', $explanation);
    }

    private static function freeGrams(string $firstRegistration): int
    {
        $year = (int) substr($firstRegistration, 0, 4);

        return match (true) {
            $year <= 2011 => 120,
            $year <= 2013 => 110,
            default => 95,
        };
    }

    private static function stagedCo2Cents(int $co2, int $freeGrams): int
    {
        $cents = 0;
        $lowerBound = $freeGrams;
        foreach (self::STAGED_CO2_BANDS as $upperBound => $centsPerGram) {
            if ($co2 <= $lowerBound) {
                break;
            }
            $cents += (min($co2, $upperBound) - $lowerBound) * $centsPerGram;
            $lowerBound = $upperBound;
        }

        return $cents;
    }

    /** @return Check */
    private static function unknown(string $text, string $explanation): array
    {
        return self::check(self::STATUS_UNKNOWN, $text, $explanation);
    }

    /** @return Check */
    private static function check(string $status, string $text, string $explanation): array
    {
        return ['status' => $status, 'text' => $text, 'explanation' => $explanation];
    }

    private static function euro(int $cents): string
    {
        return DossierFormat::euro(Money::centsToEuro($cents));
    }
}
