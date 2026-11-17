# Phase 1 — Ablage-Kern

**Rating:** heikel (Sicherheit: signierte Links, Upload ohne Login, Bildverarbeitung). Modell: `opusplan`.

## Kontext — vor dem ersten Edit lesen

- Plan-README: [`README.md`](README.md) — Entscheidungen E1–E8, E16, Kontrakt (Klassen, Tools, Routen).
- Repo: `AGENTS.md`, `docs/conventions/php.md`, `docs/code-map.md`, `docs/models.md` („Regeln für jede neue Tabelle“), `docs/routes.md`.
- Vorbild-Code GARAGE: `app/src/Mcp/Tools/NoteTools.php` (Tool-Registrierung), `app/src/Domain/VehicleAccess.php` (`readable`/`writable`, `not_found` für Fremdes), `app/src/Domain/AuditRepository.php`, `app/src/Db/Transaction.php`, `app/src/Http/Response.php` (`file`, `json`, `error`), `app/src/Http/Request.php` (`body`, `files`, `contentLength`), `app/src/Api/OpenItemController.php` (Controller-Form), `app/src/Mcp/ToolResult.php` (`json($data, $images)`), `app/src/Mcp/McpController.php` (`services`, `registry`), `app/src/Auth/Jwt.php` (base64url-Helfer), `app/config.php`, `app/migrations/015_note.sql` (Migrationsform), `deploy.cmd` (legt `storage/documents` schon an und gleicht `storage/` nie ab).
- Vorbild-Code DISPATCH (nur lesen, Pfade absolut): `C:\Users\sasch\develop\Blog\app\src\Domain\MediaService.php` (`store`, `detectType`, temporärer Ordner + `rename`, `removeDir`), `C:\Users\sasch\develop\Blog\app\src\Util\ImageProcessor.php` (Pixelgrenze vor dem Dekodieren, Orientierung, Neukodierung), `C:\Users\sasch\develop\Blog\app\src\Mcp\ImageBlocks.php`, `C:\Users\sasch\develop\Blog\docs\decisions\011-medien-http-client-ssrf-bildvarianten.md`.
- Vault-Fehlerklassen: `~/.claude/knowledge/protokolle/mcp.md` → „Fehlerklassen“ (Server nach altem Schema — hier nichts an `Protocol` ändern).

## Abnahmekriterien

- [ ] Migration `017_document.sql` legt `documents`, `attachments`, `upload_tickets` an (Spalten unten); Deploy meldet sie als angewendet.
- [ ] `request_upload` → `upload_url`; `curl -X POST --data-binary @foto.jpg <upload_url>` → 201 mit `document_id`, Status `unzugeordnet`; zweiter POST auf dieselbe Adresse → 410 `ticket_used`; nach 15 Minuten → 410 `ticket_expired`; unbekannter Slug → 404 `ticket_not_found`.
- [ ] Dasselbe mit `-F file=@rechnung.pdf` (multipart) → 201; die gespeicherte `original.pdf` hat dieselbe SHA-256 wie die Eingabe.
- [ ] `store_document {"ticket_id": …, "vehicle_id": …, "doc_type": "rechnung", "title": "…", "text": "…"}` setzt die Metadaten; Fahrzeug einer fremden Garage → `not_found`; `store_document` ohne `ticket_id` (und ohne `file`) → Dokument mit Status `wartet` und `upload`-Block (neues Ticket, an das Dokument gebunden; ein Upload darauf füllt genau dieses Dokument).
- [ ] Dieselbe Datei erneut hochgeladen (neues Ticket) → 201 mit `duplicate: true` und derselben `document_id`; das Ticket gilt als eingelöst.
- [ ] Ein JPEG mit GPS-EXIF: gespeicherte `original.jpg`, `1568.jpg`, `400.jpg` haben keine EXIF-Daten (`exif_read_data` liefert kein `GPSLatitude`, geprüft per `php -r` an einer heruntergeladenen Datei); `captured_on` = Datum aus `DateTimeOriginal`. Ein hochkant fotografiertes Bild (Orientation 6) steht aufrecht.
- [ ] HTML-, SVG- oder HEIC-Datei → 415 `type_rejected`; 31 MB → 413 `too_large`; ein Bild über 50 Megapixel → 422 `too_many_pixels`.
- [ ] `get_document` auf ein Foto → Text-Block plus ein Bildblock (`image/jpeg`, Kante ≤ 1568); auf ein PDF ohne Seitenbilder → nur Text-Block mit `download_url`. `download_url` liefert die Datei; derselbe Slug mit verändertem letzten Zeichen → 404; nach 600 Sekunden → 404.
- [ ] `list_documents` zeigt nur Dokumente der eigenen Garagen; Nutzer B bekommt für die `document_id` von A `not_found` (`get_document`). Ein signierter Link funktioniert für jeden, der ihn hat, 10 Minuten lang — gewollt, der Link ist die Berechtigung (E3).
- [ ] Rolle `lesend` → `store_document`, `request_upload` `forbidden`; Token ohne `garage:files` sieht die beiden nicht in `tools/list`.
- [ ] `https://quantum-canvas.de/garage-app/storage/documents/` → 403.
- [ ] 29-MB-PDF und ein Foto ≥ 40 Megapixel laufen auf Strato durch; Spitzenverbrauch im Log < 400 MB.

