<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\Provenance;
use Garage\Domain\VehicleService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class VehicleTools
{
    private const SPEC_AREAS = ['motoroel', 'kuehlmittel', 'bremsfluessigkeit', 'getriebeoel', 'batterie', 'reifen', 'steuertrieb'];

    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var VehicleService $vehicles */
        $vehicles = $services['vehicles'];

        $registry->add(new Tool(
            'list_vehicles',
            'Fahrzeuge auflisten',
            'Listet alle Garagen des Nutzers (auch leere) mit Rolle und Fahrzeugen: id, label, status, marke, modell, fin, die Zahl offener Klärfälle, km_estimate (geschätzter Kilometerstand heute, null ohne Kilometerstand) und next_due (dringendste Wartung: task_key, title, effective_date, status; null ohne berechenbare Aufgabe; Details in get_due_items). Starte hiermit, um die vehicle_id und garage_id für die anderen Tools zu finden und um zu prüfen, ob es ein Fahrzeug schon gibt. Keine Eingabe nötig: {}.',
            Schema::object(),
            Tool::annotations('Fahrzeuge auflisten', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json(['garages' => $vehicles->list($actor)])
        ));

        $registry->add(new Tool(
            'get_vehicle',
            'Fahrzeug lesen',
            'Liefert ein Fahrzeug mit allen aktuellen Stammdaten, je Feld mit Wert, Herkunft (source, source_url), Konfidenz, Akteur und Zeitpunkt. „specs“ sind die Spezifikationen nach Bereich gruppiert (specs.motoroel.viskositaet), „registrations“ die Zulassungen (neueste zuerst), „holder_count“ die Zahl verschiedener Halter, „equipment“ die Ausstattung, „km_estimate“ den geschätzten Kilometerstand von heute mit Grundlage (km_estimate, per_day = Kilometer pro Tag, estimated = false ohne Hochrechnung, basis.from/to = die Stände dahinter; null ohne Kilometerstand). „disputed“ nennt Werte, die einem bestätigten Wert widersprechen und auf die Entscheidung eines Menschen warten (open_item_id). Beispiel: {"vehicle_id": 12}.',
            Schema::object(['vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles')], ['vehicle_id']),
            Tool::annotations('Fahrzeug lesen', 'R'),
            Scopes::READ,
            static function (array $args, Actor $actor) use ($vehicles): ToolResult {
                $result = $vehicles->get((int) $args['vehicle_id'], $actor);
                // Ein leeres PHP-Array würde als JSON-Liste statt als Objekt ausgegeben.
                $result['facts'] = (object) $result['facts'];
                $result['specs'] = (object) $result['specs'];

                return ToolResult::json($result);
            }
        ));

        $registry->add(new Tool(
            'create_vehicle',
            'Fahrzeug anlegen',
            'Legt ein Fahrzeug an und trägt optional gleich Stammdaten ein (Regeln wie bei set_vehicle_facts). Prüfe vorher mit list_vehicles, dass es das Fahrzeug noch nicht gibt. Hat der Nutzer mehr als eine beschreibbare Garage, ist garage_id Pflicht. Beispiel: {"label": "Golf 7", "facts": [{"field": "marke", "value": "Volkswagen", "source": "nutzer", "confidence": "bestaetigt"}]}.',
            Schema::object([
                'label' => Schema::text('Anzeigename, 1 bis 80 Zeichen, z. B. "Golf 7"'),
                'garage_id' => Schema::integer('Garage aus list_vehicles; nur nötig, wenn mehrere beschreibbar sind'),
                'facts' => self::factsSchema(),
            ], ['label']),
            Tool::annotations('Fahrzeug anlegen', 'W'),
            Scopes::WRITE,
            static function (array $args, Actor $actor) use ($vehicles): ToolResult {
                $garageId = isset($args['garage_id']) ? (int) $args['garage_id'] : null;

                return ToolResult::json($vehicles->create((string) $args['label'], $garageId, $args['facts'] ?? [], $actor));
            }
        ));

        $registry->add(new Tool(
            'set_vehicle_facts',
            'Stammdaten ergänzen',
            'Trägt Stammdaten eines Fahrzeugs mit Herkunft ein. Je Feld meldet das Ergebnis: angelegt (neu), aktualisiert (ersetzt einen nicht bestätigten Wert oder hebt die Konfidenz), unveraendert (nichts zu tun) oder klaerfall (Widerspruch zu einem bestätigten Wert: der neue Wert wird daneben gelegt, ein Mensch entscheidet; open_item_id nennt den Klärfall). Alles oder nichts: ein ungültiges Feld schreibt gar nichts. Felder: ' . implode(', ', array_keys(VehicleService::FIELDS)) . '. Beispiel: {"vehicle_id": 12, "facts": [{"field": "fin", "value": "WVWZZZ1KZAW123456", "source": "beleg", "confidence": "bestaetigt"}, {"field": "leistung_kw", "value": "110", "source": "recherche", "source_url": "https://example.com/golf", "confidence": "wahrscheinlich"}]}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'facts' => self::factsSchema(),
            ], ['vehicle_id', 'facts']),
            Tool::annotations('Stammdaten ergänzen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($vehicles->setFacts((int) $args['vehicle_id'], $args['facts'], $actor))
        ));

        $registry->add(new Tool(
            'set_spec',
            'Spezifikationen eintragen',
            'Trägt technische Spezifikationen (Öl, Kühlmittel, Bremsflüssigkeit, Batterie …) eines Fahrzeugs ein, mit einer Herkunft für den ganzen Aufruf. Es gilt dieselbe Konfliktregel wie bei set_vehicle_facts: je Eintrag angelegt, aktualisiert, unveraendert oder klaerfall (ein bestätigter Wert bleibt stehen, der neue wird daneben gelegt, open_item_id nennt den Klärfall). Die Einheit steht im Wert, z. B. "4,3 l". Übliche Bereiche (area): ' . implode(', ', self::SPEC_AREAS) . '; area und key bestehen aus Kleinbuchstaben, Ziffern und Unterstrich (2 bis 31 Zeichen). Alles oder nichts: ein ungültiger Eintrag schreibt gar nichts. Beispiel: {"vehicle_id": 12, "specs": [{"area": "motoroel", "key": "viskositaet", "value": "0W-20"}, {"area": "motoroel", "key": "fuellmenge", "value": "4,3 l"}], "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'specs' => Schema::list(
                    Schema::object([
                        'area' => Schema::text('Bereich, z. B. "motoroel"'),
                        'key' => Schema::text('Schlüssel im Bereich, z. B. "viskositaet"'),
                        'value' => Schema::text('Wert als Text mit Einheit, 1 bis 200 Zeichen, z. B. "0W-20" oder "4,3 l"'),
                    ], ['area', 'key', 'value']),
                    'Liste von Spezifikationen, ein Eintrag je Bereich und Schlüssel'
                ),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'specs', 'source', 'confidence']),
            Tool::annotations('Spezifikationen eintragen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($vehicles->setSpecs((int) $args['vehicle_id'], $args['specs'], $args, $actor))
        ));
    }

    /** @return array<string, mixed> */
    private static function factsSchema(): array
    {
        return Schema::list(
            Schema::object([
                'field' => Schema::choice(array_keys(VehicleService::FIELDS), 'Stammdatenfeld'),
                'value' => Schema::text('Wert, immer als Text, auch Zahlen ("110"); Datum als YYYY-MM-DD; FIN 17 Zeichen'),
                'source' => Schema::choice(Provenance::SOURCES, 'Woher der Wert stammt: beleg (Rechnung, Fahrzeugschein), nutzer (Aussage des Nutzers), recherche (Webseite), agent (eigene Ableitung), werkstatt'),
                'source_url' => Schema::text('Adresse der Quelle, z. B. "https://example.com/seite"; bei recherche Pflicht in der Praxis'),
                'confidence' => Schema::choice(array_keys(Provenance::CONFIDENCE_RANK), 'bestaetigt (Beleg liegt vor), wahrscheinlich, unsicher'),
            ], ['field', 'value', 'source', 'confidence']),
            'Liste von Stammdaten, ein Eintrag je Feld'
        );
    }
}
