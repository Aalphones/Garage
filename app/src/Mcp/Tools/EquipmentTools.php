<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\EquipmentService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class EquipmentTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var EquipmentService $equipment */
        $equipment = $services['equipment'];

        $registry->add(new Tool(
            'upsert_equipment',
            'Ausstattung erfassen',
            'Trägt Ausstattungspositionen (Serie oder Sonderausstattung) mit einer Herkunft für den ganzen Aufruf ein. Der Name erkennt dieselbe Position wieder: ein zweiter identischer Aufruf meldet je Position unveraendert, fehlende Angaben werden ergaenzt, ein Widerspruch zu einem bestätigten Wert wird klaerfall (open_item_ids). Alles oder nichts: ein ungültiger Eintrag schreibt gar nichts. Beispiel: {"vehicle_id": 12, "items": [{"name": "Navigationssystem", "code": "7UG", "category": "sonder", "list_price_eur": "450.00"}, {"name": "Klimaautomatik", "category": "serie"}], "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'items' => Schema::list(
                    Schema::object([
                        'name' => Schema::text('Bezeichnung, 1 bis 160 Zeichen, je Aufruf eindeutig'),
                        'code' => Schema::text('Ausstattungscode, 1 bis 20 Zeichen, z. B. "7UG"'),
                        'category' => Schema::choice(['serie', 'sonder'], 'serie (Serienausstattung) oder sonder (Sonderausstattung)'),
                        'list_price_eur' => Schema::money('Listenpreis in Euro, Zahl oder Text mit höchstens zwei Nachkommastellen, z. B. "450.00"'),
                    ], ['name', 'category']),
                    'Liste von Ausstattungspositionen, 1 bis 200 Einträge'
                ),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'items', 'source', 'confidence']),
            Tool::annotations('Ausstattung erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($equipment->upsert((int) $args['vehicle_id'], $args['items'], $args, $actor))
        ));
    }
}
