# Phase 3 — Eingang auf dem Server

**Rating:** heikel (Reservierung mit Frist ohne Cron, Web-Upload mit Seitenbildern). Modell: `opusplan`.

## Kontext — vor dem ersten Edit lesen

- Plan-README: [`README.md`](README.md) — E7, E11, Kontrakt (Eingangs-Tools, Routen `/garage/api/documents…`, `/garage/api/inbox…`).
- Report-Back der Phasen 1–2 und `FINDINGS.md`.
- GARAGE: `DocumentService`/`DocumentRepository`/`DocumentTools` (Phasen 1–2), `app/src/Mcp/ImageBlocks.php`, `app/src/Api/OpenItemController.php` und `app/src/Auth/ApiAuth.php` (`user()` → `Actor` mit `VIA_WEB`), `app/src/Api/ApiErrors.php`, `app/src/Mcp/Prompts.php` (Prompt-Form), `app/src/Domain/OpenItemService.php` (`listForHumans`, Rollenprüfung im Browser).
- DISPATCH (Muster für Reservieren mit Frist): `C:\Users\sasch\develop\Blog\app\src\Domain\QueueService.php` (`claim`) und `C:\Users\sasch\develop\Blog\app\src\Mcp\Tools\QueueTools.php` (`claim_queue_item` mit Bildern).
- Plan-Regel „Zeitabhängige Prüfungen“: die Frist wird nur über den Rohwert `claimed_until` gegen `UTC_TIMESTAMP()` ausgewertet.
- Vault-Fehlerklassen: keine einschlägig.

## Abnahmekriterien

- [x] Migration `018_inbox.sql` legt `inbox_items` und `inbox_documents` an.
- [x] `POST /garage/api/documents` (multipart `file` = PDF, `pages[]` = 2 JPEGs) → 201, `page_count = 2`, Dateien `pages/1.jpg`, `pages/2.jpg`; `pages[]` bei einem Foto → `invalid_value`; mehr als 20 Seiten → `invalid_value`; Rolle `lesend` → 403.
- [x] `POST /garage/api/inbox {"document_ids":[a,b,c], "note":"Inspektion Mai"}` → 201; Dokumente einer fremden Garage → 404; leere Liste → 422.
- [x] `list_inbox` zeigt den Eintrag `neu`; `claim_inbox_item {}` reserviert den ältesten, liefert `item`, `documents` und höchstens 6 Bildblöcke (Seiten in Reihenfolge `inbox_documents.position`); ein zweiter `claim_inbox_item {"id": X}` desselben Eintrags von einem anderen Token → `invalid_value` „reserviert bis …“; vom selben Akteur → Frist wird verlängert.
- [x] Frist-Ablauf: mit `minutes: 10` reservieren, nach 11 Minuten zeigt `list_inbox` den Eintrag als `neu`, `claim_inbox_item` durch ein anderes Token gelingt, `release_inbox_item` des ersten Tokens → `invalid_value` „Reservierung abgelaufen“.
- [x] `release_inbox_item {"id": X, "outcome": "erfasst", "result": "Inspektion 05/2025 erfasst"}` → `erfasst`; `outcome: klaerfall` → Status `klaerfall` **und** ein Klärfall `nachfragen` am Fahrzeug (ohne Fahrzeug: am Eintrag nur Status, Klärfall ohne `vehicle_id`); `zurueck` → `neu`. Release durch jemand anderen als den Reservierenden → `invalid_value`.
- [x] `GET /garage/api/inbox` und `GET /garage/api/documents` (mit `preview_url`) liefern nur eigene Garagen; `GET /garage/api/documents/{id}` liefert `pages` als signierte Links und `original_url`.
- [x] `PATCH /garage/api/documents/{id}` ändert Typ/Fahrzeug/Titel/Schlagwort; `discard`/`restore` wechseln `verworfen` ↔ vorheriger Status (aus Anhängen abgeleitet: Anhang da → `zugeordnet`, sonst `unzugeordnet`, keine Datei → `wartet`).
- [x] `POST /garage/api/documents/{id}/file` füllt ein Dokument `wartet`; bei einem anderen Status → 409 `already_has_file`.
- [x] Prompt `eingang_abarbeiten` erscheint in `prompts/list` und beschreibt die Schleife `list_inbox` → `claim_inbox_item` → erfassen → `attach_document` → `release_inbox_item`.

