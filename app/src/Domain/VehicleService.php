<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Auth\Scopes;
use Garage\Db\Transaction;
use PDO;

/**
 * Fahrzeuge und ihre Stammdaten als Fakten mit Herkunft (ADR-007). Die Konfliktregel lebt hier, nicht im Prompt:
 * nichts überschreibt einen bestätigten Wert, ein Widerspruch wird ein Klärfall.
 */
final class VehicleService
{
    public const RESULT_CREATED = 'angelegt';
    public const RESULT_UPDATED = 'aktualisiert';
    public const RESULT_UNCHANGED = 'unveraendert';
    public const RESULT_DISPUTE = 'klaerfall';

    private const TYPE_FIN = 'fin';
    private const TYPE_TEXT = 'text';
    private const TYPE_DATE = 'date';
    private const TYPE_INT = 'int';

    /** Feld → Typ, Längengrenzen (nur Text) und ein Beispielwert für die Fehlermeldung. */
    public const FIELDS = [
        'fin' => ['type' => self::TYPE_FIN, 'example' => 'WVWZZZ1KZAW123456'],
        'hsn' => ['type' => self::TYPE_TEXT, 'min' => 4, 'max' => 4, 'example' => '0603'],
        'tsn' => ['type' => self::TYPE_TEXT, 'min' => 3, 'max' => 10, 'example' => 'BEC'],
        'marke' => ['type' => self::TYPE_TEXT, 'example' => 'Volkswagen'],
        'modell' => ['type' => self::TYPE_TEXT, 'example' => 'Golf'],
        'variante' => ['type' => self::TYPE_TEXT, 'example' => '1.4 TSI Highline'],
        'motorcode' => ['type' => self::TYPE_TEXT, 'example' => 'CZCA'],
        'getriebecode' => ['type' => self::TYPE_TEXT, 'example' => 'NFN'],
        'erstzulassung' => ['type' => self::TYPE_DATE, 'example' => '2016-03-14'],
        'farbe' => ['type' => self::TYPE_TEXT, 'example' => 'Urano-Grau'],
        'farbcode' => ['type' => self::TYPE_TEXT, 'example' => 'LI7F'],
        'kraftstoff' => ['type' => self::TYPE_TEXT, 'example' => 'Benzin'],
        'leistung_kw' => ['type' => self::TYPE_INT, 'example' => '110'],
        'hubraum_ccm' => ['type' => self::TYPE_INT, 'example' => '1395'],
        'schadstoffklasse' => ['type' => self::TYPE_TEXT, 'example' => 'Euro 6'],
        'co2_g_km' => ['type' => self::TYPE_INT, 'example' => '120'],
        'leergewicht_kg' => ['type' => self::TYPE_INT, 'example' => '1270'],
        'zul_gesamtgewicht_kg' => ['type' => self::TYPE_INT, 'example' => '1830'],
        'listenneupreis_eur' => ['type' => self::TYPE_INT, 'example' => '28000'],
        'schluesselanzahl' => ['type' => self::TYPE_INT, 'example' => '2'],
        'ablageort_papiere' => ['type' => self::TYPE_TEXT, 'example' => 'Ordner Auto, Regal Büro'],
    ];

    private const TEXT_MAX = 200;
    private const LABEL_MAX = 80;
    private const FIN_PATTERN = '/^[A-HJ-NPR-Z0-9]{17}$/';
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/u';

    private VehicleRepository $vehicles;
    private OpenItemRepository $openItems;
    private GarageRepository $garages;
    private AuditRepository $audit;
    private VehicleAccess $access;

    public function __construct(private readonly PDO $pdo)
    {
        $this->vehicles = new VehicleRepository($pdo);
        $this->openItems = new OpenItemRepository($pdo);
        $this->garages = new GarageRepository($pdo);
        $this->audit = new AuditRepository($pdo);
        $this->access = new VehicleAccess($pdo);
    }

