<?php

declare(strict_types=1);

namespace Garage\Domain;

use DateTimeImmutable;
use PDO;

/**
 * Die Akte eines Fahrzeugs als Markdown, Aufbau fest (20 Abschnitte). Liest nur über die Dienste der Bereiche — jeder prüft
 * den Zugriff selbst — und rechnet nichts Eigenes; Fälligkeit und Prognose kommen aus `DueService`.
 */
final class DossierService
{
    private const DUE_HORIZON_MONTHS = 120;
    private const NOTE_HEADING_SHIFT = 2;
    private const NONE = '–';
    private const DAYS_PER_YEAR = 365;
    private const CONTRACT_EVENT_DONE = 'erledigt';
    private const VERDICT_MARKS = [
        ValueCalculator::VERDICT_PLUS => '+',
        ValueCalculator::VERDICT_MINUS => '−',
        ValueCalculator::VERDICT_UNKNOWN => '?',
    ];

    /** Lesbare Namen der Stammdatenfelder; Felder ohne Eintrag erscheinen mit ihrem Schlüssel. */
    private const LABELS = [
        'fin' => 'FIN',
        'hsn' => 'HSN',
        'tsn' => 'TSN',
        'marke' => 'Marke',
        'modell' => 'Modell',
        'variante' => 'Variante',
        'motorcode' => 'Motorcode',
        'getriebecode' => 'Getriebecode',
        'erstzulassung' => 'Erstzulassung',
        'farbe' => 'Farbe',
        'farbcode' => 'Farbcode',
        'kraftstoff' => 'Kraftstoff',
        'leistung_kw' => 'Leistung (kW)',
        'hubraum_ccm' => 'Hubraum (ccm)',
        'schadstoffklasse' => 'Schadstoffklasse',
        'co2_g_km' => 'CO₂ (g/km)',
        'leergewicht_kg' => 'Leergewicht (kg)',
        'zul_gesamtgewicht_kg' => 'Zulässiges Gesamtgewicht (kg)',
        'listenneupreis_eur' => 'Listenneupreis (€)',
        'schluesselanzahl' => 'Schlüsselanzahl',
        'ablageort_papiere' => 'Ablageort der Papiere',
        'unfallfrei' => 'Unfallfrei',
        'halterzahl' => 'Anzahl Halter',
    ];
    private const DATE_FIELDS = ['erstzulassung'];
    private const INTEGER_FIELDS = ['leistung_kw', 'hubraum_ccm', 'co2_g_km', 'leergewicht_kg', 'zul_gesamtgewicht_kg', 'listenneupreis_eur'];

    private const STATUS_WORDS = [
        DueCalculator::STATUS_OVERDUE => 'überfällig',
        DueCalculator::STATUS_SOON => 'bald',
        DueCalculator::STATUS_OK => 'ok',
        DueCalculator::STATUS_UNKNOWN => 'unbekannt',
    ];
    private const POSITION_WORDS = [
        'vl' => 'vorne links',
        'vr' => 'vorne rechts',
        'hl' => 'hinten links',
        'hr' => 'hinten rechts',
        'vorne' => 'vorne',
        'hinten' => 'hinten',
    ];
    private const EQUIPMENT_GROUPS = ['serie' => 'Serie', 'sonder' => 'Sonder'];
    private const RECALL_STATUS_WORDS = ['offen' => 'offen', 'erledigt' => 'erledigt', 'nicht_betroffen' => 'nicht betroffen'];
    private const ATTACHMENT_WORDS = [
        'vehicles' => 'Fahrzeug',
        'events' => 'Ereignis',
        'damages' => 'Schaden',
        'recalls' => 'Rückruf',
        'tire_sets' => 'Reifensatz',
        'parts' => 'Teil',
        'measurements' => 'Messwert',
        'odometer_readings' => 'Kilometerstand',
        'registrations' => 'Zulassung',
    ];

    private DocumentRepository $documents;
    private VehicleService $vehicles;
    private EventService $events;
    private DueService $due;
    private OdometerService $odometer;
    private MeasurementService $measurements;
    private TireService $tires;
    private PartService $parts;
    private DamageService $damages;
    private NoteService $notes;
    private OpenItemService $openItems;
    private PaperService $papers;
    private CostService $costs;
    private ValueService $value;

    public function __construct(PDO $pdo)
    {
        $this->papers = new PaperService($pdo);
        $this->costs = new CostService($pdo);
        $this->value = new ValueService($pdo);
        $this->documents = new DocumentRepository($pdo);
        $this->vehicles = new VehicleService($pdo);
        $this->events = new EventService($pdo);
        $this->due = new DueService($pdo);
        $this->odometer = new OdometerService($pdo);
        $this->measurements = new MeasurementService($pdo);
        $this->tires = new TireService($pdo);
        $this->parts = new PartService($pdo);
        $this->damages = new DamageService($pdo);
        $this->notes = new NoteService($pdo);
        $this->openItems = new OpenItemService($pdo);
    }

