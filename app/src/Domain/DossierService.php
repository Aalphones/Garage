<?php

declare(strict_types=1);

namespace Garage\Domain;

use PDO;

/**
 * Die Akte eines Fahrzeugs als Markdown, Aufbau fest (16 Abschnitte). Liest nur über die Dienste der Bereiche — jeder prüft
 * den Zugriff selbst — und rechnet nichts Eigenes; Fälligkeit und Prognose kommen aus `DueService`.
 */
final class DossierService
{
    private const DUE_HORIZON_MONTHS = 120;
    private const NOTE_HEADING_SHIFT = 2;
    private const NONE = '–';

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

    public function __construct(PDO $pdo)
    {
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

    /** @throws DomainError `not_found` für unbekannte und fremde Fahrzeuge */
    public function render(int $vehicleId, Actor $actor): string
    {
        $vehicle = $this->vehicles->get($vehicleId, $actor);
        $due = $this->due->dueItems($vehicleId, self::DUE_HORIZON_MONTHS, $actor);
        $today = $due['today'];

        $blocks = [
            $this->header($vehicle, $today),
            self::section('Stammdaten', $this->masterData($vehicle)),
            self::section('Zulassung', $this->registrations($vehicle)),
            self::section('Ausstattung', self::equipment($vehicle['equipment'])),
            self::section('Spezifikationen', self::specs($vehicle['specs'])),
            self::section('Kilometerstand', $this->odometerLines($vehicleId, $vehicle['km_estimate'], $actor)),
            self::section('Fälligkeiten', self::dueTable($due['items'])),
            self::section('Wartungsplan', self::maintenancePlan($due['items'])),
            self::section('Scheckheft', $this->serviceRecord($vehicleId, $actor)),
            self::section('Messwerte', $this->measurementLines($vehicleId, $actor)),
            self::section('Reifen', $this->tireLines($vehicleId, $actor)),
            self::section('Teile', $this->partLines($vehicleId, $actor)),
            self::section('Schäden', $this->damageLines($vehicleId, $actor)),
            self::section('Rückrufe', $this->recallLines($vehicleId, $actor)),
            self::section('Notizen', $this->noteLines($vehicleId, $actor)),
            self::section('Offene Klärfälle', $this->openItemLines($vehicleId, $actor)),
            self::section('Belege', $this->documentLines($vehicleId, $actor)),
        ];

        return implode("\n\n", $blocks) . "\n";
    }

    /** @param list<string> $lines */
    private static function section(string $title, array $lines): string
    {
        $body = $lines === [] ? DossierFormat::EMPTY_SECTION : implode("\n", $lines);

        return "## {$title}\n\n{$body}";
    }

    /** @param array<string, mixed> $vehicle Ergebnis von `VehicleService::get` */
    private function header(array $vehicle, string $today): string
    {
        $current = self::currentRegistration($vehicle['registrations'], $today);
        $plate = $current === null ? self::NONE : (string) $current['plate'];

        return sprintf(
            "# %s\n\nKennzeichen: %s · Status: %s · Stand: %s",
            $vehicle['vehicle']['label'],
            $plate,
            $vehicle['vehicle']['status'],
            DossierFormat::date($today)
        );
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
     * @return list<string>
     */
    private function masterData(array $vehicle): array
    {
        $rows = [];
        foreach (self::orderedFields($vehicle['facts']) as $field) {
            $fact = $vehicle['facts'][$field];
            $rows[] = [self::LABELS[$field] ?? $field, self::factValue($field, $fact['value']), DossierFormat::provenanceOf($fact)];
        }

        $lines = $rows === [] ? [] : DossierFormat::table(['Feld', 'Wert', 'Herkunft'], $rows);
        if ($vehicle['disputed'] !== []) {
            $lines[] = '';
            $lines[] = 'Strittig:';
            foreach ($vehicle['disputed'] as $disputed) {
                $field = (string) $disputed['field'];
                $lines[] = sprintf(
                    '- %s: %s %s — wartet auf Entscheidung, Klärfall #%d',
                    str_starts_with($field, 'spec.') ? $field : (self::LABELS[$field] ?? $field),
                    self::factValue($field, $disputed['value']),
                    DossierFormat::provenanceOf($disputed),
                    $disputed['open_item_id']
                );
            }
        }

        return $lines;
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
     * @return list<string>
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

        return [...DossierFormat::table(['Von', 'Bis', 'Kennzeichen', 'Halter', 'Herkunft'], $rows), '', 'Halterzahl: ' . $vehicle['holder_count']];
    }

    /**
     * @param list<array<string, mixed>> $equipment
     * @return list<string>
     */
    private static function equipment(array $equipment): array
    {
        $lines = [];
        foreach (self::EQUIPMENT_GROUPS as $category => $title) {
            $items = array_values(array_filter($equipment, static fn (array $item): bool => $item['category'] === $category));
            if ($items === []) {
                continue;
            }
            $lines[] = $title . ':';
            foreach ($items as $item) {
                $price = $item['list_price_eur'] === null ? '' : ' — Neupreis ' . DossierFormat::euro($item['list_price_eur']);
                $lines[] = sprintf(
                    '- %s%s%s %s',
                    $item['code'] === null ? '' : $item['code'] . ' · ',
                    $item['name'],
                    $price,
                    DossierFormat::provenanceOf($item)
                );
            }
            $lines[] = '';
        }

        return $lines === [] ? [] : array_slice($lines, 0, -1);
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $specs
     * @return list<string>
     */
    private static function specs(array $specs): array
    {
        ksort($specs);
        $lines = [];
        foreach ($specs as $area => $entries) {
            ksort($entries);
            $lines[] = "### {$area}";
            $lines[] = '';
            foreach ($entries as $key => $entry) {
                $lines[] = sprintf('- %s: %s %s', $key, $entry['value'], DossierFormat::provenanceOf($entry));
            }
            $lines[] = '';
        }

        return $lines === [] ? [] : array_slice($lines, 0, -1);
    }

    /**
     * @param array<string, mixed>|null $forecast
     * @return list<string>
     */
    private function odometerLines(int $vehicleId, ?array $forecast, Actor $actor): array
    {
        $latest = $this->odometer->latestValid($vehicleId, $actor);
        if ($latest === null || $forecast === null) {
            return [];
        }

        $lines = [sprintf(
            'Letzter Stand: %s km am %s %s',
            DossierFormat::number($latest['km']),
            DossierFormat::date($latest['read_on']),
            DossierFormat::provenanceOf($latest)
        )];
        $lines[] = '';
        if ($forecast['estimated'] === false || $forecast['per_day'] === null) {
            $lines[] = 'Prognose: keine Hochrechnung möglich (zu wenige Stände); heute gilt der letzte Stand.';

            return $lines;
        }
        $lines[] = sprintf(
            'Prognose: heute %s %s km (%s %s km/Tag, aus %s bis %s)',
            DossierFormat::APPROXIMATE,
            DossierFormat::number($forecast['km_estimate']),
            DossierFormat::APPROXIMATE,
            DossierFormat::decimal((float) $forecast['per_day']),
            DossierFormat::date($forecast['basis']['from']['date'] ?? null),
            DossierFormat::date($forecast['basis']['to']['date'])
        );

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<string>
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

        return DossierFormat::table(['Aufgabe', 'Fällig', 'Status', 'Rechenweg'], $rows);
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
     * @return list<string>
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

        return $rows === [] ? [] : DossierFormat::table(['Aufgabe', 'Regel', 'Modus', 'Herkunft'], $rows);
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

    /** @return list<string> */
    private function serviceRecord(int $vehicleId, Actor $actor): array
    {
        $lines = [];
        foreach ($this->events->list($vehicleId, null, null, null, $actor) as $summary) {
            $event = $this->events->get($summary['id'], $actor);
            $lines = [...$lines, ...self::eventBlock($event), ''];
        }

        return $lines === [] ? [] : array_slice($lines, 0, -1);
    }

    /**
     * @param array<string, mixed> $event
     * @return list<string>
     */
    private static function eventBlock(array $event): array
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
            sprintf('### %s · %s · %s', DossierFormat::date($event['performed_on']), DossierFormat::humanize((string) $event['kind']), $event['title']),
            '',
            implode(' · ', $details),
        ];
        if ($event['items'] === []) {
            return $lines;
        }
        $rows = [];
        foreach ($event['items'] as $item) {
            $quantity = $item['quantity'] === null ? null : DossierFormat::decimal((float) $item['quantity']) . ($item['unit'] === null ? '' : ' ' . $item['unit']);
            $rows[] = [$item['type'], $item['description'], $item['part_number'], $quantity, DossierFormat::euro($item['net_eur'])];
        }

        return [...$lines, '', ...DossierFormat::table(['Typ', 'Beschreibung', 'Teilenummer', 'Menge', 'Netto'], $rows)];
    }

    /** @return list<string> */
    private function measurementLines(int $vehicleId, Actor $actor): array
    {
        $lines = [];
        foreach ($this->measurements->series($vehicleId, null, $actor)['series'] as $series) {
            $points = array_reverse($series['points']);
            $latest = $points[0];
            $unit = (string) $series['unit'];
            $kilometers = $latest['odometer_km'] === null ? '' : ', ' . DossierFormat::number($latest['odometer_km']) . ' km';

            $lines[] = '### ' . self::seriesTitle($series);
            $lines[] = '';
            $lines[] = sprintf(
                'Jüngster Wert: %s %s am %s%s %s',
                DossierFormat::decimal($latest['value']),
                $unit,
                DossierFormat::date($latest['date']),
                $kilometers,
                DossierFormat::provenanceOf($latest)
            );
            if (count($points) > 1) {
                $lines[] = '';
                $lines[] = 'Verlauf:';
                foreach ($points as $point) {
                    $lines[] = sprintf('- %s: %s %s', DossierFormat::date($point['date']), DossierFormat::decimal($point['value']), $unit);
                }
            }
            $lines[] = '';
        }

        return $lines === [] ? [] : array_slice($lines, 0, -1);
    }

    /** @param array<string, mixed> $series */
    private static function seriesTitle(array $series): string
    {
        $name = $series['label'] !== '' ? (string) $series['label'] : DossierFormat::humanize((string) $series['quantity']);
        $position = self::POSITION_WORDS[$series['position']] ?? null;

        return $position === null ? $name : "{$name}, {$position}";
    }

    /** @return list<string> */
    private function tireLines(int $vehicleId, Actor $actor): array
    {
        $tires = $this->tires->list($vehicleId, $actor);
        $lines = [];
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
            $lines[] = sprintf('- **%s**%s — %s %s', $set['label'], $isMounted ? ' (**montiert**)' : '', implode(', ', $details), DossierFormat::provenanceOf($set));
        }

        return $lines;
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

    /** @return list<string> */
    private function partLines(int $vehicleId, Actor $actor): array
    {
        $lines = [];
        foreach ($this->parts->grouped($vehicleId, $actor)['components'] as $component) {
            $lines[] = '### ' . $component['component'];
            $lines[] = '';
            foreach ($component['parts'] as $part) {
                $lines[] = self::partLine($part);
            }
            $lines[] = '';
        }

        return $lines === [] ? [] : array_slice($lines, 0, -1);
    }

    /** @param array<string, mixed> $part */
    private static function partLine(array $part): string
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

        return sprintf('- %s: %s %s', $role, implode(', ', $details), DossierFormat::provenanceOf($part));
    }

    /** @return list<string> */
    private function damageLines(int $vehicleId, Actor $actor): array
    {
        $lines = [];
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
            $lines[] = sprintf(
                '- %s · %s · %s%s %s',
                DossierFormat::date($damage['occurred_on']),
                DossierFormat::humanize((string) $damage['kind']),
                $damage['description'],
                $details === [] ? '' : ' — ' . implode(', ', $details),
                DossierFormat::provenanceOf($damage)
            );
        }

        return $lines;
    }