## Tabellen (`app/migrations/018_inbox.sql`)

`inbox_items`: `id` PK · `garage_id` FK · `vehicle_id` NULL FK · `note` VARCHAR(200) NOT NULL DEFAULT '' · `status` ENUM('neu','in_arbeit','erfasst','klaerfall','verworfen') NOT NULL DEFAULT 'neu' · `claimed_by` VARCHAR(160) NULL (Akteur-Name) · `claimed_user_id` INT NULL · `claimed_until` DATETIME NULL · `result` VARCHAR(500) NULL · `open_item_id` INT NULL · `actor`, `user_id`, `created_at`, `updated_at`. Index `(garage_id, status, created_at)`.

`inbox_documents`: `inbox_item_id` FK · `document_id` FK · `position` SMALLINT UNSIGNED · PK `(inbox_item_id, document_id)`, Index `(inbox_item_id, position)`.

## Checkliste

### Domain

- [x] `InboxRepository`: `insert`, `addDocuments`, `find(int $id, Actor, bool $lock)`, `list(Actor, ?string $status, ?int $garageId, int $limit)`, `oldestClaimable(Actor): ?int` (SQL-Bedingung: `status = 'neu' OR (status = 'in_arbeit' AND claimed_until < UTC_TIMESTAMP())`, `ORDER BY created_at` , `FOR UPDATE`), `documents(int $itemId)`.
- [x] `InboxService`:
  - `create(array $documentIds, ?int $vehicleId, string $note, Actor $actor)` — nur Menschen (`via web`), Dokumente alle in **einer** Garage des Akteurs, Rolle `inhaber`/`mitglied`.
  - `effectiveStatus(array $row): string` — `in_arbeit` mit `claimed_until < now` → `neu` (einzige Stelle; `list`/`get` geben diesen Wert aus).
  - `claim(?int $id, int $minutes, Actor $actor): array` — in `Transaction::run`, Zeile sperren; frei, wenn effektiver Status `neu` oder `claimed_by === $actor->name`; setzt `in_arbeit`, `claimed_by`, `claimed_user_id`, `claimed_until = UTC + minutes`; Audit `inbox.claim`.
  - `release(int $id, string $outcome, ?string $result, Actor $actor)` — nur der Reservierende mit noch laufender Frist (Rohwert-Prüfung `claimed_until >= now`; abgelaufen → `invalid_value` „Reservierung abgelaufen, erneut claim_inbox_item“); `klaerfall` legt über `OpenItemService::create` einen `nachfragen`-Klärfall mit `result` als Text an; Audit `inbox.release`.
  - `discard`/`restore` (nur Mensch, nur aus/zu `neu`).
- [x] `DocumentService::storeWebUpload(array $file, array $pages, ?int $vehicleId, ?string $docType, Actor $actor, ?int $intoDocumentId = null)` — `file` über `storeBytes` mit Kanal `web`; Seitenbilder nur bei PDF, je Seite `ImageProcessor` auf Kante 1568 (nur eine Datei `pages/<n>.jpg`, Methode `processPage` ergänzen), `page_count` setzen. Garage aus Fahrzeug, sonst einzige beschreibbare Garage, sonst `garage_id` Pflicht (Body-Feld `garage_id`).
- [x] `DocumentService::discard/restore/update` für Menschen.

### MCP

- [x] `Mcp\Tools\InboxTools`: `list_inbox` (R, READ), `claim_inbox_item` (W, WRITE; Text-Block `{item, documents:[{id, doc_type, title, page_count, mime}], next_steps: "Erfassen, attach_document je Dokument, dann release_inbox_item"}` + `ImageBlocks` über alle Seiten aller Dokumente, höchstens 6 — die übrigen per `get_document` mit `page`), `release_inbox_item` (W, WRITE). Schreibrecht wie `VehicleAccess::assertCanWriteGarage` für die Garage des Eintrags.
- [x] `McpController`: Service `'inbox'`, Gruppe `'Eingang'`.
- [x] `Prompts`: `eingang_abarbeiten` (ohne Argument), Text wie Kontrakt-Schleife, „ein unleserlicher Kilometerstand wird Klärfall, kein geratener Wert“.