## Tabellen (`app/migrations/017_document.sql`)

Kopfkommentar: `-- Meilenstein 3, Phase 1: Belege und Fotos, Anhaenge, Upload-Tickets.` Form wie `015_note.sql` (InnoDB, utf8mb4_unicode_ci, FKs ausgeschrieben).

`documents`: `id` INT UNSIGNED PK AI · `garage_id` INT UNSIGNED NOT NULL (FK `garages`) · `vehicle_id` INT UNSIGNED NULL (FK `vehicles`) · `uid` CHAR(12) NOT NULL UNIQUE (`d` + 11 Hex, Ordnername) · `doc_type` ENUM('foto','rechnung','hu_bericht','police','bescheid','fahrzeugschein','kaufvertrag','sonstiges') NOT NULL DEFAULT 'sonstiges' · `photo_tag` ENUM('aussen','innen','motorraum','tacho','reifen','schaden') NULL · `title` VARCHAR(200) NOT NULL DEFAULT '' · `mime` VARCHAR(40) NULL (NULL solange `wartet`) · `bytes` INT UNSIGNED NULL · `sha256` CHAR(64) NULL · `width`, `height` SMALLINT UNSIGNED NULL · `page_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0 (Seitenbilder; Foto = 1) · `captured_on` DATE NULL · `text_content` MEDIUMTEXT NULL · `status` ENUM('wartet','unzugeordnet','zugeordnet','verworfen') NOT NULL · `status_reason` VARCHAR(200) NULL · `channel` ENUM('ticket','chatgpt','url','widget','web') NOT NULL (Weg der Datei; bei `wartet` der geplante: `widget`) · `actor` VARCHAR(160) NOT NULL · `user_id` INT UNSIGNED NULL · `created_at`, `updated_at` DATETIME NOT NULL. Indizes: UNIQUE `(garage_id, sha256)`, `(garage_id, status)`, `(vehicle_id, status)`.

`attachments` (Tabelle in Phase 1 anlegen, Logik in Phase 2): `id` PK · `garage_id` · `document_id` FK `documents` · `target_table` VARCHAR(40) · `target_id` INT UNSIGNED · `role` ENUM('beleg','foto','anhang','titelbild') · `actor` · `user_id` NULL · `created_at`. UNIQUE `(document_id, target_table, target_id, role)`, Index `(target_table, target_id)`.

`upload_tickets`: `id` PK · `ticket_hash` CHAR(64) UNIQUE (SHA-256 des Slugs) · `user_id` FK `users` · `garage_id` FK `garages` · `vehicle_id` NULL · `document_id` NULL (gesetzt beim Einlösen oder von vornherein bei `wartet`) · `max_bytes` INT UNSIGNED · `expires_at` DATETIME · `redeemed_at` DATETIME NULL · `created_at`.

## Checkliste

### Konfiguration

- [ ] `app/config.php` um Block `'documents' => ['max_bytes' => 31457280, 'max_megapixels' => 50, 'max_edge' => 4000, 'variant_widths' => [1568, 400], 'jpeg_quality' => 90, 'variant_quality' => 85, 'ticket_seconds' => 900, 'link_seconds' => 600]` ergänzen; `oauth.default_scopes` → `['garage:read', 'garage:write', 'garage:files']`.
- [ ] `web/index.html`: Hinweis bei `scope-files` von „In dieser Version noch ohne Wirkung.“ auf „darf Belege und Fotos hochladen.“ ändern.

### `Util\ImageProcessor` (aus DISPATCH, angepasst)

- [ ] Datei `app/src/Util/ImageProcessor.php`, Namespace `Garage\Util`. Übernimmt aus DISPATCH: `getimagesizefromstring` + Pixelgrenze **vor** `imagecreatefromstring` (`too_many_pixels`, Status 422), `imagepalettetotruecolor`, `readOrientation`/`applyOrientation` (nur JPEG). Abweichend: Ausgabe immer JPEG (Transparenz vorher auf Weiß legen: neues Truecolor-Bild, weiß füllen, `imagecopy`); `original.jpg` auf `max_edge` begrenzt (größer → `imagescale`), Qualität `jpeg_quality`; Varianten je `variant_widths` als `<w>.jpg` (Kante = längere Seite, nicht Breite; nur wenn kleiner als das Original, sonst Kopie des Originals unter dem Variantennamen), Qualität `variant_quality`.
- [ ] `readCapturedOn(string $bytes): ?string` — `exif_read_data` über temporäre Datei wie `readOrientation`, Feld `DateTimeOriginal` (`YYYY:MM:DD HH:MM:SS`) → `YYYY-MM-DD`; Fehler oder fehlende Erweiterung → `null` (in `try … catch (\Throwable)`).
- [ ] Rückgabe `array{width:int, height:int, captured_on:?string, files:list<string>, peak_bytes:int}`; `Log::info('Bild verarbeitet', ['peak' => …, 'mp' => …])`.

### `Auth\FileLink`

- [ ] `sign(int $documentId, string $file, ?int $ttl = null): string` — Payload `{"d":<id>,"f":"<file>","e":<unix-ablauf>}`, Slug `base64url(payload) . '.' . base64url(hash_hmac('sha256', payload, key, true))`, `key = hash_hmac('sha256', 'garage-files', Env::get('GARAGE_JWT_SECRET'), true)`; ohne Secret (< 64 Zeichen) → `DomainError('not_configured')`.
- [ ] `verify(string $slug): array{document_id:int, file:string}` — `hash_equals` auf die Signatur, Ablauf prüfen, `f` gegen `^(original\.(jpg|pdf)|1568\.jpg|400\.jpg|pages/[0-9]{1,2}\.jpg)$`; jeder Fehler → `DomainError('not_found', 'Der Link ist ungültig oder abgelaufen.')`.

### Domain

- [ ] `DocumentRepository` (`app/src/Domain/DocumentRepository.php`): `insert(array $row): int`, `find(int $id, Actor $actor, bool $lock = false): ?array` (Bedingung `garage_id IN (<garageIds des Akteurs>)`), `findBySha(int $garageId, string $sha): ?array`, `update(int $id, int $garageId, array $fields): void`, `list(Actor $actor, ?int $vehicleId, ?string $status, ?string $docType): array` (neueste zuerst, höchstens 500), `insertTicket(array $row): int`, `findTicketByHash(string $hash, bool $lock): ?array` (bewusst **ohne** Akteur — der Slug ist die Berechtigung; einzige Ausnahme von Regel 3 in AGENTS.md, Kommentar an der Methode), `redeemTicket(int $ticketId, int $documentId): void`, `attachmentsFor(int $documentId): array` (Phase 1 leer).
- [ ] `DocumentService` (`app/src/Domain/DocumentService.php`), Konstanten `TYPES` (aus E8), `TAGS`, `MIMES = ['image/jpeg','image/png','image/webp','image/gif','application/pdf']`.
  - `storeBytes(string $bytes, array $ticket|null, string $channel, Actor $actor, ?int $garageId, ?int $vehicleId, ?int $intoDocumentId = null): array` — Größe prüfen (`too_large`), Typ per `finfo` (`type_rejected`, Meldung nennt erlaubte Typen), SHA-256; Dublette in der Garage → vorhandenes Dokument + `duplicate: true` (bei `intoDocumentId` mit Dublette: das wartende Dokument bekommt `status = verworfen`, `status_reason = 'Dublette von #<id>'`, Ergebnis ist die Dublette). Sonst Ordner `storage/documents/<garage_id>/.tmp-<hex>` schreiben (Bild: `ImageProcessor`; PDF: `original.pdf` byte-genau), `rename` auf `<uid>`, Datensatz in `Transaction::run` anlegen bzw. das wartende Dokument füllen, Audit `document.store` mit `{channel, sha256, mime}`. Fehler → Ordner entfernen (wie DISPATCH `removeDir`).
  - `describe(int $documentId, array $input, Actor $actor): array` — prüft `doc_type` (Pflicht bei `store_document`), `photo_tag` (nur bei `foto`), `title` (0–200), `captured_on` (`Validate::date`), `text` (0–200.000 Zeichen), `vehicle_id` (muss in derselben Garage liegen → sonst `not_found`); schreibt, Audit `document.describe`.
  - `issueTicket(Actor $actor, ?int $garageId, ?int $vehicleId, ?int $documentId = null): array` — Garage bestimmen: aus `vehicle_id` über `VehicleAccess::readable`, sonst `garage_id`, sonst die einzige Garage mit Rolle `inhaber`/`mitglied` (mehrere → `invalid_value` mit Liste, wie `create_vehicle`); Rolle `lesend` → `forbidden`. Slug `bin2hex(random_bytes(16))`, speichern als Hash. Rückgabe `{ticket_id, upload_url: base_url . '/garage/upload/' . slug, expires_at (ISO 8601 UTC), max_bytes, accepted: ['image/jpeg','image/png','image/webp','image/gif','application/pdf']}`.
  - `redeemTicket(string $slug, string $bytes): array` — Ticket mit Sperre laden, 404/410-Fehler (`ticket_not_found`, `ticket_expired`, `ticket_used`), Akteur aus `ticket.user_id` per `Actor::forUser(..., Actor::VIA_MCP, 'upload', [Scopes::FILES], $pdo)` bauen und Rolle in `ticket.garage_id` erneut prüfen (Mitgliedschaft kann inzwischen weg sein → `ticket_not_found`), dann `storeBytes`, Ticket einlösen.
  - `get(int $id, Actor $actor): array`, `list(...)`, `filePath(array $document, string $file): string` (nur bekannte Namen wie in `FileLink::verify`).
  - Schreibrecht: Rolle `inhaber`/`mitglied` in der Garage (über `$actor->roles`), **ohne** `garage:write`-Prüfung — der Scope `garage:files` prüft die Registry. Eigene Methode `assertCanStore(int $garageId, Actor $actor)`.
- [ ] Status-Regel: nach `storeBytes` `unzugeordnet`, nach Anhang (Phase 2) `zugeordnet`.

### Web-Endpunkte

- [ ] `Api\UploadController::upload(Request $request, string $slug)` — Slug-Format `^[0-9a-f]{32}$` sonst 404; Bytes: bei `multipart/form-data` die Datei aus `$request->files()['file']` (`tmp_name` lesen, Upload-Fehler `UPLOAD_ERR_INI_SIZE`/`FORM_SIZE` → 413), sonst `$request->body()`; leere Bytes → 422 `invalid_value`. Fehlercodes über `ApiErrors` abbilden (`too_large` 413, `type_rejected` 415, `too_many_pixels` 422, `ticket_*` 404/410) — `ApiErrors` um diese Codes ergänzen.
- [ ] `Api\FileController::show(Request $request, string $slug)` — `FileLink::verify`, Dokument **ohne Akteur** über `DocumentRepository::findById` (neue Methode, Kommentar wie oben), Status `verworfen` gilt weiter (Menschen dürfen Verworfenes ansehen), `Response::file($path, $mime, [Content-Disposition inline; filename="<uid>.<ext>", nosniff, CSP sandbox, Cache-Control private max-age=600], $request->method() !== 'HEAD')`.
- [ ] Routen in `app/index.php`: `POST /garage/upload/{slug}`, `GET /garage/files/{slug}`. Prüfen, dass der Router `{slug}` mit Punkt und `-`/`_` erlaubt (base64url + `.`); sonst Muster in `Router` für `{slug}` auf `[A-Za-z0-9._-]+` erweitern.

### MCP

- [ ] `Mcp\ImageBlocks` (`app/src/Mcp/ImageBlocks.php`): `forDocument(DocumentService $docs, array $document, ?int $page, ?string $half, int $max = 6): array{blocks, skipped}` — Foto: `1568.jpg`; PDF mit `page_count > 0`: `pages/<n>.jpg` (ohne `page`: Seiten 1..min(6, page_count)); `half` schneidet per GD obere bzw. untere Hälfte mit 5 % Überlappung aus und kodiert JPEG 85. Ohne darstellbare Datei → keine Blöcke.
- [ ] `Mcp\Tools\DocumentTools` mit `request_upload`, `store_document` (Phase 1: nur `ticket_id` oder nichts; `file` ab Phase 2), `get_document`, `list_documents` gemäß Kontrakt. Beschreibungen mit Beispiel-JSON wie `NoteTools`. `store_document` Annotation `W`, `request_upload` `W`, Scope `Scopes::FILES`; `get_document`/`list_documents` `R`, `Scopes::READ`. `get_document` Text-Block: `{document:{…}, attachments:[], text_content, download_url: FileLink für original}`.
- [ ] `McpController::services` um `'documents' => new DocumentService($pdo, $config)` ergänzen; `registry()` neue Gruppe `'Belege'` nach `'Akte'` mit `DocumentTools::register`.
- [ ] `app/mcp/instructions.md`: Absatz „Belege“: Datei nie als Base64 ins Argument; Wege: Datei-Parameter (ChatGPT), sonst `request_upload` + Upload + `store_document {ticket_id}`; ohne Dateizugriff `store_document` ohne Datei → Status `wartet`, dem Menschen sagen, dass er das Original im Eingang oder auf der Beleg-Seite nachreicht.

### Doku (gleiche Phase)

- [ ] ADR-012 `docs/decisions/012-belegablage-und-auslieferung.md` (10 Zeilen: E1–E4, E7; verworfen: Original-Bytes von Fotos behalten, Auslieferung über Login-Cookie, Base64 im Tool-Argument).
- [ ] `docs/models.md` (017, drei Tabellen), `docs/routes.md` (zwei Routen, vier Tools), `docs/code-map.md` (Zeilen `Domain` Belege, `Util\ImageProcessor`, `Auth\FileLink`, `Api\UploadController`/`FileController`, `Mcp\ImageBlocks`, `Tools/DocumentTools`), `docs/glossary.md` (Beleg, Dokument, Upload-Ticket, signierter Link, „Original fehlt“).

## Report-Back
