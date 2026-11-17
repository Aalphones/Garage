<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\SearchService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class SearchTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var SearchService $search */
        $search = $services['search'];

        $registry->add(new Tool(
            'search',
            'In der Akte suchen',
            'Sucht einen Text (3 bis 100 Zeichen, ohne Rücksicht auf Groß- und Kleinschreibung) in Ereignissen (Titel, Rechnungsnummer), Positionen (Text, Teilenummer), Teilen (Bauteil, OEM- und Artikelnummer), Notizen (Thema, Text) und offenen Klärfällen. Je Quelle höchstens 10, zusammen höchstens 30 Treffer: type (event, event_item, part, note, open_item), id, vehicle_id, title, snippet; bei event_item zusätzlich event_id für get_event. Platzhalter wie % und _ werden wörtlich gesucht. Beispiel: {"query": "Ölfilter", "vehicle_id": 12}.',
            Schema::object([
                'query' => Schema::text('Suchtext, 3 bis 100 Zeichen'),
                'vehicle_id' => Schema::integer('nur dieses Fahrzeug (ID aus list_vehicles); ohne Angabe alle Fahrzeuge'),
            ], ['query']),
            Tool::annotations('In der Akte suchen', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json([
                'hits' => $search->search($args['query'] ?? null, isset($args['vehicle_id']) ? (int) $args['vehicle_id'] : null, $actor),
            ])
        ));
    }
}
