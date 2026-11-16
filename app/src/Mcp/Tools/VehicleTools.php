<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\VehicleService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class VehicleTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var VehicleService $vehicles */
        $vehicles = $services['vehicles'];

        $registry->add(new Tool(
            'list_vehicles',
            'Fahrzeuge auflisten',
            'Listet alle Garagen des Nutzers (auch leere) mit Rolle und Fahrzeugen: id, label, status, marke, modell, fin und die Zahl offener Klärfälle. Starte hiermit, um die vehicle_id und garage_id für die anderen Tools zu finden und um zu prüfen, ob es ein Fahrzeug schon gibt. Keine Eingabe nötig: {}.',
            Schema::object(),
            Tool::annotations('Fahrzeuge auflisten', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json(['garages' => $vehicles->list($actor)])
        ));

        $registry->add(new Tool(
            'get_vehicle',
            'Fahrzeug lesen',
            'Liefert ein Fahrzeug mit allen aktuellen Stammdaten, je Feld mit Wert, Herkunft (source, source_url), Konfidenz, Akteur und Zeitpunkt. „disputed“ nennt Werte, die einem bestätigten Wert widersprechen und auf die Entscheidung eines Menschen warten (open_item_id). Beispiel: {"vehicle_id": 12}.',
            Schema::object(['vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles')], ['vehicle_id']),
            Tool::annotations('Fahrzeug lesen', 'R'),
            Scopes::READ,
            static function (array $args, Actor $actor) use ($vehicles): ToolResult {
                $result = $vehicles->get((int) $args['vehicle_id'], $actor);
                // Ein leeres PHP-Array würde als JSON-Liste statt als Objekt ausgegeben.
                $result['facts'] = (object) $result['facts'];

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
    }

    /** @return array<string, mixed> */
    private static function factsSchema(): array
    {
        return Schema::list(
            Schema::object([
                'field' => Schema::choice(array_keys(VehicleService::FIELDS), 'Stammdatenfeld'),
                'value' => Schema::text('Wert, immer als Text, auch Zahlen ("110"); Datum als YYYY-MM-DD; FIN 17 Zeichen'),
                'source' => Schema::choice(VehicleService::SOURCES, 'Woher der Wert stammt: beleg (Rechnung, Fahrzeugschein), nutzer (Aussage des Nutzers), recherche (Webseite), agent (eigene Ableitung), werkstatt'),
                'source_url' => Schema::text('Adresse der Quelle, z. B. "https://example.com/seite"; bei recherche Pflicht in der Praxis'),
                'confidence' => Schema::choice(array_keys(VehicleService::CONFIDENCE_RANK), 'bestaetigt (Beleg liegt vor), wahrscheinlich, unsicher'),
            ], ['field', 'value', 'source', 'confidence']),
            'Liste von Stammdaten, ein Eintrag je Feld'
        );
    }
}
