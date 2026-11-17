<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\DamageService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class DamageTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var DamageService $damages */
        $damages = $services['damages'];

        $registry->add(new Tool(
            'record_damage',
            'Schaden erfassen',
            'Trägt einen Schaden ein (Unfall, Hagel, Lack, Glas, Vandalismus, sonstiges). Datum und Art erkennen denselben Schaden wieder: ein zweiter identischer Aufruf meldet unveraendert, später ergänzte Angaben (Reparatur, Gutachten, Wertminderung) ergaenzt, ein Widerspruch zu einem bestätigten Wert klaerfall (open_item_ids). repair_event_id verweist auf das Reparatur-Ereignis aus list_events. Der Kilometerstand ist Angabe zum Schaden, kein Kilometerstand der Akte (der gehört in record_odometer). Beispiel: {"vehicle_id": 12, "occurred_on": "2025-08-14", "kind": "hagel", "description": "Hagelschaden an Dach und Motorhaube", "repaired": false, "appraisal": true, "diminished_value_eur": "1200", "source": "nutzer", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'occurred_on' => Schema::text('Datum des Schadens, YYYY-MM-DD'),
                'kind' => Schema::choice(DamageService::DAMAGE_KINDS, 'Art des Schadens'),
                'description' => Schema::text('Beschreibung, 1 bis 500 Zeichen, einzeilig'),
                'odometer_km' => Schema::integer('Kilometerstand beim Schaden, 0 bis 2000000'),
                'repaired' => Schema::boolean('Schaden ist behoben'),
                'repair_event_id' => Schema::integer('Ereignis (aus list_events), das den Schaden behoben hat'),
                'appraisal' => Schema::boolean('Ein Gutachten liegt vor'),
                'diminished_value_eur' => Schema::money('Wertminderung in Euro, höchstens zwei Nachkommastellen'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'occurred_on', 'kind', 'description', 'source', 'confidence']),
            Tool::annotations('Schaden erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($damages->recordDamage((int) $args['vehicle_id'], $args, $actor))
        ));

        $registry->add(new Tool(
            'record_recall',
            'Rückruf erfassen',
            'Trägt einen Rückruf ein. Herstellernummer und KBA-Referenz erkennen denselben Rückruf wieder — mindestens eine von beiden ist Pflicht (sonst invalid_value). Ein zweiter identischer Aufruf meldet unveraendert, später ergänzte Angaben (erledigt am, Ereignis) ergaenzt, ein Widerspruch zu einem bestätigten Wert klaerfall (open_item_ids). status: offen, erledigt, nicht_betroffen. Beispiel: {"vehicle_id": 12, "title": "Rückruf Hochvoltbatterie", "kba_reference": "012345", "manufacturer_code": "23Y5", "status": "offen", "source": "recherche", "source_url": "https://www.kba.de", "confidence": "wahrscheinlich"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'title' => Schema::text('Titel, 1 bis 200 Zeichen'),
                'manufacturer_code' => Schema::text('Rückrufcode des Herstellers, 1 bis 40 Zeichen'),
                'kba_reference' => Schema::text('KBA-Referenznummer, 1 bis 40 Zeichen'),
                'status' => Schema::choice(DamageService::RECALL_STATUSES, 'offen, erledigt oder nicht_betroffen'),
                'done_on' => Schema::text('Datum der Erledigung, YYYY-MM-DD'),
                'event_id' => Schema::integer('Ereignis (aus list_events), bei dem der Rückruf erledigt wurde'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'title', 'status', 'source', 'confidence']),
            Tool::annotations('Rückruf erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($damages->recordRecall((int) $args['vehicle_id'], $args, $actor))
        ));
    }
}
