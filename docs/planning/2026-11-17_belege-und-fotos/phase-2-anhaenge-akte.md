# Phase 2 — Anhänge, Datei-Parameter, Akte

**Rating:** standard. Modell: `sonnet`.

## Kontext — vor dem ersten Edit lesen

- Plan-README: [`README.md`](README.md) — E5, E9, E10, Kontrakt (`attach_document`, `store_document` mit `file`, `import_document_from_url`).
- Report-Back von [Phase 1](phase-1-ablage-kern.md) und `FINDINGS.md`.
- GARAGE: `app/src/Domain/DocumentService.php`, `DocumentRepository.php`, `app/src/Mcp/Tools/DocumentTools.php` (Phase 1), `app/src/Util/HttpClient.php` (`get($url, $maxBytes)`, SSRF-Schutz), `app/src/Domain/EventService.php` (`get` für `get_event`), `app/src/Domain/DossierService.php` (Abschnittsreihenfolge, `DossierFormat`), `app/src/Domain/SearchRepository.php` (eine Quelle je Abfrage, höchstens 10), `app/src/Domain/VehicleService.php` (`get_vehicle`-Ausgabe), `app/src/Domain/RecordMerger.php` → `TABLES` (welche Tabellen `source_document_id` haben), `docs/models.md`.
- OpenAI Apps SDK, Datei-Parameter: `_meta["openai/fileParams"]` = Liste der Feldnamen; Dateiobjekt `{download_url, file_id, mime_type?, file_name?}`, Pflicht `download_url` und `file_id` (https://developers.openai.com/apps-sdk/reference, gelesen 2026-11-17).
- Vault-Fehlerklassen: keine einschlägig (MCP-Abschnitt betrifft das Protokoll, das unverändert bleibt).

## Abnahmekriterien

- [ ] `attach_document {"document_id": D, "target": "ereignis", "target_id": E, "role": "beleg"}` → `angelegt`, `source_document_set: true`; zweiter gleicher Aufruf → `unveraendert`; Dokument wechselt auf `zugeordnet`; `events.source_document_id` = D (vorher NULL), ein bereits gesetzter Wert bleibt (`source_document_set: false`).
- [ ] Ziel einer anderen Garage oder eines anderen Fahrzeugs als `documents.vehicle_id` → `not_found`; Dokument ohne Fahrzeug bekommt beim ersten Anhang an ein Fahrzeug-Ziel dessen `vehicle_id`.
- [ ] `role: "titelbild"` nur mit `target: "fahrzeug"` und `doc_type: "foto"`, sonst `invalid_value`; ein neues Titelbild ersetzt das alte (alte Anhang-Zeile Rolle `titelbild` → wird Rolle `foto`).
- [ ] `attach_document` mit `text` schreibt `documents.text_content` (ersetzt).
- [ ] `get_event` enthält `documents:[{id, doc_type, title, role, preview_url}]`; `get_vehicle` enthält `documents:{count, open, cover:{document_id, preview_url}|null}`; `get_dossier` hat als 16. Abschnitt „Belege“ (Tabelle: Datum, Typ, Titel, hängt an); `search` findet ein Wort aus `text_content` (Treffer `type: "document"`).
- [ ] `store_document` mit `file: {"download_url": "https://…", "file_id": "f1"}` holt die Datei ab, `channel = chatgpt`; `download_url` auf `http://127.0.0.1/…` → `invalid_value` (Meldung aus `HttpClient`); `ticket_id` und `file` zusammen → `invalid_argument`.
- [ ] `import_document_from_url {"url": "https://…/rechnung.pdf", "doc_type": "rechnung"}` legt ab, `channel = url`.
- [ ] `tools/list` zeigt an `store_document` `_meta: {"openai/fileParams": ["file"]}` und im Schema `file` mit den vier Eigenschaften.

## Checkliste

### Anhänge

- [ ] `DocumentService::TARGETS` = `['fahrzeug' => 'vehicles', 'ereignis' => 'events', 'schaden' => 'damages', 'rueckruf' => 'recalls', 'reifensatz' => 'tire_sets', 'teil' => 'parts', 'messwert' => 'measurements', 'kilometerstand' => 'odometer_readings', 'zulassung' => 'registrations']`. Tabellen mit `source_document_id` (laut Migrationen 008–014): alle außer `vehicles`.
- [ ] `DocumentService::attach(int $documentId, string $target, int $targetId, string $role, ?string $text, Actor $actor): array` in `Transaction::run`: Dokument mit Sperre laden (`not_found`), Status `wartet`/`verworfen` → `invalid_value` („Erst das Original ablegen“ bzw. „Verworfen“); Ziel laden über `SELECT id, garage_id, vehicle_id FROM <tabelle> WHERE id = ? AND garage_id IN (…)` (`vehicles`: `id AS vehicle_id`), Schreibrecht per `VehicleAccess::writable($vehicleId)`; Fahrzeug passt nicht zum Dokument → `not_found`; Anhang `INSERT IGNORE` (Ergebnis `angelegt`/`unveraendert`); Rolle `beleg` → `UPDATE <tabelle> SET source_document_id = ? WHERE id = ? AND source_document_id IS NULL`; Status → `zugeordnet`; Audit `document.attach` mit `vehicle_id`.
- [ ] `DocumentRepository::attachmentsFor`, `documentsFor(string $table, int $id)`, `coverFor(int $vehicleId)`, `countsFor(int $vehicleId)` ergänzen.
- [ ] Tool `attach_document` (Scope `WRITE`, Annotation `W`) in `DocumentTools`.

### Datei-Parameter und URL-Import

- [ ] `Tool` um optionales `public array $meta = []` (letzter Konstruktor-Parameter) erweitern; `describe()` hängt `'_meta' => $meta` nur an, wenn nicht leer.
- [ ] `store_document`: Schema-Eigenschaft `file` = Objekt mit `download_url` (string), `file_id` (string), `mime_type` (string), `file_name` (string), `required: ['download_url','file_id']`; `meta: ['openai/fileParams' => ['file']]`. Handler: `file` → `DocumentService::importFromUrl($file['download_url'], 'chatgpt', …)`, dann `describe`.
- [ ] `DocumentService::importFromUrl(string $url, string $channel, Actor $actor, ?int $garageId, ?int $vehicleId): array` — `HttpClient` mit `$config['http']`, `get($url, max_bytes)`; `HttpClient`-Fehler kommen als `DomainError` durch.
- [ ] Tool `import_document_from_url` (Scope `FILES`, Annotation `W` + `O`).

### Lesen

- [ ] `EventService::get` (für `get_event`) um `documents` ergänzen, `VehicleService` (für `get_vehicle`) um `documents` — beide über `DocumentService`/`DocumentRepository`, `preview_url` per `FileLink::sign($id, '400.jpg')` (PDF ohne Seitenbilder: `null`).
- [ ] `DossierService`: Abschnitt 16 „Belege“ nach „Offene Klärfälle“; ohne Belege „Noch nichts erfasst.“; Format über `DossierFormat` wie die anderen Tabellen; keine Links (die Akte ist dauerhaft, Links laufen ab).
- [ ] `SearchRepository`/`SearchService`: Quelle `document` über `documents.title` und `text_content` (`LIKE` mit escapten `%`/`_` wie die anderen Quellen, Status ≠ `verworfen`), `snippet` aus `text_content` um den Treffer.

### Doku

- [ ] ADR-013 `docs/decisions/013-belegbezug-ueber-anhaenge.md` (E5, E6; verworfen: `source_document_id` in jedes Schreib-Tool — 9 Schreibwege, Risiko für M2-Abgleich).
- [ ] ADR-015 anlegen `docs/decisions/015-weg-a-datei-parameter-und-widget.md` mit Teil „Datei-Parameter“ (E10); Teil „Widget“ ergänzt Phase 6.
- [ ] `docs/routes.md` (Tools, Erweiterungen `get_event`/`get_vehicle`/`get_dossier`/`search`), `docs/code-map.md`, `app/mcp/leitfaden-erfassung.md`: Schritt „Beleg anhängen“ nach `record_service_event` (`attach_document … role beleg`, erkannten Text mitgeben), „Tacho-Foto → `record_odometer` + `attach_document target kilometerstand`“.

## Report-Back

**Status:** complete (2026-11-17), deployt auf Strato, keine Migration nötig. Alle Kästchen oben erfüllt.

**Geprüft (Node gegen `https://quantum-canvas.de/garage`, 44 Prüfungen grün):** `tools/list` (`_meta` und Schema von `file`; Token ohne `garage:files` sieht `import_document_from_url` nicht, `attach_document` schon), `attach_document` (angelegt/unveraendert, `source_document_set` true/false, Status `zugeordnet`, Fahrzeug aus erstem Anhang, anderes Fahrzeug/andere Garage/fremder Nutzer → `not_found`, Dokument `wartet` → `invalid_value`, Titelbild nur Foto am Fahrzeug, neues Titelbild macht das alte zu `foto`, `text` ersetzt `text_content`), `get_event`/`get_vehicle` (`documents`, `preview_url` liefert JPEG), `get_dossier` (16 Abschnitte, Tabelle „Belege“), `search` (Treffer `document`, fremder Nutzer findet nichts), `store_document` mit `file` (`channel` chatgpt, `127.0.0.1` → `invalid_value`, `ticket_id` + `file` → `invalid_argument`), `import_document_from_url` (`channel` url, `localhost` → `invalid_value`, JSON-Antwort → `type_rejected`, PDF byte-gleich → Dublette).

**Abweichungen:**

- `get_event` und `get_vehicle` bekommen `documents` in der Tool-Schicht (`DocumentService::forTarget`/`forVehicle`), nicht in `EventService::get`/`VehicleService::get`: beide Dienste kennen die Konfiguration für die Links nicht, und die Akte ruft `EventService::get` ohne Links. Das Ergebnis ist wie geplant.
- `HttpClient` meldet abgewiesene Adressen als `url_rejected`; `DocumentService::importFromUrl` übersetzt das in `invalid_value` (Meldung bleibt).
- Zusätzliche Repository-Methoden `findTarget`, `insertAttachment`, `findAttachmentId`, `demoteCover`, `setSourceDocument`.

**Offen / Hinweise:** 🟡 Die Produktionskonfiguration folgt Weiterleitungen (`max_redirects` 4); ob ChatGPT-Download-Adressen öffentlich abrufbar sind, zeigt erst die Verprobung (Inhaber, Phase 7). Prüfdaten (Fahrzeuge „M3P2 …“, Dokumente 3–13) liegen in den Testgaragen auf Strato.
