<?php

declare(strict_types=1);

namespace Garage\Domain;

use Garage\Auth\Scopes;
use DateTimeImmutable;
use DateTimeZone;
use Garage\Db\Transaction;
use LogicException;
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

    /** Feld → Typ, Grenzen (Länge bei Text, Wert bei Zahl), erlaubte Werte (`choices`) und ein Beispielwert für die Fehlermeldung. */
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
        'unfallfrei' => ['type' => self::TYPE_TEXT, 'choices' => ['ja', 'nein'], 'example' => 'ja'],
        'halterzahl' => ['type' => self::TYPE_INT, 'min' => 1, 'max' => 20, 'example' => '2'],
    ];

    /** Spezifikationen sind Fakten mit Feldnamen `spec.<bereich>.<schluessel>` (ADR-009). */
    private const SPEC_PREFIX = 'spec.';
    private const SPEC_PART = '/^[a-z][a-z0-9_]{1,30}$/';
    private const SPEC_EXAMPLE = '{"area": "motoroel", "key": "viskositaet", "value": "0W-20"}';

    private const TEXT_MAX = 200;
    private const LABEL_MAX = 80;
    private const FIN_PATTERN = '/^[A-HJ-NPR-Z0-9]{17}$/';
    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/u';

    private VehicleRepository $vehicles;
    private OpenItemRepository $openItems;
    private GarageRepository $garages;
    private AuditRepository $audit;
    private VehicleAccess $access;
    private RegistrationService $registrations;
    private EquipmentService $equipment;
    private DueService $due;
    private CostService $costs;
    private ValueService $value;

    /** `$documents` braucht nur `list` (Titelbild und Belegzahl je Fahrzeug). */
    public function __construct(private readonly PDO $pdo, private readonly ?DocumentService $documents = null)
    {
        $this->registrations = new RegistrationService($pdo);
        $this->equipment = new EquipmentService($pdo);
        $this->due = new DueService($pdo);
        $this->costs = new CostService($pdo);
        $this->value = new ValueService($pdo);
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
        if ($this->documents === null) {
            throw new LogicException('VehicleService::list braucht den DocumentService.');
        }

        $vehicles = $this->vehicles->listForActor($actor);
        $vehicleIds = array_map(static fn (array $vehicle): int => $vehicle['id'], $vehicles);
        $values = $this->vehicles->currentValues($vehicleIds, ['marke', 'modell', 'fin'], $actor);
        $openCounts = $this->vehicles->countOpenItems($vehicleIds, $actor);
        $dueSummary = $this->due->summary($vehicles, $actor);
        $activeVehicles = array_values(array_filter($vehicles, static fn (array $vehicle): bool => $vehicle['status'] === VehicleRepository::STATUS_ACTIVE));
        $costs = $this->costs->vehicleTotals($activeVehicles, $actor);

        $byGarage = [];
        foreach ($vehicles as $vehicle) {
            $documents = $this->documents->forVehicle($vehicle['id'], $actor);
            $byGarage[$vehicle['garage_id']][] = [
                'id' => $vehicle['id'],
                'label' => $vehicle['label'],
                'status' => $vehicle['status'],
                'plate' => $this->currentPlate($vehicle['id'], $actor),
                'cover_url' => $documents['cover']['preview_url'] ?? null,
                'document_count' => $documents['count'],
                'marke' => $values[$vehicle['id']]['marke'] ?? null,
                'modell' => $values[$vehicle['id']]['modell'] ?? null,
                'fin' => $values[$vehicle['id']]['fin'] ?? null,
                'open_items' => $openCounts[$vehicle['id']] ?? 0,
                'km_estimate' => $dueSummary[$vehicle['id']]['km_estimate'],
                'next_due' => $dueSummary[$vehicle['id']]['next_due'],
                'costs' => $costs[$vehicle['id']] ?? null,
                'value' => isset($costs[$vehicle['id']]) ? $this->value->corridorToday($vehicle['id'], $actor) : null,
            ];
        }

        $result = [];
        foreach ($this->garages->listForUser($actor->userId) as $garage) {
            $result[] = $garage + ['vehicles' => $byGarage[$garage['id']] ?? []];
        }

        return $result;
    }

    /**
     * @return array{vehicle: array{id: int, garage_id: int, label: string, status: string}, facts: array<string, array<string, mixed>>, specs: array<string, array<string, array<string, mixed>>>, disputed: list<array<string, mixed>>, registrations: list<array<string, mixed>>, holder_count: int, equipment: list<array<string, mixed>>, km_estimate: array<string, mixed>|null}
     */
    public function get(int $vehicleId, Actor $actor): array
    {
        $vehicle = $this->access->readable($vehicleId, $actor);

        $facts = [];
        $specs = [];
        foreach ($this->vehicles->currentFacts($vehicleId, $actor) as $fact) {
            $entry = [
                'value' => self::typed($fact['field'], $fact['value']),
                'source' => $fact['source'],
                'source_url' => $fact['source_url'],
                'confidence' => $fact['confidence'],
                'actor' => $fact['actor'],
                'recorded_at' => self::isoTime($fact['created_at']),
            ];
            if (str_starts_with($fact['field'], self::SPEC_PREFIX)) {
                [, $area, $key] = explode('.', $fact['field'], 3);
                $specs[$area][$key] = $entry;
                continue;
            }
            $facts[$fact['field']] = $entry;
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

        $registrations = $this->registrations->list($vehicleId, $actor);

        return [
            'vehicle' => $vehicle,
            'facts' => $facts,
            'specs' => $specs,
            'disputed' => $disputed,
            'registrations' => $registrations,
            'holder_count' => self::countHolders($registrations),
            'equipment' => $this->equipment->list($vehicleId, $actor),
            'km_estimate' => $this->due->forecastFor($vehicleId, $actor),
        ];
    }

    /** Kennzeichen der aktuellen Zulassung: `valid_to` leer oder nicht vor heute (deutsche Zeit), die mit dem jüngsten `valid_from`. */
    public function currentPlate(int $vehicleId, Actor $actor): ?string
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $plate = null;
        $newestStart = '';
        foreach ($this->registrations->list($vehicleId, $actor) as $registration) {
            $isCurrent = $registration['valid_to'] === null || $registration['valid_to'] >= $today;
            if ($isCurrent && $registration['valid_from'] <= $today && $registration['valid_from'] > $newestStart) {
                $plate = $registration['plate'];
                $newestStart = $registration['valid_from'];
            }
        }

        return $plate;
    }

    /**
     * Zahl verschiedener, nicht leerer Halter über alle Zulassungen.
     *
     * @param list<array<string, mixed>> $registrations
     */
    private static function countHolders(array $registrations): int
    {
        $holders = [];
        foreach ($registrations as $registration) {
            if ($registration['holder'] !== null) {
                $holders[mb_strtolower((string) $registration['holder'])] = true;
            }
        }

        return count($holders);
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
            $this->audit->record($actor, 'vehicle.create', 'vehicle:' . $vehicleId, $targetGarageId, null, ['label' => $label], $vehicleId);
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

    /**
     * Ein Mensch im Browser korrigiert einen Stammdaten-Wert (ADR-021). Anders als `setFacts` ersetzt das sofort, auch
     * einen bestätigten Wert: der Mensch ist die Instanz, die sonst Klärfälle entscheidet (ADR-010), ein Klärfall gegen
     * sich selbst wäre Leerlauf. Der alte Wert bleibt als abgelöste Zeile im Verlauf. Liegt am Feld ein offener Klärfall,
     * geht dieser vor.
     *
     * @return array{field: string, value: int|string, result: string}
     */
    public function correctByHuman(int $vehicleId, mixed $field, mixed $value, Actor $actor): array
    {
        if ($actor->via !== Actor::VIA_WEB) {
            throw new DomainError('forbidden', 'Stammdaten ohne Klärfall korrigiert nur ein Mensch in der Oberfläche; Agenten nutzen set_vehicle_facts.');
        }

        return Transaction::run($this->pdo, function () use ($vehicleId, $field, $value, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $validField = self::validCorrectableField($field);
            $fact = ['field' => $validField, 'value' => self::validValue($validField, $value)] + Provenance::BY_HUMAN;
            $this->assertNoOpenDispute($vehicleId, $validField, $actor);

            $current = $this->currentFact($vehicleId, $validField, $actor);
            $outcome = ['field' => $validField, 'value' => self::typed($validField, $fact['value'])];
            if ($current === null) {
                $this->writeCurrent($vehicle, $fact, null, $actor);

                return $outcome + ['result' => self::RESULT_CREATED];
            }
            $isSameEntry = $current['value'] === $fact['value']
                && $current['source'] === Provenance::SOURCE_USER
                && $current['confidence'] === Provenance::CONFIRMED;
            if ($isSameEntry) {
                return $outcome + ['result' => self::RESULT_UNCHANGED];
            }

            $this->writeCurrent($vehicle, $fact, $current, $actor);

            return $outcome + ['result' => self::RESULT_UPDATED];
        });
    }

    private static function validCorrectableField(mixed $field): string
    {
        if (!is_string($field) || !isset(self::FIELDS[$field])) {
            throw new DomainError(
                'invalid_value',
                sprintf('Korrigierbar sind nur die Stammdaten %s. Beispiel: {"field": "farbe", "value": "Urano-Grau"}', implode(', ', array_keys(self::FIELDS))),
                ['field' => 'field']
            );
        }

        return $field;
    }

    /** Ein strittiger Wert mit offenem Klärfall: erst dort entscheiden, sonst stünde die Korrektur neben einer offenen Frage. */
    private function assertNoOpenDispute(int $vehicleId, string $field, Actor $actor): void
    {
        foreach ($this->vehicles->disputedFacts($vehicleId, $actor) as $disputed) {
            if ($disputed['field'] === $field && $disputed['open_item_id'] > 0) {
                throw new DomainError(
                    'open_item_pending',
                    sprintf('Zu „%s“ ist ein Klärfall offen. Erst den Klärfall entscheiden, dann korrigieren.', $field),
                    ['field' => $field, 'open_item_id' => $disputed['open_item_id']]
                );
            }
        }
    }

    /**
     * Spezifikationen (Öl, Kühlmittel, Batterie …) als Fakten: dieselbe Konfliktregel wie bei setFacts,
     * die Einheit steht im Wert („4,3 l“).
     *
     * @param list<mixed> $specs je Eintrag area, key, value
     * @param array<string, mixed> $provenance source, source_url?, confidence
     * @return array{vehicle_id: int, specs: list<array<string, mixed>>}
     */
    public function setSpecs(int $vehicleId, array $specs, array $provenance, Actor $actor): array
    {
        return Transaction::run($this->pdo, function () use ($vehicleId, $specs, $provenance, $actor): array {
            $vehicle = $this->access->writable($vehicleId, $actor);
            $entries = self::validSpecs($specs);
            $validProvenance = Provenance::validate($provenance, 'specs');

            $results = [];
            foreach ($entries as $entry) {
                $fact = ['field' => $entry['field'], 'value' => $entry['value']] + $validProvenance;
                $outcome = $this->applyFact($vehicle, $fact, $actor);
                $results[] = ['area' => $entry['area'], 'key' => $entry['key'], 'value' => $entry['value'], 'result' => $outcome['result']]
                    + (isset($outcome['open_item_id']) ? ['open_item_id' => $outcome['open_item_id']] : []);
            }

            return ['vehicle_id' => $vehicleId, 'specs' => $results];
        });
    }

    /**
     * @param list<mixed> $specs
     * @return list<array{area: string, key: string, value: string, field: string}>
     */
    private static function validSpecs(array $specs): array
    {
        if ($specs === []) {
            throw new DomainError('invalid_value', 'specs darf nicht leer sein. Beispiel: [' . self::SPEC_EXAMPLE . ']', ['field' => 'specs']);
        }

        $entries = [];
        $seen = [];
        foreach ($specs as $spec) {
            if (!is_array($spec) || array_is_list($spec)) {
                throw new DomainError('invalid_value', 'Jeder Eintrag in specs ist ein Objekt. Beispiel: ' . self::SPEC_EXAMPLE, ['field' => 'specs']);
            }
            $area = self::validSpecPart('area', $spec['area'] ?? null);
            $key = self::validSpecPart('key', $spec['key'] ?? null);
            $field = self::SPEC_PREFIX . $area . '.' . $key;
            if (isset($seen[$field])) {
                throw new DomainError('invalid_value', sprintf('Die Spezifikation „%s“ kommt mehrfach in einer Anfrage vor. Pro Bereich und Schlüssel genau ein Eintrag.', $field), ['field' => $field]);
            }
            $seen[$field] = true;
            $entries[] = ['area' => $area, 'key' => $key, 'value' => Validate::text('value', $spec['value'] ?? null, 1, self::TEXT_MAX, self::SPEC_EXAMPLE), 'field' => $field];
        }

        return $entries;
    }

    private static function validSpecPart(string $name, mixed $raw): string
    {
        if (!is_string($raw) || preg_match(self::SPEC_PART, $raw) !== 1) {
            throw Validate::invalid($name, 'Erwartet werden 2 bis 31 Zeichen: Kleinbuchstaben a–z, Ziffern und Unterstrich, am Anfang ein Buchstabe, ohne Umlaute und Leerzeichen.', self::SPEC_EXAMPLE);
        }

        return $raw;
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
            // `via` unterscheidet im Verlauf die Korrektur im Browser (ADR-021) von der Meldung eines Agenten.
            ['fact_id' => $factId, 'value' => $fact['value'], 'source' => $fact['source'], 'confidence' => $fact['confidence'], 'status' => VehicleRepository::FACT_CURRENT, 'via' => $actor->via],
            $vehicle['id']
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
            ['fact_id' => $factId, 'value' => $fact['value'], 'source' => $fact['source'], 'confidence' => $fact['confidence'], 'status' => VehicleRepository::FACT_DISPUTED, 'open_item_id' => $openItemId],
            $vehicle['id']
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
        if (is_string($field) && str_starts_with($field, self::SPEC_PREFIX)) {
            throw new DomainError('invalid_value', 'Spezifikationen über set_spec eintragen. Beispiel: ' . self::SPEC_EXAMPLE, ['field' => $field]);
        }
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

        if (isset($definition['choices'])) {
            return Validate::enum($field, $raw, $definition['choices']);
        }

        return match ($definition['type']) {
            self::TYPE_FIN => self::validFin($field, $raw, $example),
            self::TYPE_DATE => Validate::date($field, $raw, $example),
            self::TYPE_INT => Validate::int($field, $raw, $example, $definition['min'] ?? 0, $definition['max'] ?? null),
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
        return (self::FIELDS[$field]['type'] ?? self::TYPE_TEXT) === self::TYPE_INT ? (int) $value : $value;
    }

    private static function isoTime(string $databaseTime): string
    {
        return str_replace(' ', 'T', $databaseTime) . 'Z';
    }
}