    /**
     * Die Akte als Markdown. Sie entsteht aus denselben Abschnitten wie die Druckansicht (`sections`).
     *
     * @throws DomainError `not_found` für unbekannte und fremde Fahrzeuge
     */
    public function render(int $vehicleId, Actor $actor): string
    {
        $document = $this->document($vehicleId, $actor);
        $header = $document['header'];
        $parts = [sprintf(
            "# %s\n\nKennzeichen: %s · Status: %s · Stand: %s",
            $header['label'],
            $header['plate'] ?? self::NONE,
            $header['status'],
            DossierFormat::date($header['today'])
        )];
        foreach ($document['sections'] as $section) {
            $parts[] = self::markdownSection($section);
        }

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * Die Abschnitte der Akte in fester Reihenfolge. Jeder Abschnitt ist eine Liste von Blöcken:
     * `table` (headers, rows), `heading` (text), `text` (text, origin?, italic?), `list` (intro?, items[text, origin?, after?]),
     * `markdown` (body, Notiztext). Texte enthalten höchstens `**fett**`.
     *
     * @return list<array{title: string, blocks: list<array<string, mixed>>}>
     * @throws DomainError `not_found` für unbekannte und fremde Fahrzeuge
     */
    public function sections(int $vehicleId, Actor $actor): array
    {
        return $this->document($vehicleId, $actor)['sections'];
    }

    /**
     * Kopfdaten und Abschnitte der Akte. `header`: label, status, today, plate (null ohne gültige Zulassung),
     * first_registration (null ohne Angabe), km (null ohne Stand), km_estimated (Prognose statt Messwert).
     *
     * @return array{header: array<string, mixed>, sections: list<array{title: string, blocks: list<array<string, mixed>>}>}
     * @throws DomainError `not_found` für unbekannte und fremde Fahrzeuge
     */
    public function document(int $vehicleId, Actor $actor): array
    {
        $vehicle = $this->vehicles->get($vehicleId, $actor);
        $due = $this->due->dueItems($vehicleId, self::DUE_HORIZON_MONTHS, $actor);
        $today = $due['today'];
        $papers = $this->papers->forVehicle($vehicleId, $actor);

        return [
            'header' => $this->header($vehicle, $today),
            'sections' => [
                self::section('Stammdaten', $this->masterData($vehicle)),
                self::section('Zulassung', $this->registrations($vehicle)),
                self::section('Ausstattung', self::equipment($vehicle['equipment'])),
                self::section('Spezifikationen', self::specs($vehicle['specs'])),
                self::section('Kilometerstand', $this->odometerBlocks($vehicleId, $vehicle['km_estimate'], $actor)),
                self::section('Fälligkeiten', self::dueTable($due['items'])),
                self::section('Wartungsplan', self::maintenancePlan($due['items'])),
                self::section('Scheckheft', $this->serviceRecord($vehicleId, $actor)),
                self::section('Messwerte', $this->measurementBlocks($vehicleId, $actor)),
                self::section('Reifen', $this->tireBlocks($vehicleId, $actor)),
                self::section('Teile', $this->partBlocks($vehicleId, $actor)),
                self::section('Schäden', $this->damageBlocks($vehicleId, $actor)),
                self::section('Rückrufe', $this->recallBlocks($vehicleId, $actor)),
                self::section('Verträge', self::contractBlocks($papers['contracts'], $papers['deadlines'], $today)),
                self::section('Kosten', $this->costBlocks($vehicleId, $actor)),
                self::section('Wert', $this->valueBlocks($vehicleId, $actor)),
                self::section('Zubehör und Plaketten', self::paperBlocks($papers['accessories'], $papers['permits'])),
                self::section('Notizen', $this->noteBlocks($vehicleId, $actor)),
                self::section('Offene Klärfälle', $this->openItemBlocks($vehicleId, $actor)),
                self::section('Belege', $this->documentBlocks($vehicleId, $actor)),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array{title: string, blocks: list<array<string, mixed>>}
     */
    private static function section(string $title, array $blocks): array
    {
        return ['title' => $title, 'blocks' => $blocks];
    }

    /** @param array{title: string, blocks: list<array<string, mixed>>} $section */
    private static function markdownSection(array $section): string
    {
        $lines = [];
        foreach ($section['blocks'] as $index => $block) {
            if ($index > 0) {
                $lines[] = '';
            }
            array_push($lines, ...self::markdownLines($block));
        }
        $body = $section['blocks'] === [] ? DossierFormat::EMPTY_SECTION : implode("\n", $lines);

        return "## {$section['title']}\n\n{$body}";
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private static function markdownLines(array $block): array
    {
        switch ($block['kind']) {
            case 'table':
                return $block['rows'] === [] ? [] : DossierFormat::table($block['headers'], $block['rows']);
            case 'heading':
                return ['### ' . $block['text']];
            case 'text':
                $text = $block['text'] . ($block['origin'] === null ? '' : ' ' . $block['origin']);

                return [$block['italic'] ? '_' . $text . '_' : $text];
            case 'markdown':
                return [DossierFormat::shiftHeadings($block['body'], self::NOTE_HEADING_SHIFT)];
            default:
                $lines = $block['intro'] === null ? [] : [$block['intro']];
                foreach ($block['items'] as $item) {
                    $lines[] = '- ' . $item['text'] . ($item['origin'] === null ? '' : ' ' . $item['origin']) . ($item['after'] === null ? '' : ' ' . $item['after']);
                }

                return $lines;
        }
    }

    /**
     * @param list<string> $headers
     * @param list<list<string|null>> $rows
     * @return array<string, mixed>
     */
    private static function table(array $headers, array $rows): array
    {
        return ['kind' => 'table', 'headers' => $headers, 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private static function heading(string $text): array
    {
        return ['kind' => 'heading', 'text' => $text];
    }

    /** @return array<string, mixed> */
    private static function text(string $text, ?string $origin = null, bool $isItalic = false): array
    {
        return ['kind' => 'text', 'text' => $text, 'origin' => $origin, 'italic' => $isItalic];
    }

    /** @return array<string, string|null> */
    private static function item(string $text, ?string $origin = null, ?string $after = null): array
    {
        return ['text' => $text, 'origin' => $origin, 'after' => $after];
    }

    /**
     * @param list<array<string, string|null>> $items
     * @return array<string, mixed>
     */
    private static function items(array $items, ?string $intro = null): array
    {
        return ['kind' => 'list', 'intro' => $intro, 'items' => $items];
    }

    /**
     * @param array<string, mixed> $vehicle Ergebnis von `VehicleService::get`
     * @return array<string, mixed>
     */
    private function header(array $vehicle, string $today): array
    {
        $current = self::currentRegistration($vehicle['registrations'], $today);
        $forecast = $vehicle['km_estimate'];

        return [
            'label' => (string) $vehicle['vehicle']['label'],
            'status' => (string) $vehicle['vehicle']['status'],
            'today' => $today,
            'plate' => $current === null ? null : (string) $current['plate'],
            'first_registration' => isset($vehicle['facts']['erstzulassung']) ? (string) $vehicle['facts']['erstzulassung']['value'] : null,
            'km' => $forecast === null ? null : (int) $forecast['km_estimate'],
            'km_estimated' => $forecast !== null && $forecast['estimated'] === true,
        ];
    }

    /**
     * Die neueste Zulassung, die heute gilt (Ende leer oder nicht vor heute); die Liste ist neueste zuerst.
     *
     * @param list<array<string, mixed>> $registrations
     * @return array<string, mixed>|null
     */
    private static function currentRegistration(array $registrations, string $today): ?array
    {
        foreach ($registrations as $registration) {
            $isOpenEnded = $registration['valid_to'] === null || $registration['valid_to'] >= $today;
            if ($isOpenEnded && $registration['valid_from'] <= $today) {
                return $registration;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $vehicle
     * @return list<array<string, mixed>>
     */
    private function masterData(array $vehicle): array
    {
        $rows = [];
        foreach (self::orderedFields($vehicle['facts']) as $field) {
            $fact = $vehicle['facts'][$field];
            $rows[] = [self::LABELS[$field] ?? $field, self::factValue($field, $fact['value']), DossierFormat::provenanceOf($fact)];
        }

        $blocks = $rows === [] && $vehicle['disputed'] === [] ? [] : [self::table(['Feld', 'Wert', 'Herkunft'], $rows)];
        if ($vehicle['disputed'] !== []) {
            $items = [];
            foreach ($vehicle['disputed'] as $disputed) {
                $field = (string) $disputed['field'];
                $items[] = self::item(
                    sprintf('%s: %s', str_starts_with($field, 'spec.') ? $field : (self::LABELS[$field] ?? $field), self::factValue($field, $disputed['value'])),
                    DossierFormat::provenanceOf($disputed),
                    sprintf('— wartet auf Entscheidung, Klärfall #%d', $disputed['open_item_id'])
                );
            }
            $blocks[] = self::items($items, 'Strittig:');
        }

        return $blocks;
    }

    /**
     * Bekannte Felder in der Reihenfolge von `LABELS`, danach unbekannte alphabetisch.
     *
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function orderedFields(array $facts): array
    {
        $known = array_values(array_filter(array_keys(self::LABELS), static fn (string $field): bool => isset($facts[$field])));
        $unknown = array_values(array_diff(array_keys($facts), array_keys(self::LABELS)));
        sort($unknown);

        return [...$known, ...$unknown];
    }

    private static function factValue(string $field, int|string $value): string
    {
        if (in_array($field, self::DATE_FIELDS, true)) {
            return DossierFormat::date((string) $value);
        }
        if (in_array($field, self::INTEGER_FIELDS, true)) {
            return DossierFormat::number($value);
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $vehicle
     * @return list<array<string, mixed>>
     */
    private function registrations(array $vehicle): array
    {
        if ($vehicle['registrations'] === []) {
            return [];
        }
        $rows = [];
        foreach ($vehicle['registrations'] as $registration) {
            $rows[] = [
                DossierFormat::date($registration['valid_from']),
                DossierFormat::date($registration['valid_to']),
                $registration['plate'],
                $registration['holder'],
                DossierFormat::provenanceOf($registration),
            ];
        }

        return [self::table(['Von', 'Bis', 'Kennzeichen', 'Halter', 'Herkunft'], $rows), self::text('Halterzahl: ' . $vehicle['holder_count'])];
    }

    /**
     * @param list<array<string, mixed>> $equipment
     * @return list<array<string, mixed>>
     */
    private static function equipment(array $equipment): array
    {
        $blocks = [];
        foreach (self::EQUIPMENT_GROUPS as $category => $title) {
            $group = array_values(array_filter($equipment, static fn (array $item): bool => $item['category'] === $category));
            if ($group === []) {
                continue;
            }
            $items = [];
            foreach ($group as $item) {
                $price = $item['list_price_eur'] === null ? '' : ' — Neupreis ' . DossierFormat::euro($item['list_price_eur']);
                $items[] = self::item(
                    sprintf('%s%s%s', $item['code'] === null ? '' : $item['code'] . ' · ', $item['name'], $price),
                    DossierFormat::provenanceOf($item)
                );
            }
            $blocks[] = self::items($items, $title . ':');
        }

        return $blocks;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $specs
     * @return list<array<string, mixed>>
     */
    private static function specs(array $specs): array
    {
        ksort($specs);
        $blocks = [];
        foreach ($specs as $area => $entries) {
            ksort($entries);
            $items = [];
            foreach ($entries as $key => $entry) {
                $items[] = self::item(sprintf('%s: %s', $key, $entry['value']), DossierFormat::provenanceOf($entry));
            }
            $blocks[] = self::heading((string) $area);
            $blocks[] = self::items($items);
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed>|null $forecast
     * @return list<array<string, mixed>>
     */
    private function odometerBlocks(int $vehicleId, ?array $forecast, Actor $actor): array
    {
        $latest = $this->odometer->latestValid($vehicleId, $actor);
        if ($latest === null || $forecast === null) {
            return [];
        }

        $lines = [self::text(
            sprintf('Letzter Stand: %s km am %s', DossierFormat::number($latest['km']), DossierFormat::date($latest['read_on'])),
            DossierFormat::provenanceOf($latest)
        )];
        if ($forecast['estimated'] === false || $forecast['per_day'] === null) {
            $lines[] = self::text('Prognose: keine Hochrechnung möglich (zu wenige Stände); heute gilt der letzte Stand.');

            return $lines;
        }
        $lines[] = self::text(sprintf(
            'Prognose: heute %s %s km (%s %s km/Tag, aus %s bis %s)',
            DossierFormat::APPROXIMATE,
            DossierFormat::number($forecast['km_estimate']),
            DossierFormat::APPROXIMATE,
            DossierFormat::decimal((float) $forecast['per_day']),
            DossierFormat::date($forecast['basis']['from']['date'] ?? null),
            DossierFormat::date($forecast['basis']['to']['date'])
        ));

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function dueTable(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $rows = [];
        foreach ($items as $item) {
            $status = self::STATUS_WORDS[$item['status']] ?? (string) $item['status'];
            if ($item['rule_uncertain'] === true) {
                $status .= ' · Regel unbelegt';
            }
            $rows[] = [(string) $item['title'], self::dueWhen($item), $status, (string) $item['explanation']];
        }

        return [self::table(['Aufgabe', 'Fällig', 'Status', 'Rechenweg'], $rows)];
    }

    /**
     * Ein Datum, das aus der Kilometerprognose stammt, ist geschätzt und trägt „≈“.
     *
     * @param array<string, mixed> $item
     */
    private static function dueWhen(array $item): string
    {
        $parts = [];
        if ($item['effective_date'] !== null) {
            $isEstimated = $item['effective_date'] === $item['km_due_date'] && $item['km_due_date'] !== $item['due_date'];
            $parts[] = ($isEstimated ? DossierFormat::APPROXIMATE . ' ' : '') . DossierFormat::date($item['effective_date']);
        }
        if ($item['due_km'] !== null) {
            $parts[] = 'bei ' . DossierFormat::number($item['due_km']) . ' km';
        }

        return $parts === [] ? self::NONE : implode(' / ', $parts);
    }

    /**
     * @param list<array<string, mixed>> $items Aufgaben aus der Fälligkeitsrechnung
     * @return list<array<string, mixed>>
     */
    private static function maintenancePlan(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rule = $item['rule'];
            $rows[] = $rule === null
                ? [(string) $item['title'], 'Keine Regel hinterlegt', null, null]
                : [(string) $item['title'], self::ruleText($rule), (string) $rule['mode'], DossierFormat::provenanceOf($rule)];
        }

        return $rows === [] ? [] : [self::table(['Aufgabe', 'Regel', 'Modus', 'Herkunft'], $rows)];
    }

    /** @param array<string, mixed> $rule */
    private static function ruleText(array $rule): string
    {
        $text = 'alle ' . self::interval($rule['interval_km'], $rule['interval_months']);
        if ($rule['first_km'] !== null || $rule['first_months'] !== null) {
            $text .= '; erstmals nach ' . self::interval($rule['first_km'], $rule['first_months']);
        }

        return $text;
    }

    private static function interval(?int $kilometers, ?int $months): string
    {
        $parts = [];
        if ($kilometers !== null) {
            $parts[] = DossierFormat::number($kilometers) . ' km';
        }
        if ($months !== null) {
            $parts[] = $months . ' Monate';
        }

        return $parts === [] ? self::NONE : implode(' / ', $parts);
    }

    /** @return list<array<string, mixed>> */
    private function serviceRecord(int $vehicleId, Actor $actor): array
    {
        $blocks = [];
        foreach ($this->events->list($vehicleId, null, null, null, $actor) as $summary) {
            $event = $this->events->get($summary['id'], $actor);
            $blocks = [...$blocks, ...self::eventBlocks($event)];
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $event
     * @return list<array<string, mixed>>
     */
    private static function eventBlocks(array $event): array
    {
        $details = [];
        if ($event['workshop'] !== null) {
            $details[] = 'Werkstatt: ' . $event['workshop']['name'] . ', ' . $event['workshop']['city'];
        }
        if ($event['odometer_km'] !== null) {
            $details[] = 'Kilometerstand: ' . DossierFormat::number($event['odometer_km']) . ' km';
        }
        if ($event['invoice_number'] !== null) {
            $invoiceDate = $event['invoiced_on'] === null ? '' : ' vom ' . DossierFormat::date($event['invoiced_on']);
            $details[] = 'Rechnung: ' . $event['invoice_number'] . $invoiceDate;
        }
        if ($event['gross_eur'] !== null) {
            $details[] = 'Brutto: ' . DossierFormat::euro($event['gross_eur']);
        }
        $details[] = DossierFormat::provenanceOf($event);

        $lines = [
            self::heading(sprintf('%s · %s · %s', DossierFormat::date($event['performed_on']), DossierFormat::humanize((string) $event['kind']), $event['title'])),
            self::text(implode(' · ', $details)),
        ];
        if ($event['items'] === []) {
            return $lines;
        }
        $rows = [];
        foreach ($event['items'] as $item) {
            $quantity = $item['quantity'] === null ? null : DossierFormat::decimal((float) $item['quantity']) . ($item['unit'] === null ? '' : ' ' . $item['unit']);
            $rows[] = [$item['type'], $item['description'], $item['part_number'], $quantity, DossierFormat::euro($item['net_eur'])];
        }

        return [...$lines, self::table(['Typ', 'Beschreibung', 'Teilenummer', 'Menge', 'Netto'], $rows)];
    }

    /** @return list<array<string, mixed>> */
    private function measurementBlocks(int $vehicleId, Actor $actor): array
    {
        $blocks = [];
        foreach ($this->measurements->series($vehicleId, null, $actor)['series'] as $series) {
            $points = array_reverse($series['points']);
            $latest = $points[0];
            $unit = (string) $series['unit'];
            $kilometers = $latest['odometer_km'] === null ? '' : ', ' . DossierFormat::number($latest['odometer_km']) . ' km';

            $blocks[] = self::heading(self::seriesTitle($series));
            $blocks[] = self::text(
                sprintf('Jüngster Wert: %s %s am %s%s', DossierFormat::decimal($latest['value']), $unit, DossierFormat::date($latest['date']), $kilometers),
                DossierFormat::provenanceOf($latest)
            );
            if (count($points) > 1) {
                $items = [];
                foreach ($points as $point) {
                    $items[] = self::item(sprintf('%s: %s %s', DossierFormat::date($point['date']), DossierFormat::decimal($point['value']), $unit));
                }
                $blocks[] = self::items($items, 'Verlauf:');
            }
        }

        return $blocks;
    }

    /** @param array<string, mixed> $series */
    private static function seriesTitle(array $series): string
    {
        $name = $series['label'] !== '' ? (string) $series['label'] : DossierFormat::humanize((string) $series['quantity']);
        $position = self::POSITION_WORDS[$series['position']] ?? null;

        return $position === null ? $name : "{$name}, {$position}";
    }

    /** @return list<array<string, mixed>> */
    private function tireBlocks(int $vehicleId, Actor $actor): array
    {
        $tires = $this->tires->list($vehicleId, $actor);
        $items = [];
        foreach ($tires['sets'] as $set) {
            $isMounted = $set['id'] === $tires['mounted_set_id'];
            $details = array_values(array_filter([
                trim(($set['brand'] ?? '') . ' ' . ($set['model'] ?? '')),
                $set['size'],
                $set['season'],
                $set['dot'] === null ? null : sprintf('DOT %s (%d Jahre alt)', $set['dot'], $set['dot_age_years']),
                $set['storage_location'] === null ? null : 'Lager: ' . $set['storage_location'],
                self::currentMount($set['mounts']),
            ], static fn (?string $detail): bool => $detail !== null && $detail !== ''));
            $items[] = self::item(
                sprintf('**%s**%s — %s', $set['label'], $isMounted ? ' (**montiert**)' : '', implode(', ', $details)),
                DossierFormat::provenanceOf($set)
            );
        }

        return $items === [] ? [] : [self::items($items)];
    }

    /** @param list<array<string, mixed>> $mounts */
    private static function currentMount(array $mounts): ?string
    {
        foreach ($mounts as $mount) {
            if ($mount['removed_on'] === null) {
                $kilometers = $mount['mounted_km'] === null ? '' : ' bei ' . DossierFormat::number($mount['mounted_km']) . ' km';

                return 'montiert seit ' . DossierFormat::date($mount['mounted_on']) . $kilometers;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function partBlocks(int $vehicleId, Actor $actor): array
    {
        $blocks = [];
        foreach ($this->parts->grouped($vehicleId, $actor)['components'] as $component) {
            $blocks[] = self::heading((string) $component['component']);
            $blocks[] = self::items(array_map(self::partItem(...), $component['parts']));
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $part
     * @return array<string, string|null>
     */
    private static function partItem(array $part): array
    {
        $role = $part['role'] === 'verbaut' ? 'verbaut' : 'Alternative';
        $identity = array_values(array_filter([
            $part['brand'],
            $part['article_number'],
            $part['oem_number'] === null ? null : 'OEM ' . $part['oem_number'],
        ], static fn (?string $value): bool => $value !== null));
        $details = [$identity === [] ? 'ohne Angaben' : implode(' ', $identity)];
        if ($part['verified'] !== null) {
            $details[] = $part['verified'] ? 'Passform geprüft' : 'Passform nicht geprüft';
        }
        if ($part['note'] !== null) {
            $details[] = $part['note'];
        }

        return self::item(sprintf('%s: %s', $role, implode(', ', $details)), DossierFormat::provenanceOf($part));
    }

    /** @return list<array<string, mixed>> */
    private function damageBlocks(int $vehicleId, Actor $actor): array
    {
        $items = [];
        foreach ($this->damages->listDamages($vehicleId, $actor) as $damage) {
            $details = [];
            if ($damage['odometer_km'] !== null) {
                $details[] = DossierFormat::number($damage['odometer_km']) . ' km';
            }
            if ($damage['repaired'] !== null) {
                $details[] = $damage['repaired'] ? 'repariert' : 'nicht repariert';
            }
            if ($damage['appraisal'] !== null) {
                $details[] = $damage['appraisal'] ? 'Gutachten liegt vor' : 'ohne Gutachten';
            }
            if ($damage['diminished_value_eur'] !== null) {
                $details[] = 'Wertminderung ' . DossierFormat::euro($damage['diminished_value_eur']);
            }
            $items[] = self::item(
                sprintf(
                    '%s · %s · %s%s',
                    DossierFormat::date($damage['occurred_on']),
                    DossierFormat::humanize((string) $damage['kind']),
                    $damage['description'],
                    $details === [] ? '' : ' — ' . implode(', ', $details)
                ),
                DossierFormat::provenanceOf($damage)
            );
        }

        return $items === [] ? [] : [self::items($items)];
    }

    /** @return list<array<string, mixed>> */
    private function recallBlocks(int $vehicleId, Actor $actor): array
    {
        $items = [];
        foreach ($this->damages->listRecalls($vehicleId, $actor) as $recall) {
            $references = array_values(array_filter([
                $recall['manufacturer_code'] === null ? null : 'Hersteller-Code ' . $recall['manufacturer_code'],
                $recall['kba_reference'] === null ? null : 'KBA ' . $recall['kba_reference'],
            ], static fn (?string $reference): bool => $reference !== null));
            $status = self::RECALL_STATUS_WORDS[$recall['status']] ?? (string) $recall['status'];
            $doneOn = $recall['done_on'] === null ? '' : ' am ' . DossierFormat::date($recall['done_on']);
            $items[] = self::item(
                sprintf('%s%s — %s%s', $recall['title'], $references === [] ? '' : ' (' . implode(', ', $references) . ')', $status, $doneOn),
                DossierFormat::provenanceOf($recall)
            );
        }

        return $items === [] ? [] : [self::items($items)];
    }

    /** @return list<array<string, mixed>> */
    private function noteBlocks(int $vehicleId, Actor $actor): array
    {
        $blocks = [];
        foreach ($this->notes->listCurrent($vehicleId, $actor) as $note) {
            $blocks[] = self::heading((string) $note['topic']);
            $blocks[] = ['kind' => 'markdown', 'body' => (string) $note['body']];
            $blocks[] = self::text(sprintf('Revision %d, %s, %s', $note['revision'], $note['actor'], DossierFormat::date($note['updated_at'])), null, true);
        }

        return $blocks;
    }

    /**
     * Belege des Fahrzeugs als Tabelle, ohne Links: die Akte bleibt, Links laufen ab.
     *
     * @return list<array<string, mixed>>
     */
    private function documentBlocks(int $vehicleId, Actor $actor): array
    {
        $documents = $this->documents->list($actor, $vehicleId, null, null);
        if ($documents === []) {
            return [];
        }

        $attachments = $this->documents->attachmentsForMany(array_map(static fn (array $document): int => $document['id'], $documents), $actor);
        $rows = [];
        foreach ($documents as $document) {
            $attached = array_map(
                static fn (array $attachment): string => (self::ATTACHMENT_WORDS[$attachment['target_table']] ?? $attachment['target_table']) . ' #' . $attachment['target_id'],
                $attachments[$document['id']] ?? []
            );
            $rows[] = [
                DossierFormat::date($document['captured_on'] ?? substr((string) $document['created_at'], 0, 10)),
                DossierFormat::humanize($document['doc_type']),
                $document['title'] !== '' ? $document['title'] : null,
                $attached === [] ? ($document['status'] === DocumentRepository::STATUS_WAITING ? 'Original fehlt' : 'noch nichts') : implode(', ', array_values(array_unique($attached))),
            ];
        }

        return [self::table(['Datum', 'Typ', 'Titel', 'hängt an'], $rows)];
    }

    /**
     * Tabelle der Verträge (eigene und die der Garage) mit Beitrag je Jahr, Hauptfälligkeit und Kündigungsstichtag; darunter
     * die offenen Vorgänge (Schadenmeldung, Änderung, Kündigung) als Liste.
     *
     * @param list<array<string, mixed>> $contracts Ausgabe von `PaperService::forVehicle`
     * @param list<array<string, mixed>> $deadlines
     * @return list<array<string, mixed>>
     */
    private static function contractBlocks(array $contracts, array $deadlines, string $today): array
    {
        if ($contracts === []) {
            return [];
        }

        $noticeDates = [];
        foreach ($deadlines as $deadline) {
            if ($deadline['kind'] === DeadlineService::KIND_NOTICE && $deadline['ref']['type'] === DeadlineService::REF_CONTRACT) {
                $noticeDates[$deadline['ref']['id']] = $deadline['date'];
            }
        }

        $rows = [];
        $openEvents = [];
        foreach ($contracts as $contract) {
            $kind = DossierFormat::humanize((string) $contract['kind']);
            $rows[] = [
                $contract['level'] === ContractService::LEVEL_GARAGE ? $kind . ' (Garage)' : $kind,
                $contract['provider'],
                $contract['contract_number'],
                self::yearlyAmount($contract, $today),
                self::mainDueText($contract['main_due']),
                DossierFormat::date($noticeDates[$contract['id']] ?? null),
            ];
            foreach ($contract['events'] as $event) {
                if ($event['event_status'] !== self::CONTRACT_EVENT_DONE) {
                    $openEvents[] = self::openEventItem($contract, $event);
                }
            }
        }

        $blocks = [self::table(['Art', 'Anbieter', 'Nummer', 'Beitrag/Jahr', 'Hauptfälligkeit', 'Kündigungsstichtag'], $rows)];
        if ($openEvents !== []) {
            $blocks[] = self::items($openEvents, 'Offene Vorgänge:');
        }

        return $blocks;
    }

    /**
     * Beitrag je Jahr: bei Garagen-Verträgen der Anteil dieses Fahrzeugs, sonst die heute gültige (sonst jüngste) Periode auf 365 Tage gerechnet.
     *
     * @param array<string, mixed> $contract
     */
    private static function yearlyAmount(array $contract, string $today): ?string
    {
        if ($contract['level'] === ContractService::LEVEL_GARAGE) {
            return $contract['share']['eur_per_year'] === null ? null : DossierFormat::euro($contract['share']['eur_per_year']) . ' (Anteil)';
        }
        if ($contract['terms'] === []) {
            return null;
        }
        $term = $contract['terms'][0];
        foreach ($contract['terms'] as $candidate) {
            if ($candidate['valid_from'] <= $today && $candidate['valid_to'] >= $today) {
                $term = $candidate;
                break;
            }
        }
        $days = (int) (new DateTimeImmutable($term['valid_from']))->diff(new DateTimeImmutable($term['valid_to']))->days + 1;

        return DossierFormat::euro(number_format((float) $term['amount_eur'] * self::DAYS_PER_YEAR / $days, 2, '.', ''));
    }

    /** Die Hauptfälligkeit liegt als `MM-TT` ohne Jahr vor und steht als `TT.MM.`. */
    private static function mainDueText(?string $mainDue): ?string
    {
        if ($mainDue === null) {
            return null;
        }
        [$month, $day] = explode('-', $mainDue);

        return $day . '.' . $month . '.';
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $event
     * @return array<string, string|null>
     */
    private static function openEventItem(array $contract, array $event): array
    {
        $details = [DossierFormat::humanize((string) $event['kind']), DossierFormat::date($event['occurred_on'])];
        if ($event['claim_number'] !== null) {
            $details[] = 'Aktenzeichen ' . $event['claim_number'];
        }
        if ($event['amount_eur'] !== null) {
            $details[] = DossierFormat::euro($event['amount_eur']);
        }

        return self::item(
            sprintf('%s: %s — %s', $contract['provider'], implode(', ', $details), DossierFormat::humanize((string) $event['event_status'])),
            DossierFormat::provenanceOf($event)
        );
    }

    /**
     * Kosten der letzten 12 Monate nach Block, dazu Kilometerpreis und fehlende Bausteine. Leer, solange das Fahrzeug nicht aktiv war.
     *
     * @return list<array<string, mixed>>
     */
    private function costBlocks(int $vehicleId, Actor $actor): array
    {
        $summary = $this->costs->summary($vehicleId, CostCalculator::PERIOD_12_MONTHS, CostCalculator::GROUP_BLOCK, $actor);
        if ($summary['active'] === null) {
            return [];
        }

        // Je Monat teilt jeder Block durch denselben Divisor wie die Monatssumme: Tage des Zeitraums ÷ 30,4375.
        $monthsPerFull = $summary['full_cents'] > 0 ? $summary['month_full_cents'] / $summary['full_cents'] : 0;
        $rows = [];
        foreach (CostCalculator::BLOCK_NAMES as $block => $name) {
            $cents = $summary['blocks'][$block]['cents'];
            $rows[] = [$name, self::cents($cents), self::cents((int) round($cents * $monthsPerFull))];
        }
        $rows[] = ['Summe laufend', self::cents($summary['running_cents']), self::cents($summary['month_running_cents'])];
        $rows[] = ['Summe mit Wertverlust', self::cents($summary['full_cents']), self::cents($summary['month_full_cents'])];

        $blocks = [self::text('Letzte 12 Monate (Stand heute).'), self::table(['Block', 'Betrag', 'je Monat'], $rows)];
        if ($summary['per_km_full_cents'] === null) {
            $blocks[] = self::text('Pro km: nicht berechenbar, es fehlt ein Kilometerstand.');
        } else {
            $blocks[] = self::text(sprintf('Pro km (mit Wertverlust): %s bei %s km.', DossierFormat::euro($summary['per_km_full_cents']), DossierFormat::number($summary['km'])));
        }
        if ($summary['missing'] !== []) {
            $blocks[] = self::text('Fehlt: ' . implode(', ', $summary['missing']) . '.');
        }

        return $blocks;
    }

    private static function cents(int $cents): string
    {
        return DossierFormat::euro(Money::centsToEuro($cents));
    }

    /**
     * Ankerwerte als Tabelle, Marktkorridor als Satz und die Wertfaktoren als Liste mit +, − oder ?.
     *
     * @return list<array<string, mixed>>
     */
    private function valueBlocks(int $vehicleId, Actor $actor): array
    {
        $summary = $this->value->summary($vehicleId, $actor);

        $blocks = [];
        if ($summary['anchors'] !== []) {
            $rows = [];
            foreach ($summary['anchors'] as $anchor) {
                $rows[] = [
                    DossierFormat::date($anchor['valued_on']),
                    DossierFormat::humanize($anchor['kind']),
                    self::anchorAmount($anchor),
                    $anchor['odometer_km'] === null ? null : DossierFormat::number($anchor['odometer_km']) . ' km',
                    $anchor['provider'],
                    DossierFormat::provenanceOf($anchor),
                ];
            }
            $blocks[] = self::table(['Datum', 'Art', 'Betrag', 'Kilometerstand', 'Anbieter', 'Herkunft'], $rows);
        }

        $blocks[] = self::text(self::corridorSentence($summary['corridor']) . ' ' . $summary['note']);

        $items = [];
        foreach ($summary['factors'] as $factor) {
            $items[] = self::item(sprintf('%s **%s:** %s', self::VERDICT_MARKS[$factor['verdict']] ?? '?', $factor['label'], $factor['detail']));
        }
        $blocks[] = self::items($items, 'Wertfaktoren:');

        return $blocks;
    }

    /** @param array<string, mixed> $anchor */
    private static function anchorAmount(array $anchor): string
    {
        $amount = self::cents($anchor['amount_cents']);
        if ($anchor['range_low_cents'] === null || $anchor['range_high_cents'] === null) {
            return $amount;
        }

        return sprintf('%s (%s bis %s)', $amount, self::cents($anchor['range_low_cents']), self::cents($anchor['range_high_cents']));
    }

    /** @param array<string, mixed> $corridor */
    private static function corridorSentence(array $corridor): string
    {
        if ($corridor['median_cents'] === null) {
            return sprintf('Marktkorridor: noch keiner, es zählen %d von %d nötigen Inseraten.', $corridor['counting'], $corridor['needed_for_corridor']);
        }

        return sprintf(
            'Marktkorridor heute: %s bis %s, Mitte %s, aus %d Inseraten (Angebotspreise).',
            self::cents($corridor['low_cents']),
            self::cents($corridor['high_cents']),
            self::cents($corridor['median_cents']),
            $corridor['counting']
        );
    }

    /**
     * Zubehör und Plaketten, je als Tabelle; ein leerer Teil trägt den kursiven Satz der leeren Abschnitte.
     *
     * @param list<array<string, mixed>> $accessories
     * @param list<array<string, mixed>> $permits
     * @return list<array<string, mixed>>
     */
    private static function paperBlocks(array $accessories, array $permits): array
    {
        if ($accessories === [] && $permits === []) {
            return [];
        }

        $accessoryRows = [];
        foreach ($accessories as $accessory) {
            $accessoryRows[] = [
                $accessory['name'],
                DossierFormat::humanize($accessory['category']),
                $accessory['manufacturer'],
                DossierFormat::date($accessory['bought_on']),
                DossierFormat::euro($accessory['price_eur']),
                DossierFormat::date($accessory['warranty_until']),
                $accessory['mount_state'] === null ? null : DossierFormat::humanize($accessory['mount_state']),
                $accessory['storage_location'],
                $accessory['goes_with_sale'] === null ? null : ($accessory['goes_with_sale'] ? 'ja' : 'nein'),
            ];
        }
        $permitRows = [];
        foreach ($permits as $permit) {
            $permitRows[] = [
                DossierFormat::humanize($permit['kind']),
                $permit['label'],
                $permit['number'],
                DossierFormat::date($permit['valid_from']),
                DossierFormat::date($permit['valid_until']),
                DossierFormat::euro($permit['price_eur']),
            ];
        }

        return [
            self::heading('Zubehör'),
            $accessoryRows === []
                ? self::text(DossierFormat::EMPTY_SECTION, null, true)
                : self::table(['Name', 'Kategorie', 'Hersteller', 'Gekauft', 'Preis', 'Garantie bis', 'Zustand', 'Lager', 'Mit Verkauf'], $accessoryRows),
            self::heading('Plaketten'),
            $permitRows === []
                ? self::text(DossierFormat::EMPTY_SECTION, null, true)
                : self::table(['Art', 'Bezeichnung', 'Nummer', 'Gültig von', 'Gültig bis', 'Preis'], $permitRows),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function openItemBlocks(int $vehicleId, Actor $actor): array
    {
        $items = [];
        foreach ($this->openItems->list($vehicleId, OpenItemRepository::STATUS_OPEN, $actor) as $item) {
            $items[] = self::item(sprintf('#%d (%s): %s', $item['id'], $item['kind'], preg_replace('/\s*[\r\n]+\s*/', ' ', (string) $item['text'])));
        }

        return $items === [] ? [] : [self::items($items)];
    }
}