    /**
     * Alle Garagen des Akteurs, auch leere, mit ihren Fahrzeugen.
     *
     * @return list<array{id: int, name: string, role: string, vehicles: list<array<string, mixed>>}>
     */
    public function list(Actor $actor): array
    {
        if ($actor->userId === null) {
            return [];
        }

        $vehicles = $this->vehicles->listForActor($actor);
        $vehicleIds = array_map(static fn (array $vehicle): int => $vehicle['id'], $vehicles);
        $values = $this->vehicles->currentValues($vehicleIds, ['marke', 'modell', 'fin'], $actor);
        $openCounts = $this->vehicles->countOpenItems($vehicleIds, $actor);

        $byGarage = [];
        foreach ($vehicles as $vehicle) {
            $byGarage[$vehicle['garage_id']][] = [
                'id' => $vehicle['id'],
                'label' => $vehicle['label'],
                'status' => $vehicle['status'],
                'marke' => $values[$vehicle['id']]['marke'] ?? null,
                'modell' => $values[$vehicle['id']]['modell'] ?? null,
                'fin' => $values[$vehicle['id']]['fin'] ?? null,
                'open_items' => $openCounts[$vehicle['id']] ?? 0,
            ];
        }

        $result = [];
        foreach ($this->garages->listForUser($actor->userId) as $garage) {
            $result[] = $garage + ['vehicles' => $byGarage[$garage['id']] ?? []];
        }

        return $result;
    }

    /**
     * @return array{vehicle: array{id: int, garage_id: int, label: string, status: string}, facts: array<string, array<string, mixed>>, disputed: list<array<string, mixed>>}
     */
    public function get(int $vehicleId, Actor $actor): array
    {
        $vehicle = $this->access->readable($vehicleId, $actor);

        $facts = [];
        foreach ($this->vehicles->currentFacts($vehicleId, $actor) as $fact) {
            $facts[$fact['field']] = [
                'value' => self::typed($fact['field'], $fact['value']),
                'source' => $fact['source'],
                'source_url' => $fact['source_url'],
                'confidence' => $fact['confidence'],
                'actor' => $fact['actor'],
                'recorded_at' => self::isoTime($fact['created_at']),
            ];
        }

        $disputed = [];
        foreach ($this->vehicles->disputedFacts($vehicleId, $actor) as $fact) {
            $disputed[] = [
                'field' => $fact['field'],
                'value' => self::typed($fact['field'], $fact['value']),
                'source' => $fact['source'],
                'confidence' => $fact['confidence'],
                'open_item_id' => $fact['open_item_id'],
            ];
        }

        return ['vehicle' => $vehicle, 'facts' => $facts, 'disputed' => $disputed];
    }

    /**
     * @param list<mixed> $facts wie bei setFacts; darf leer sein
     * @return array{vehicle_id: int, garage_id: int, label: string, facts: list<array<string, mixed>>}
     */
    public function create(string $label, ?int $garageId, array $facts, Actor $actor): array
    {
        $label = self::validLabel($label);
        $targetGarageId = $this->resolveGarage($garageId, $actor);
        $validated = $this->validateFacts($facts, true);

        return Transaction::run($this->pdo, function () use ($label, $targetGarageId, $validated, $actor): array {
            $vehicleId = $this->vehicles->insert($targetGarageId, $label, $actor);
            $this->audit->record($actor, 'vehicle.create', 'vehicle:' . $vehicleId, $targetGarageId, null, ['label' => $label]);
            $vehicle = ['id' => $vehicleId, 'garage_id' => $targetGarageId, 'label' => $label, 'status' => VehicleRepository::STATUS_ACTIVE];

            return [
                'vehicle_id' => $vehicleId,
                'garage_id' => $targetGarageId,
                'label' => $label,
                'facts' => $this->applyFacts($vehicle, $validated, $actor),
            ];
        });
    }

