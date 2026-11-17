<?php

declare(strict_types=1);

namespace Garage\Mcp\Tools;

use Garage\Auth\Scopes;
use Garage\Domain\Actor;
use Garage\Domain\DocumentService;
use Garage\Domain\Validate;
use Garage\Mcp\ImageBlocks;
use Garage\Mcp\Registry;
use Garage\Mcp\Schema;
use Garage\Mcp\Tool;
use Garage\Mcp\ToolResult;

/** Belege und Fotos: Upload-Ticket, Beschreibung, Lesen, Liste. Dateien laufen nie durch das Modell (ADR-012). */
final class DocumentTools
{
    /** @param array<string, mixed> $services */
    public static function register(Registry $registry, array $services): void
    {
        /** @var DocumentService $documents */
        $documents = $services['documents'];

        $registry->add(new Tool(
            'request_upload',
            'Upload-Adresse anfordern',
            'Liefert eine einmalige Upload-Adresse für ein Foto oder PDF (15 Minuten gültig). Für Programme mit Dateizugriff (curl, Claude Code): die Datei per POST an upload_url senden — als Body (curl --data-binary @foto.jpg "<upload_url>") oder als Formularfeld (curl -F file=@rechnung.pdf "<upload_url>"); die Antwort nennt document_id. Danach store_document mit ticket_id aufrufen, um Typ, Fahrzeug und Titel zu setzen. Ohne vehicle_id und garage_id gilt die einzige Garage mit Schreibrecht. Beispiel: {"vehicle_id": 12}.',
            Schema::object([
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles; bestimmt auch die Garage'),
                'garage_id' => Schema::integer('Garage, wenn kein Fahrzeug angegeben ist und der Nutzer mehrere Garagen hat'),
            ]),
            Tool::annotations('Upload-Adresse anfordern', 'W'),
            Scopes::FILES,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($documents->issueTicket(
                $actor,
                isset($args['garage_id']) ? (int) $args['garage_id'] : null,
                isset($args['vehicle_id']) ? (int) $args['vehicle_id'] : null
            ))
        ));

        $registry->add(new Tool(
            'store_document',
            'Beleg oder Foto beschreiben',
            'Beschreibt einen Beleg oder ein Foto. Mit ticket_id (nach dem Upload an die Adresse aus request_upload) setzt es Typ, Fahrzeug, Titel und erkannten Text der hochgeladenen Datei; wurde dieselbe Datei schon abgelegt, ist duplicate true und das vorhandene Dokument bleibt unverändert. Ohne ticket_id entsteht ein Dokument im Status „wartet“ (Datei fehlt): das Ergebnis enthält dann einen upload-Block, und der Mensch reicht das Original im Eingang oder auf der Beleg-Seite nach. Den Inhalt (Datum, Beträge, Positionen) hältst du in text fest — GARAGE erkennt selbst keinen Text. Die Datei nie als Base64 ins Argument legen. Beispiel: {"ticket_id": 7, "vehicle_id": 12, "doc_type": "rechnung", "title": "Rechnung Werkstatt Müller", "text": "Ölwechsel 89,00 EUR"}.',
            Schema::object([
                'ticket_id' => Schema::integer('ticket_id aus request_upload, nachdem die Datei hochgeladen wurde; ohne: Dokument im Status „wartet“'),
                'vehicle_id' => Schema::integer('ID des Fahrzeugs aus list_vehicles'),
                'garage_id' => Schema::integer('Garage für ein Dokument ohne Fahrzeug, wenn der Nutzer mehrere Garagen hat'),
                'doc_type' => Schema::choice(DocumentService::TYPES, 'Art des Dokuments'),
                'title' => Schema::text('Kurzer Titel, bis 200 Zeichen, einzeilig'),
                'photo_tag' => Schema::choice(DocumentService::TAGS, 'Nur bei doc_type foto: was das Foto zeigt'),
                'captured_on' => Schema::text('Aufnahme- oder Belegdatum, YYYY-MM-DD'),
                'text' => Schema::text('Erkannter Text oder Zusammenfassung des Belegs, bis 200000 Zeichen; die Suche findet ihn'),
            ], ['doc_type']),
            Tool::annotations('Beleg oder Foto beschreiben', 'W'),
            Scopes::FILES,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json($documents->store($args, $actor))
        ));

        $registry->add(new Tool(
            'get_document',
            'Beleg oder Foto lesen',
            'Liefert die Angaben zu einem Dokument samt erkanntem Text und einem Download-Link (10 Minuten gültig, ohne Anmeldung nutzbar), bei Fotos dazu das Bild. Bei PDFs mit Seitenbildern mit page (1–20) eine Seite, ohne page bis zu 6 Seiten; half „oben“ oder „unten“ liefert nur die Hälfte der Seite, damit kleine Schrift lesbar bleibt. Beispiel: {"document_id": 5}.',
            Schema::object([
                'document_id' => Schema::integer('ID aus list_documents oder store_document'),
                'page' => Schema::integer('Seite des PDFs, 1 bis 20'),
                'half' => Schema::choice([ImageBlocks::HALF_TOP, ImageBlocks::HALF_BOTTOM], 'Nur die obere oder untere Hälfte des Bildes'),
            ], ['document_id']),
            Tool::annotations('Beleg oder Foto lesen', 'R'),
            Scopes::READ,
            static function (array $args, Actor $actor) use ($documents): ToolResult {
                $found = $documents->get((int) $args['document_id'], $actor);
                $document = $found['document'];
                $page = isset($args['page']) ? (int) Validate::int('page', $args['page'], '{"document_id": 5, "page": 1}', 1, 20) : null;
                $half = isset($args['half']) ? Validate::enum('half', $args['half'], [ImageBlocks::HALF_TOP, ImageBlocks::HALF_BOTTOM]) : null;
                $original = $documents->originalFile($document);

                $images = ImageBlocks::forDocument($documents, $document, $page, $half);

                return ToolResult::json([
                    'document' => $documents->present($document),
                    'attachments' => $found['attachments'],
                    'text_content' => $document['text_content'],
                    'download_url' => $original === null ? null : $documents->linkFor($document, $original),
                ] + ($images['skipped'] === [] ? [] : ['skipped_pages' => $images['skipped']]), $images['blocks']);
            }
        ));

        $registry->add(new Tool(
            'list_documents',
            'Belege und Fotos auflisten',
            'Listet die Dokumente deiner Garagen, neueste zuerst (höchstens 500), ohne verworfene. status: wartet (Datei fehlt), unzugeordnet (Datei da, an nichts angehängt), zugeordnet, offen (wartet und unzugeordnet), verworfen oder alle. Beispiel: {"vehicle_id": 12, "status": "offen"}.',
            Schema::object([
                'vehicle_id' => Schema::integer('Nur Dokumente dieses Fahrzeugs'),
                'status' => Schema::choice(DocumentService::LIST_STATUSES, 'Standard: alle außer verworfene'),
                'doc_type' => Schema::choice(DocumentService::TYPES, 'Nur diese Art'),
            ]),
            Tool::annotations('Belege und Fotos auflisten', 'R'),
            Scopes::READ,
            static fn (array $args, Actor $actor): ToolResult => ToolResult::json(['documents' => $documents->list(
                $actor,
                isset($args['vehicle_id']) ? (int) $args['vehicle_id'] : null,
                isset($args['status']) ? (string) $args['status'] : null,
                isset($args['doc_type']) ? (string) $args['doc_type'] : null
            )])
        ));
    }
}