    /** @return list<string> */
    private function recallLines(int $vehicleId, Actor $actor): array
    {
        $lines = [];
        foreach ($this->damages->listRecalls($vehicleId, $actor) as $recall) {
            $references = array_values(array_filter([
                $recall['manufacturer_code'] === null ? null : 'Hersteller-Code ' . $recall['manufacturer_code'],
                $recall['kba_reference'] === null ? null : 'KBA ' . $recall['kba_reference'],
            ], static fn (?string $reference): bool => $reference !== null));
            $status = self::RECALL_STATUS_WORDS[$recall['status']] ?? (string) $recall['status'];
            $doneOn = $recall['done_on'] === null ? '' : ' am ' . DossierFormat::date($recall['done_on']);
            $lines[] = sprintf(
                '- %s%s — %s%s %s',
                $recall['title'],
                $references === [] ? '' : ' (' . implode(', ', $references) . ')',
                $status,
                $doneOn,
                DossierFormat::provenanceOf($recall)
            );
        }

        return $lines;
    }

    /** @return list<string> */
    private function noteLines(int $vehicleId, Actor $actor): array
    {
        $lines = [];
        foreach ($this->notes->listCurrent($vehicleId, $actor) as $note) {
            $lines[] = '### ' . $note['topic'];
            $lines[] = '';
            $lines[] = DossierFormat::shiftHeadings($note['body'], self::NOTE_HEADING_SHIFT);
            $lines[] = '';
            $lines[] = sprintf('_Revision %d, %s, %s_', $note['revision'], $note['actor'], DossierFormat::date($note['updated_at']));
            $lines[] = '';
        }

        return $lines === [] ? [] : array_slice($lines, 0, -1);
    }

    /**
     * Belege des Fahrzeugs als Tabelle, ohne Links: die Akte bleibt, Links laufen ab.
     *
     * @return list<string>
     */
    private function documentLines(int $vehicleId, Actor $actor): array
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

        return DossierFormat::table(['Datum', 'Typ', 'Titel', 'hängt an'], $rows);
    }

    /** @return list<string> */
    private function openItemLines(int $vehicleId, Actor $actor): array
    {
        $lines = [];
        foreach ($this->openItems->list($vehicleId, OpenItemRepository::STATUS_OPEN, $actor) as $item) {
            $lines[] = sprintf('- #%d (%s): %s', $item['id'], $item['kind'], preg_replace('/\s*[\r\n]+\s*/', ' ', (string) $item['text']));
        }

        return $lines;
    }
}