    /**
     * Ganze Anfrage in einer Transaktion: ein ungültiges Feld schreibt nichts.
     *
     * @param list<mixed> $facts
     * @return array{vehicle_id: int, facts: list<array<string, mixed>>}
     */
    public function setFacts(int $vehicleId, array $facts, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($vehicleId, $facts, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);

            return ['vehicle_id' => $vehicleId, 'facts' => $this->applyFacts($vehicle, $this->validateFacts($facts, false), $actor)];
        });
    }

    /** Ohne Angabe die einzige beschreibbare Garage; eine fremde Garage ist `not_found`, eine nur lesbare `forbidden`. */
    private function resolveGarage(?int $garageId, Actor $actor): int
    {
        if ($garageId !== null) {
            if (!$actor->canRead($garageId)) {
                throw new DomainError('not_found', 'Eine Garage mit dieser ID gibt es bei dir nicht. list_vehicles nennt deine Garagen.', ['garage_id' => $garageId]);
            }
            $this->access->assertCanWriteGarage($garageId, $actor);

            return $garageId;
        }

        $writable = array_values(array_filter($actor->garageIds(), static fn (int $id): bool => $actor->canWrite($id)));
        if (count($writable) === 1) {
            return $writable[0];
        }
        if ($writable === []) {
            $this->failWithoutWritableGarage($actor);
        }

        $names = [];
        foreach ($this->garages->listForUser((int) $actor->userId) as $garage) {
            if (in_array($garage['id'], $writable, true)) {
                $names[] = sprintf('%d („%s“)', $garage['id'], $garage['name']);
            }
        }
        throw new DomainError(
            'invalid_value',
            'Du kannst in mehreren Garagen schreiben, daher ist garage_id nötig: ' . implode(', ', $names) . '. Beispiel: {"label": "Golf 7", "garage_id": ' . $writable[0] . '}',
            ['field' => 'garage_id']
        );
    }

    private function failWithoutWritableGarage(Actor $actor): never
    {
        if ($actor->garageIds() === []) {
            throw new DomainError('not_found', 'Dieses Konto gehört noch zu keiner Garage.');
        }
        if (!in_array(Scopes::WRITE, $actor->scopes, true)) {
            throw new DomainError('forbidden', 'Dem Zugang fehlt ' . Scopes::WRITE . '.', ['required_scope' => Scopes::WRITE]);
        }

        throw new DomainError('forbidden', 'Dieses Konto darf in der Garage nur lesen.');
    }

    /**
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param list<array{field: string, value: string, source: string, source_url: string|null, confidence: string}> $facts
     * @return list<array<string, mixed>>
     */
    private function applyFacts(array $vehicle, array $facts, Actor $actor): array
    {
        $results = [];
        foreach ($facts as $fact) {
            $results[] = $this->applyFact($vehicle, $fact, $actor);
        }

        return $results;
    }

    /**
     * Die fünf Zweige der Konfliktregel, je Feld.
     *
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param array{field: string, value: string, source: string, source_url: string|null, confidence: string} $fact
     * @return array<string, mixed>
     */
    private function applyFact(array $vehicle, array $fact, Actor $actor): array
    {
        $current = $this->currentFact($vehicle['id'], $fact['field'], $actor);
        $outcome = ['field' => $fact['field'], 'value' => self::typed($fact['field'], $fact['value'])];

        if ($current === null) {
            $this->writeCurrent($vehicle, $fact, null, $actor);

            return $outcome + ['result' => self::RESULT_CREATED];
        }

        if ($current['value'] === $fact['value']) {
            if (!Provenance::isHigher($fact['confidence'], $current['confidence'])) {
                return $outcome + ['result' => self::RESULT_UNCHANGED];
            }
            $this->writeCurrent($vehicle, $fact, $current, $actor);

            return $outcome + ['result' => self::RESULT_UPDATED];
        }

        if ($current['confidence'] !== Provenance::CONFIRMED) {
            $this->writeCurrent($vehicle, $fact, $current, $actor);

            return $outcome + ['result' => self::RESULT_UPDATED];
        }

        return $outcome + $this->recordDispute($vehicle, $fact, $current, $actor);
    }

    /**
     * Setzt den Wert als aktuell; eine vorherige aktuelle Zeile wird abgelöst und bleibt als Verlauf stehen.
     *
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param array{field: string, value: string, source: string, source_url: string|null, confidence: string} $fact
     * @param array{id: int, value: string, source: string, confidence: string}|null $previous
     */
    private function writeCurrent(array $vehicle, array $fact, ?array $previous, Actor $actor): void
    {
        if ($previous !== null) {
            $this->vehicles->supersede($previous['id'], $actor);
        }
        $factId = $this->vehicles->insertFact($vehicle['garage_id'], $vehicle['id'], $fact['field'], $fact['value'], $fact, VehicleRepository::FACT_CURRENT, $actor);

        $this->audit->record(
            $actor,
            'vehicle_fact.set',
            sprintf('vehicle:%d.%s', $vehicle['id'], $fact['field']),
            $vehicle['garage_id'],
            $previous === null ? null : ['value' => $previous['value'], 'source' => $previous['source'], 'confidence' => $previous['confidence']],
            ['fact_id' => $factId, 'value' => $fact['value'], 'source' => $fact['source'], 'confidence' => $fact['confidence'], 'status' => VehicleRepository::FACT_CURRENT]
        );
    }

    /**
     * Ein Widerspruch zu einem bestätigten Wert: neue Zeile `strittig` daneben und ein Klärfall. Meldet derselbe
     * Widerspruch sich erneut, bleibt es beim bestehenden Klärfall.
     *
     * @param array{id: int, garage_id: int, label: string, status: string} $vehicle
     * @param array{field: string, value: string, source: string, source_url: string|null, confidence: string} $fact
     * @param array{id: int, value: string, source: string, source_url: string|null, confidence: string, actor: string} $current
     * @return array{result: string, open_item_id: int}
     */
    private function recordDispute(array $vehicle, array $fact, array $current, Actor $actor): array
    {
        $existing = $this->vehicles->findOpenDispute($vehicle['id'], $fact['field'], $fact['value'], $actor);
        if ($existing !== null) {
            return ['result' => self::RESULT_DISPUTE, 'open_item_id' => $existing];
        }

        $factId = $this->vehicles->insertFact($vehicle['garage_id'], $vehicle['id'], $fact['field'], $fact['value'], $fact, VehicleRepository::FACT_DISPUTED, $actor);
        $openItemId = $this->openItems->insert(
            $vehicle['garage_id'],
            $vehicle['id'],
            OpenItemRepository::KIND_CONFLICT,
            self::conflictText($vehicle['label'], $fact, $current),
            'vehicle_facts',
            $factId,
            $actor
        );

        $this->audit->record(
            $actor,
            'vehicle_fact.dispute',
            sprintf('vehicle:%d.%s', $vehicle['id'], $fact['field']),
            $vehicle['garage_id'],
            ['value' => $current['value'], 'source' => $current['source'], 'confidence' => $current['confidence']],
            ['fact_id' => $factId, 'value' => $fact['value'], 'source' => $fact['source'], 'confidence' => $fact['confidence'], 'status' => VehicleRepository::FACT_DISPUTED, 'open_item_id' => $openItemId]
        );

        return ['result' => self::RESULT_DISPUTE, 'open_item_id' => $openItemId];
    }

    /**
     * @param array{field: string, value: string, source: string, source_url: string|null, confidence: string} $fact
     * @param array{value: string, source: string, source_url: string|null, confidence: string, actor: string} $current
     */
    private static function conflictText(string $label, array $fact, array $current): string
    {
        return sprintf(
            'Widerspruch bei „%s“ am Fahrzeug „%s“: bestätigt ist „%s“ (Quelle: %s), neu gemeldet wird „%s“ (Quelle: %s, Konfidenz %s). Ein Mensch entscheidet, welcher Wert gilt.',
            $fact['field'],
            $label,
            $current['value'],
            self::describeSource($current['source'], $current['source_url'], $current['actor']),
            $fact['value'],
            self::describeSource($fact['source'], $fact['source_url'], null),
            $fact['confidence']
        );
    }

    private static function describeSource(string $source, ?string $url, ?string $actorName): string
    {
        $parts = [$source];
        if ($url !== null) {
            $parts[] = $url;
        }
        if ($actorName !== null) {
            $parts[] = 'erfasst von ' . $actorName;
        }

        return implode(', ', $parts);
    }

    /** @return array{id: int, field: string, value: string, source: string, source_url: string|null, confidence: string, actor: string, created_at: string}|null */
    private function currentFact(int $vehicleId, string $field, Actor $actor): ?array
    {
        foreach ($this->vehicles->currentFacts($vehicleId, $actor) as $fact) {
            if ($fact['field'] === $field) {
                return $fact;
            }
        }

        return null;
    }

    /**
     * Prüft die ganze Liste, bevor etwas geschrieben wird.
     *
     * @param list<mixed> $facts
     * @return list<array{field: string, value: string, source: string, source_url: string|null, confidence: string}>
     */
    private function validateFacts(array $facts, bool $mayBeEmpty): array
    {
        if ($facts === [] && !$mayBeEmpty) {
            throw new DomainError('invalid_value', 'facts darf nicht leer sein. Beispiel: [{"field": "marke", "value": "Volkswagen", "source": "nutzer", "confidence": "bestaetigt"}]', ['field' => 'facts']);
        }

        $validated = [];
        $seen = [];
        foreach ($facts as $entry) {
            $fact = self::validFact($entry);
            if (isset($seen[$fact['field']])) {
                throw new DomainError('invalid_value', sprintf('Das Feld „%s“ kommt mehrfach in einer Anfrage vor. Pro Feld genau ein Eintrag.', $fact['field']), ['field' => $fact['field']]);
            }
            $seen[$fact['field']] = true;
            $validated[] = $fact;
        }

        return $validated;
    }

    /** @return array{field: string, value: string, source: string, source_url: string|null, confidence: string} */
    private static function validFact(mixed $entry): array
    {
        $example = '{"field": "marke", "value": "Volkswagen", "source": "nutzer", "confidence": "bestaetigt"}';
        if (!is_array($entry) || array_is_list($entry)) {
            throw new DomainError('invalid_value', 'Jeder Eintrag in facts ist ein Objekt. Beispiel: ' . $example, ['field' => 'facts']);
        }

        $field = $entry['field'] ?? null;
        if (!is_string($field) || !isset(self::FIELDS[$field])) {
            throw new DomainError(
                'invalid_value',
                sprintf('Unbekanntes Feld „%s“. Erlaubt: %s. Beispiel: %s', is_string($field) ? $field : '(fehlt)', implode(', ', array_keys(self::FIELDS)), $example),
                ['field' => is_string($field) ? $field : 'field']
            );
        }

        $provenance = Provenance::validate($entry, $field);

        return ['field' => $field, 'value' => self::validValue($field, $entry['value'] ?? null)] + $provenance;
    }

    private static function validValue(string $field, mixed $raw): string
    {
        $definition = self::FIELDS[$field];
        $example = sprintf('{"field": "%s", "value": "%s"}', $field, $definition['example']);

        return match ($definition['type']) {
            self::TYPE_FIN => self::validFin($field, $raw, $example),
            self::TYPE_DATE => Validate::date($field, $raw, $example),
            self::TYPE_INT => Validate::int($field, $raw, $example),
            default => Validate::text($field, $raw, $definition['min'] ?? 1, $definition['max'] ?? self::TEXT_MAX, $example),
        };
    }

    private static function validFin(string $field, mixed $raw, string $example): string
    {
        $fin = is_string($raw) ? strtoupper(trim($raw)) : '';
        if (preg_match(self::FIN_PATTERN, $fin) !== 1) {
            throw Validate::invalid($field, 'Die FIN hat genau 17 Zeichen aus A–H, J–N, P, R–Z und 0–9 (ohne I, O, Q).', $example);
        }

        return $fin;
    }

    private static function validLabel(string $label): string
    {
        $label = trim($label);
        $length = mb_check_encoding($label, 'UTF-8') ? mb_strlen($label) : 0;
        if ($length < 1 || $length > self::LABEL_MAX || preg_match(self::CONTROL_CHARACTERS, $label) === 1) {
            throw new DomainError('invalid_value', sprintf('label ist ein einzeiliger Anzeigename mit 1 bis %d Zeichen. Beispiel: {"label": "Golf 7"}', self::LABEL_MAX), ['field' => 'label']);
        }

        return $label;
    }

    /** Ganzzahlfelder kommen als Zahl zurück, alles andere als Text. */
    private static function typed(string $field, string $value): int|string
    {
        return self::FIELDS[$field]['type'] === self::TYPE_INT ? (int) $value : $value;
    }

    private static function isoTime(string $databaseTime): string
    {
        return str_replace(' ', 'T', $databaseTime) . 'Z';
    }
}
