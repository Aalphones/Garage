<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\DocumentService;
use Garage\Domain\InboxService;
use Garage\Domain\Validate;
use Garage\Mcp\ImageBlocks;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

/** Eingang abarbeiten: auflisten, mit Frist reservieren, abschließen (E11, ADR-016). Füllen und Verwerfen bleibt Menschen vorbehalten. */
final class InboxTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var InboxService $inbox */
        $inbox = $services['inbox'];
        /** @var DocumentService $documents */
        $documents = $services['documents'];

        $registry->add(new Tool(
            'list_inbox',
            'Eingang auflisten',
            'Listet Einträge im Eingang der eigenen Garagen, älteste zuerst (höchstens 200): id, garage_id, vehicle_id, note (Notiz des Menschen), status, claimed_by und claimed_until (nur bei in_arbeit), page_count, created_at, result. Standard: nur neue; eine abgelaufene Reservierung erscheint wieder als neu. status: neu, in_arbeit, erfasst, klaerfall, verworfen oder alle. Beispiel: {"status": "neu"}.',
            Schema::object([
                'status' => Schema::choice(InboxService::LIST_STATUSES, 'Standard: neu'),
                'garage_id' => Schema::integer('Nur diese Garage'),
            ]),
            Tool::annotations('Eingang auflisten', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json(['items' => $inbox->list(
                $actor,
                isset($args['status']) ? (string) $args['status'] : null,
                isset($args['garage_id']) ? (int) $args['garage_id'] : null
            )])
        ));

        $registry->add(new Tool(
            'claim_inbox_item',
            'Eingangs-Eintrag reservieren',
            'Reserviert einen Eintrag für dich, damit kein anderer Agent ihn zugleich bearbeitet, und liefert ihn: item, documents (id, doc_type, title, page_count, mime) und die Seiten als Bilder (zusammen höchstens 6; die übrigen holst du mit get_document und page). Ohne id bekommst du den ältesten freien Eintrag; gibt es keinen, ist item null. Die Reservierung gilt minutes Minuten (10 bis 720, Standard 180); ruf claim_inbox_item mit derselben id erneut auf, um sie zu verlängern. Ein von jemand anderem reservierter Eintrag ist gesperrt, bis die Frist abläuft. Danach: erfassen, je Dokument attach_document, dann release_inbox_item. Beispiel: {} oder {"id": 3, "minutes": 60}.',
            Schema::object([
                'id' => Schema::integer('ID aus list_inbox; ohne Angabe der älteste freie Eintrag'),
                'minutes' => Schema::integer('Dauer der Reservierung in Minuten, 10 bis 720; Standard 180'),
            ]),
            Tool::annotations('Eingangs-Eintrag reservieren', 'W'),
            Scopes::WRITE,
            static function (array $args, Actor $actor) use ($inbox, $documents): ToolResult {
                $example = '{"id": 3, "minutes": 60}';
                $minutes = isset($args['minutes'])
                    ? (int) Validate::int('minutes', $args['minutes'], $example, InboxService::MIN_MINUTES, InboxService::MAX_MINUTES)
                    : InboxService::DEFAULT_MINUTES;
                $id = isset($args['id']) ? (int) Validate::int('id', $args['id'], $example, 1) : null;

                $claimed = $inbox->claim($id, $minutes, $actor);
                if ($claimed === null) {
                    return ToolResult::json(['item' => null, 'documents' => [], 'next_steps' => 'Der Eingang ist leer oder alles ist reserviert. list_inbox mit status alle zeigt den Stand.']);
                }

                $summary = [];
                $images = [];
                $omitted = [];
                foreach ($claimed['documents'] as $document) {
                    $summary[] = ['id' => $document['id'], 'doc_type' => $document['doc_type'], 'title' => $document['title'], 'page_count' => $document['page_count'], 'mime' => $document['mime']];
                    $room = ImageBlocks::MAX_BLOCKS - count($images);
                    $blocks = ImageBlocks::forDocument($documents, $document, null, null, $room);
                    array_push($images, ...$blocks['blocks']);
                    if ($blocks['skipped'] !== []) {
                        $omitted[] = ['document_id' => $document['id'], 'pages' => $blocks['skipped']];
                    }
                }

                return ToolResult::json([
                    'item' => $claimed['item'],
                    'documents' => $summary,
                    'next_steps' => 'Erfassen, attach_document je Dokument, dann release_inbox_item.',
                ] + ($omitted === [] ? [] : ['not_shown' => $omitted, 'hint' => 'Diese Seiten mit get_document (document_id, page) lesen.']), $images);
            }
        ));

        $registry->add(new Tool(
            'release_inbox_item',
            'Eingangs-Eintrag abschließen',
            'Schließt eine eigene, noch laufende Reservierung ab. outcome: erfasst (alles übernommen; result sagt in einem Satz was), klaerfall (nicht entscheidbar; result nennt die Frage — daraus entsteht eine Nachfrage am Fahrzeug) oder zurueck (nichts getan, der Eintrag wird wieder neu; result optional). Nach Ablauf der Frist oder wenn ein anderer reserviert hat, kommt invalid_value: dann erneut claim_inbox_item. Beispiel: {"id": 3, "outcome": "erfasst", "result": "Inspektion 05/2025 erfasst"}.',
            Schema::object([
                'id' => Schema::integer('ID des reservierten Eintrags'),
                'outcome' => Schema::choice(InboxService::OUTCOMES, 'Ausgang der Bearbeitung'),
                'result' => Schema::text('1 bis 500 Zeichen: was erfasst wurde bzw. die Frage; bei zurueck optional'),
            ], ['id', 'outcome']),
            Tool::annotations('Eingangs-Eintrag abschließen', 'W'),
            Scopes::WRITE,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json(
                $inbox->release((int) $args['id'], $args['outcome'] ?? null, $args['result'] ?? null, $actor)
            )
        ));
    }
}
