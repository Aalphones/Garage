<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\ChangeService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class ChangeTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var ChangeService $changes */
        $changes = $services['changes'];

        $registry->add(new Tool(
            'list_changes',
            'Verlauf lesen',
            'Zeigt, was sich in den eigenen Garagen geändert hat, neueste zuerst: id, at (UTC), actor (wer: Person oder Zugang), action (z. B. event.create, open_item.resolve), target (Tabelle:ID), vehicle_id, before und after (die geänderten Werte). Standard 50 Einträge, höchstens 200. Hilft zu erkennen, was seit dem letzten Besuch passiert ist und ob ein Mensch einen Klärfall entschieden hat. Beispiel: {"vehicle_id": 12, "limit": 20}.',
            Schema::object([
                'vehicle_id' => Schema::integer('nur dieses Fahrzeug (ID aus list_vehicles); ohne Angabe alle Fahrzeuge'),
                'limit' => Schema::integer('Anzahl Einträge, 1 bis 200, Standard 50'),
            ]),
            Tool::annotations('Verlauf lesen', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json([
                'changes' => $changes->list(isset($args['vehicle_id']) ? (int) $args['vehicle_id'] : null, $args['limit'] ?? null, $actor),
            ])
        ));
    }
}
