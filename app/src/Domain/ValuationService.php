<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Db\Transaction;
use PDO;

/** Ankerwerte (Bewertungen, Kaufpreis) und Vergleichsinserate eines Fahrzeugs, über den Datensatz-Abgleich (ADR-023). */
final class ValuationService
{
    public const KINDS = ['kaufpreis', 'verkaufspreis', 'gutachten', 'haendlerangebot', 'bewertung', 'schaetzung'];
    public const VALUE_TYPES = ['haendlereinkauf', 'privatverkauf', 'haendlerverkauf'];

    private const VALUATIONS = 'valuations';
    private const LISTINGS = 'market_listings';
    private const FIRST_REGISTRATION_PATTERN = '/^(19|20)\d{2}-(0[1-9]|1[0-2])$/';
    private const VALUATION_EXAMPLE = '{"vehicle_id": 12, "valued_on": "2026-11-20", "kind": "bewertung", "amount_eur": "12400", "value_type": "privatverkauf", "provider": "DAT"}';
    private const LISTING_EXAMPLE = '{"vehicle_id": 12, "url": "https://example.com/inserat/123", "fetched_on": "2026-11-20", "price_eur": "11950", "odometer_km": 31200, "first_registration": "2020-04", "same_drivetrain": true}';

    private VehicleAccess $access;
    private RecordMerger $merger;
    private RecordRepository $records;
    private ValueService $values;

    public function __construct(private readonly PDO $pdo)
    {
        $this->access = new VehicleAccess($pdo);
        $this->merger = new RecordMerger($pdo);
        $this->records = new RecordRepository($pdo);
        $this->values = new ValueService($pdo);
    }

    /**
     * Datum, Art und Anbieter erkennen dieselbe Bewertung wieder; ohne Anbieter steht `''` im Schlüssel.
     *
     * @param array<string, mixed> $input valued_on, kind, amount_eur, value_type?, odometer_km?, range_low_eur?, range_high_eur?, provider?, note?, source, source_url?, confidence
     * @return array{valuation_id: int, result: string, open_item_ids: list<int>}
     */
    public function addValuation(int $vehicleId, array $input, Actor $actor): array
    {
        $values = self::valuationValues($input);
        $provenance = Provenance::validate($input, 'valuation');

        return Transaction::run($this->pdo, function () use ($vehicleId, $values, $provenance, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $merged = $this->merger->merge(self::VALUATIONS, $values, $provenance, $vehicle, $actor);

            return ['valuation_id' => $merged['id'], 'result' => $merged['result'], 'open_item_ids' => $merged['open_item_ids']];
        });
    }

    /**
     * Die URL erkennt dasselbe Inserat wieder; ein erneuter Abruf trägt Preis, km und Abrufdatum nach. `counts` sagt, ob das
     * gespeicherte Inserat heute für den Korridor zählt.
     *
     * @param array<string, mixed> $input url, fetched_on, price_eur, odometer_km, first_registration, same_drivetrain, equipment_note?, postal_code?, source, source_url?, confidence
     * @return array{listing_id: int, result: string, counts: bool, open_item_ids: list<int>}
     */
    public function addListing(int $vehicleId, array $input, Actor $actor): array
    {
        $values = self::listingValues($input);
        $provenance = Provenance::validate($input, 'listing');

        $merged = Transaction::run($this->pdo, function () use ($vehicleId, $values, $provenance, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);

            return $this->merger->merge(self::LISTINGS, $values, $provenance, $vehicle, $actor);
        });

        $stored = $this->records->find(self::LISTINGS, $merged['id'], $actor);
        $counts = $stored !== null && $this->values->countsToday($vehicleId, ValueService::listingInput($stored), $actor);

        return ['listing_id' => $merged['id'], 'result' => $merged['result'], 'counts' => $counts, 'open_item_ids' => $merged['open_item_ids']];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private static function valuationValues(array $input): array
    {
        $amount = self::positiveCents('amount_eur', $input['amount_eur'] ?? null);
        $low = Validate::euroToCents('range_low_eur', $input['range_low_eur'] ?? null);
        $high = Validate::euroToCents('range_high_eur', $input['range_high_eur'] ?? null);
        if ($low !== null && $high !== null && (int) $low > (int) $high) {
            throw Validate::invalid('range_low_eur', 'Die Untergrenze liegt über der Obergrenze.', '{"range_low_eur": "11500", "range_high_eur": "13200"}');
        }
        $valueType = $input['value_type'] ?? null;

        return [
            'valued_on' => Validate::date('valued_on', $input['valued_on'] ?? null, self::VALUATION_EXAMPLE),
            'kind' => Validate::enum('kind', $input['kind'] ?? null, self::KINDS),
            'provider' => Validate::optionalText('provider', $input['provider'] ?? null, 1, 120, '{"provider": "DAT"}') ?? '',
            'value_type' => $valueType === null || $valueType === '' ? null : Validate::enum('value_type', $valueType, self::VALUE_TYPES),
            'amount_cents' => $amount,
            'range_low_cents' => $low,
            'range_high_cents' => $high,
            'odometer_km' => ($input['odometer_km'] ?? null) === null ? null : OdometerService::validKm('odometer_km', $input['odometer_km']),
            'note' => Validate::optionalText('note', $input['note'] ?? null, 1, 200, '{"note": "Online-Bewertung, Zustand gut"}'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private static function listingValues(array $input): array
    {
        $url = Validate::url('url', $input['url'] ?? null);
        if ($url === null) {
            throw Validate::invalid('url', 'Erwartet wird die http(s)-Adresse des Inserats.', self::LISTING_EXAMPLE);
        }
        $firstRegistration = $input['first_registration'] ?? null;
        if (!is_string($firstRegistration) || preg_match(self::FIRST_REGISTRATION_PATTERN, $firstRegistration) !== 1) {
            throw Validate::invalid('first_registration', 'Erwartet wird Jahr und Monat der Erstzulassung als JJJJ-MM.', '{"first_registration": "2020-04"}');
        }
        $sameDrivetrain = Validate::bool('same_drivetrain', $input['same_drivetrain'] ?? null);
        if ($sameDrivetrain === null) {
            throw Validate::invalid('same_drivetrain', 'Pflichtangabe: passen Motor und Getriebe zum eigenen Auto?', '{"same_drivetrain": true}');
        }

        return [
            'url' => $url,
            'fetched_on' => Validate::date('fetched_on', $input['fetched_on'] ?? null, self::LISTING_EXAMPLE),
            'price_cents' => self::positiveCents('price_eur', $input['price_eur'] ?? null),
            'odometer_km' => OdometerService::validKm('odometer_km', $input['odometer_km'] ?? null),
            'first_registration' => $firstRegistration,
            'same_drivetrain' => $sameDrivetrain,
            'equipment_note' => Validate::optionalText('equipment_note', $input['equipment_note'] ?? null, 1, 300, '{"equipment_note": "Navi, Sitzheizung"}'),
            'postal_code' => Validate::optionalText('postal_code', $input['postal_code'] ?? null, 4, 10, '{"postal_code": "10115"}'),
        ];
    }

    private static function positiveCents(string $field, mixed $raw): string
    {
        $cents = Validate::euroToCents($field, $raw);
        if ($cents === null || (int) $cents < 1) {
            throw Validate::invalid($field, 'Erwartet wird ein Betrag über 0 Euro mit höchstens zwei Nachkommastellen.', sprintf('{"%s": "11950"}', $field));
        }

        return $cents;
    }
}
