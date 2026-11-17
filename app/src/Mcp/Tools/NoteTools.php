<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\NoteService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

final class NoteTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var NoteService $notes */
        $notes = $services['notes'];

        $registry->add(new Tool(
            'upsert_note',
            'Notiz speichern',
            'Speichert eine Notiz zu einem Thema (Freitext, Markdown). Ein neues Thema ergibt Revision 1 (result angelegt); anderer Text beim selben Thema ergibt die nächste Revision (aktualisiert) — die alten Revisionen bleiben erhalten; derselbe Text ändert nichts (unveraendert). Notizen haben keine Konfliktregel und keine Herkunft; der Akteur steht an der Revision. Gedacht für Wissen, das in keine Tabelle passt (Eigenheiten, Merkzettel). Beispiel: {"vehicle_id": 12, "topic": "Winterlager", "body": "Reifen im Keller, Regal 2."}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'topic' => Schema::text('Thema, 1 bis 120 Zeichen, einzeilig; erkennt dieselbe Notiz wieder'),
                'body' => Schema::text('Text der Notiz, Markdown, 1 bis 20000 Zeichen; ersetzt den bisherigen Text als neue Revision'),
            ], ['vehicle_id', 'topic', 'body']),
            Tool::annotations('Notiz speichern', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($notes->upsert((int) $args['vehicle_id'], $args, $actor))
        ));
    }
}
