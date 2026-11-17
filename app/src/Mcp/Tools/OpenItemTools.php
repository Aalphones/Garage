<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\OpenItemService;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

/** Klärfälle lesen und Nachfragen anlegen. Entscheiden kann ein Mensch in der Oberfläche; ein Werkzeug dafür gibt es absichtlich nicht (ADR-010). */
final class OpenItemTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var OpenItemService $openItems */
        $openItems = $services['openItems'];

        $registry->add(new Tool(
            'list_open_items',
            'Klärfälle auflisten',
            'Listet Klärfälle der eigenen Garagen, neueste zuerst (höchstens 200): id, vehicle_id, vehicle_label, kind (konflikt, nachfragen, beobachten), text, status, created_by, created_at, ref_table und ref_id (die betroffene Zeile), proposal (der gemeldete Wert, den ein Mensch übernehmen kann); gelöste zusätzlich resolution, resolved_by, resolved_at. Standard: nur offene. status: offen, geloest, verworfen oder alle. Entscheiden kann nur ein Mensch in der Oberfläche — frage dort nach, statt einen offenen Klärfall zu umgehen. Beispiel: {"vehicle_id": 12, "status": "alle"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('nur dieses Fahrzeug (ID aus list_vehicles); ohne Angabe alle Fahrzeuge'),
                'status' => Schema::choice(['offen', 'geloest', 'verworfen', 'alle'], 'Standard: offen'),
            ]),
            Tool::annotations('Klärfälle auflisten', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json([
                'open_items' => $openItems->list(isset($args['vehicle_id']) ? (int) $args['vehicle_id'] : null, (string) ($args['status'] ?? 'offen'), $actor),
            ])
        ));

        $registry->add(new Tool(
            'open_item',
            'Nachfrage oder Beobachtung anlegen',
            'Legt einen Klärfall an, wenn du etwas nicht selbst klären kannst: kind nachfragen (der Mensch soll etwas beantworten oder entscheiden) oder beobachten (etwas im Blick behalten). text sagt in 1 bis 1000 Zeichen, was offen ist; suggestion (optional, 1 bis 1000 Zeichen) ist dein Vorschlag. Derselbe offene Text am selben Fahrzeug ergibt keinen zweiten Eintrag (result unveraendert, dieselbe ID). Konflikte (kind konflikt) legst du nicht an — sie entstehen von selbst, wenn ein geschriebener Wert einem bestätigten widerspricht. Beispiel: {"vehicle_id": 12, "kind": "nachfragen", "text": "Welcher Ölfilter wurde 2025 verbaut?"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'kind' => Schema::choice(['nachfragen', 'beobachten'], 'Art des Klärfalls'),
                'text' => Schema::text('Was offen ist, 1 bis 1000 Zeichen, einzeilig'),
                'suggestion' => Schema::text('dein Vorschlag, 1 bis 1000 Zeichen'),
            ], ['vehicle_id', 'kind', 'text']),
            Tool::annotations('Nachfrage oder Beobachtung anlegen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json(
                $openItems->create((int) $args['vehicle_id'], $args['kind'] ?? null, $args['text'] ?? null, $args['suggestion'] ?? null, $actor)
            )
        ));
    }
}
