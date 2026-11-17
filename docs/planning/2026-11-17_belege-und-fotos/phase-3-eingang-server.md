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

- [ ] Migration `018_inbox.sql` legt `inbox_items` und `inbox_documents` an.
- [ ] `POST /garage/api/documents` (multipart `file` = PDF, `pages[]` = 2 JPEGs) → 201, `page_count = 2`, Dateien `pages/1.jpg`, `pages/2.jpg`; `pages[]` bei einem Foto → `invalid_value`; mehr als 20 Seiten → `invalid_value`; Rolle `lesend` → 403.
- [ ] `POST /garage/api/inbox {"document_ids":[a,b,c], "note":"Inspektion Mai"}` → 201; Dokumente einer fremden Garage → 404; leere Liste → 422.
- [ ] `list_inbox` zeigt den Eintrag `neu`; `claim_inbox_item {}` reserviert den ältesten, liefert `item`, `documents` und höchstens 6 Bildblöcke (Seiten in Reihenfolge `inbox_documents.position`); ein zweiter `claim_inbox_item {"id": X}` desselben Eintrags von einem anderen Token → `invalid_value` „reserviert bis …“; vom selben Akteur → Frist wird verlängert.
- [ ] Frist-Ablauf: mit `minutes: 10` reservieren, nach 11 Minuten zeigt `list_inbox` den Eintrag als `neu`, `claim_inbox_item` durch ein anderes Token gelingt, `release_inbox_item` des ersten Tokens → `invalid_value` „Reservierung abgelaufen“.
- [ ] `release_inbox_item {"id": X, "outcome": "erfasst", "result": "Inspektion 05/2025 erfasst"}` → `erfasst`; `outcome: klaerfall` → Status `klaerfall` **und** ein Klärfall `nachfragen` am Fahrzeug (ohne Fahrzeug: am Eintrag nur Status, Klärfall ohne `vehicle_id`); `zurueck` → `neu`. Release durch jemand anderen als den Reservierenden → `invalid_value`.
- [ ] `GET /garage/api/inbox` und `GET /garage/api/documents` (mit `preview_url`) liefern nur eigene Garagen; `GET /garage/api/documents/{id}` liefert `pages` als signierte Links und `original_url`.
- [ ] `PATCH /garage/api/documents/{id}` ändert Typ/Fahrzeug/Titel/Schlagwort; `discard`/`restore` wechseln `verworfen` ↔ vorheriger Status (aus Anhängen abgeleitet: Anhang da → `zugeordnet`, sonst `unzugeordnet`, keine Datei → `wartet`).
- [ ] `POST /garage/api/documents/{id}/file` füllt ein Dokument `wartet`; bei einem anderen Status → 409 `already_has_file`.
- [ ] Prompt `eingang_abarbeiten` erscheint in `prompts/list` und beschreibt die Schleife `list_inbox` → `claim_inbox_item` → erfassen → `attach_document` → `release_inbox_item`.

## Tabellen (`app/migrations/018_inbox.sql`)

`inbox_items`: `id` PK · `garage_id` FK · `vehicle_id` NULL FK · `note` VARCHAR(200) NOT NULL DEFAULT '' · `status` ENUM('neu','in_arbeit','erfasst','klaerfall','verworfen') NOT NULL DEFAULT 'neu' · `claimed_by` VARCHAR(160) NULL (Akteur-Name) · `claimed_user_id` INT NULL · `claimed_until` DATETIME NULL · `result` VARCHAR(500) NULL · `open_item_id` INT NULL · `actor`, `user_id`, `created_at`, `updated_at`. Index `(garage_id, status, created_at)`.

`inbox_documents`: `inbox_item_id` FK · `document_id` FK · `position` SMALLINT UNSIGNED · PK `(inbox_item_id, document_id)`, Index `(inbox_item_id, position)`.

## Checkliste

### Domain