### Web-API

- [x] `Api\DocumentController` (`list`, `show`, `create`, `uploadFile`, `update`, `discard`, `restore`) und `Api\InboxController` (`list`, `create`, `discard`, `restore`), Form wie `OpenItemController`; Routen laut Kontrakt in `app/index.php`. Multipart-Felder: `file`, `pages[]` (über `$request->files()`), `vehicle_id`, `garage_id`, `doc_type`.
- [x] `ApiErrors`: `already_has_file` → 409.

### Doku

- [x] ADR-016 `docs/decisions/016-eingang-reservierung-ohne-cron.md` (E11; verworfen: Cron, Ablauf beim Schreiben).
- [x] `docs/models.md` (018), `docs/routes.md` (Web-Routen, Tools, Prompt), `docs/code-map.md`, `app/mcp/instructions.md` (Eingang abarbeiten), `docs/glossary.md` (Eingang, Reservierung).

## Report-Back

**Status:** complete (2026-11-17), deployt auf Strato, Migration 018 angewendet, Smoke-Test grün. Alle Kästchen oben erfüllt.

**Geprüft (Node gegen `https://quantum-canvas.de/garage`, 79 Prüfungen grün):** PDF mit zwei Seitenbildern (`page_count` 2, `pages/1.jpg`/`2.jpg`), 20 Seiten → 201, 21 Seiten → 422, Seiten bei einem Foto → 422, `lesend` → 403; Eingang anlegen (fremde Garage → 404, leer → 422); `claim_inbox_item` (ältester zuerst, Fristverlängerung durch denselben Akteur, fremder Zugriff → `invalid_value` „reserviert bis …“, höchstens 6 Bilder mit `not_shown`); `release_inbox_item` (`erfasst`, `klaerfall` mit und ohne Fahrzeug, `zurueck`, Fremder → `invalid_value`); Fristablauf: nach 10 Minuten zeigt `list_inbox` den Eintrag als `neu`, Token A2 übernimmt, Token A bekommt „Reservierung abgelaufen“ beim Abschließen, auch nach der Übernahme; Listen nur eigener Garagen; PATCH, `discard`/`restore`, `/file` füllt `wartet` und liefert danach 409; Prompt in `prompts/list` und `prompts/get`; `tools/list` mit drei neuen Tools; Agenten-Token an der Web-API → 401.

**Abweichungen:**

- **Kein Weg, `doc_type` eines Eingangs-Dokuments per Tool zu setzen:** `attach_document` ändert den Typ nicht; der Mensch setzt ihn per PATCH in der Oberfläche (Phase 4). 
- **PHP-Grenze `max_file_uploads` (20) verwarf Dateien stillschweigend:** 21 Dateien (Original plus 20 Seiten) ergaben 19 Seiten. Fix: `web/.user.ini` mit `max_file_uploads = 40` (Strato liest sie mit etwa 5 Minuten Zwischenspeicher) und Zugriffssperre für `.user.ini` in `web/.htaccess` (403 geprüft). Danach 20 Seiten → 201, 21 → 422.
- **Klärfall aus `release_inbox_item`** wird direkt über `OpenItemRepository::insert` geschrieben (Art `nachfragen`, Text „Eingang #id: result“), nicht über `OpenItemService`; der Dienst verlangt ein Fahrzeug.
- **Fehler im bestehenden Upload bei leerem Multipart-Body:** `contentLength() > 0` meldete 413; jetzt Vergleich mit `post_max_size` (`postMaxBytes()`), leerer Body → 422.
- **Testdaten auf Strato:** die Garagen „M3P3 Garage A/B“ mit drei Testkonten (`@example.invalid`) und Eingangsdaten bleiben stehen; in Phase 7 sperren oder löschen.
