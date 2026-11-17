<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\PartService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class PartTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var PartService $parts */
        $parts = $services['parts'];

        $registry->add(new Tool(
            'add_part',
            'Teil erfassen',
            'Trägt ein Teil ein: was am Bauteil verbaut ist (role verbaut) oder was alternativ passt (alternative). Bauteil, Marke und Artikelnummer erkennen dasselbe Teil wieder: ein zweiter identischer Aufruf meldet unveraendert, später ergänzte Angaben (z. B. verified: true nach Prüfung der Passform) ergaenzt, ein Widerspruch zu einem bestätigten Wert klaerfall (open_item_ids). verified heißt: Passform am Fahrzeug geprüft. Beispiel: {"vehicle_id": 12, "component": "Bremsbeläge vorn", "brand": "ATE", "article_number": "13.0460-7188.2", "oem_number": "5Q0698151", "role": "verbaut", "source": "beleg", "confidence": "bestaetigt"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'component' => Schema::text('Bauteil, 1 bis 80 Zeichen, z. B. "Bremsbeläge vorn"'),
                'brand' => Schema::text('Marke des Teils, 1 bis 60 Zeichen'),
                'article_number' => Schema::text('Artikelnummer des Herstellers, 1 bis 60 Zeichen'),
                'oem_number' => Schema::text('Nummer des Fahrzeugherstellers, 1 bis 60 Zeichen'),
                'role' => Schema::choice(PartService::ROLES, 'verbaut (ist am Fahrzeug) oder alternative (passt auch)'),
                'verified' => Schema::boolean('Passform am Fahrzeug geprüft'),
                'note' => Schema::text('Anmerkung, 1 bis 200 Zeichen'),
            ] + ProvenanceSchema::properties(), ['vehicle_id', 'component', 'role', 'source', 'confidence']),
            Tool::annotations('Teil erfassen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($parts->add((int) $args['vehicle_id'], $args, $actor))
        ));

        $registry->add(new Tool(
            'get_parts',
            'Teile lesen',
            'Liefert die Teile eines Fahrzeugs nach Bauteil gruppiert (components: component, parts), je Bauteil die verbauten zuerst: id, brand, article_number, oem_number, role, verified, note, source, confidence. Beispiel: {"vehicle_id": 12}.',
            Schema::object(['vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles')], ['vehicle_id']),
            Tool::annotations('Teile lesen', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($parts->grouped((int) $args['vehicle_id'], $actor))
        ));
    }
}