- [ ] `InboxRepository`: `insert`, `addDocuments`, `find(int $id, Actor, bool $lock)`, `list(Actor, ?string $status, ?int $garageId, int $limit)`, `oldestClaimable(Actor): ?int` (SQL-Bedingung: `status = 'neu' OR (status = 'in_arbeit' AND claimed_until < UTC_TIMESTAMP())`, `ORDER BY created_at` , `FOR UPDATE`), `documents(int $itemId)`.
- [ ] `InboxService`:
  - `create(array $documentIds, ?int $vehicleId, string $note, Actor $actor)` — nur Menschen (`via web`), Dokumente alle in **einer** Garage des Akteurs, Rolle `inhaber`/`mitglied`.
  - `effectiveStatus(array $row): string` — `in_arbeit` mit `claimed_until < now` → `neu` (einzige Stelle; `list`/`get` geben diesen Wert aus).
  - `claim(?int $id, int $minutes, Actor $actor): array` — in `Transaction::run`, Zeile sperren; frei, wenn effektiver Status `neu` oder `claimed_by === $actor->name`; setzt `in_arbeit`, `claimed_by`, `claimed_user_id`, `claimed_until = UTC + minutes`; Audit `inbox.claim`.
  - `release(int $id, string $outcome, ?string $result, Actor $actor)` — nur der Reservierende mit noch laufender Frist (Rohwert-Prüfung `claimed_until >= now`; abgelaufen → `invalid_value` „Reservierung abgelaufen, erneut claim_inbox_item“); `klaerfall` legt über `OpenItemService::create` einen `nachfragen`-Klärfall mit `result` als Text an; Audit `inbox.release`.
  - `discard`/`restore` (nur Mensch, nur aus/zu `neu`).
- [ ] `DocumentService::storeWebUpload(array $file, array $pages, ?int $vehicleId, ?string $docType, Actor $actor, ?int $intoDocumentId = null)` — `file` über `storeBytes` mit Kanal `web`; Seitenbilder nur bei PDF, je Seite `ImageProcessor` auf Kante 1568 (nur eine Datei `pages/<n>.jpg`, Methode `processPage` ergänzen), `page_count` setzen. Garage aus Fahrzeug, sonst einzige beschreibbare Garage, sonst `garage_id` Pflicht (Body-Feld `garage_id`).
- [ ] `DocumentService::discard/restore/update` für Menschen.

### MCP

- [ ] `Mcp\Tools\InboxTools`: `list_inbox` (R, READ), `claim_inbox_item` (W, WRITE; Text-Block `{item, documents:[{id, doc_type, title, page_count, mime}], next_steps: "Erfassen, attach_document je Dokument, dann release_inbox_item"}` + `ImageBlocks` über alle Seiten aller Dokumente, höchstens 6 — die übrigen per `get_document` mit `page`), `release_inbox_item` (W, WRITE). Schreibrecht wie `VehicleAccess::assertCanWriteGarage` für die Garage des Eintrags.
- [ ] `McpController`: Service `'inbox'`, Gruppe `'Eingang'`.
- [ ] `Prompts`: `eingang_abarbeiten` (ohne Argument), Text wie Kontrakt-Schleife, „ein unleserlicher Kilometerstand wird Klärfall, kein geratener Wert“.

### Web-API

- [ ] `Api\DocumentController` (`list`, `show`, `create`, `uploadFile`, `update`, `discard`, `restore`) und `Api\InboxController` (`list`, `create`, `discard`, `restore`), Form wie `OpenItemController`; Routen laut Kontrakt in `app/index.php`. Multipart-Felder: `file`, `pages[]` (über `$request->files()`), `vehicle_id`, `garage_id`, `doc_type`.
- [ ] `ApiErrors`: `already_has_file` → 409.

### Doku

- [ ] ADR-016 `docs/decisions/016-eingang-reservierung-ohne-cron.md` (E11; verworfen: Cron, Ablauf beim Schreiben).
- [ ] `docs/models.md` (018), `docs/routes.md` (Web-Routen, Tools, Prompt), `docs/code-map.md`, `app/mcp/instructions.md` (Eingang abarbeiten), `docs/glossary.md` (Eingang, Reservierung).

## Report-Back
