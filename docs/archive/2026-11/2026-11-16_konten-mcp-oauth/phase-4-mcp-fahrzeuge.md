# Phase 4 — MCP und Fahrzeug-Tools

Rating: **heikel** (Mandantentrennung, Konfliktregel der Fakten). Status: **complete** (2026-11-17, deployt, auf Strato per curl geprüft; claude.ai-Verbindung offen).

## Kontext — vorher lesen

- [`README.md`](README.md) → E3, E4, E5, E8, Kontrakt (Akteur, Rollen, MCP-Tools).
- `docs/design/konzept-garage.md` → „Datenmodell“ (Herkunft als Pflichtfeld, Regeln im Code) und „MCP-Oberfläche“ (Scopes, Server-Anweisungen 1–5).
- `AGENTS.md` → Critical Rules 3–5.
- DISPATCH ADR-016, ADR-017, ADR-018 (`C:\Users\sasch\develop\Blog\docs\decisions\`).
- DISPATCH-Vorlagen (`C:\Users\sasch\develop\Blog\app\`): `src/Mcp/Protocol.php`, `ProtocolError.php`, `Registry.php`, `Schema.php`, `Tool.php`, `ToolResult.php`, `McpController.php`, `Resources.php`, `Prompts.php`, `src/Auth/McpAuth.php`, ein Tool-Beispiel `src/Mcp/Tools/CategoryTools.php`, `mcp/instructions.md`.
- Fehlerklasse MCP (`~/.claude/knowledge/protokolle/mcp.md` → Fehlerklassen): Server nach altem Schema — die kopierte Schicht muss `server/discover` (2026-07-28) **und** `initialize` (ältere Clients) können; nichts davon wegkürzen.

## Abnahmekriterien

- [x] `POST /garage/mcp` ohne Token → 401 mit `WWW-Authenticate: Bearer … resource_metadata="https://quantum-canvas.de/.well-known/oauth-protected-resource/garage/mcp"`.
- [x] `tools/list` zeigt je Token nur Tools seiner Scopes: nur `garage:read` → `list_vehicles`, `get_vehicle`.
- [x] `create_vehicle` mit Rolle `lesend` oder ohne `garage:write` → Tool-Fehler `forbidden` mit Satz, was fehlt. Mit einer fremden `garage_id` → `not_found`.
- [x] `get_vehicle` / `set_vehicle_facts` mit der ID eines Fahrzeugs aus Garage „Test B“ durch Nutzer A → `not_found` (nie `forbidden`).
- [x] Konfliktregel `set_vehicle_facts`, je Feld:
  - kein aktueller Wert → neue Zeile `aktuell`, Ergebnis `angelegt`;
  - gleicher Wert, gleiche oder niedrigere Konfidenz → nichts schreiben, `unveraendert`;
  - gleicher Wert, höhere Konfidenz → alte Zeile `abgeloest`, neue `aktuell`, `aktualisiert`;
  - anderer Wert, aktueller ist **nicht** `bestaetigt` → alte `abgeloest`, neue `aktuell`, `aktualisiert`;
  - anderer Wert, aktueller ist `bestaetigt` → neue Zeile `strittig` + Klärfall (`open_items`, Art `konflikt`, Text nennt Feld, beide Werte und Quellen), `klaerfall` mit `open_item_id`.
- [x] Jede Schreibung erzeugt einen `audit_log`-Eintrag mit `before_json`/`after_json`.
- [x] Ungültige Werte (FIN nicht 17 Zeichen aus `A–H J–N P R–Z 0–9`, Datum nicht `YYYY-MM-DD`, Zahl nicht ganzzahlig ≥ 0, unbekanntes Feld, `source` oder `confidence` außerhalb der Liste) → Tool-Fehler `invalid_value` mit Feldname und Beispiel; keine Zeile geschrieben (ganze Anfrage in einer Transaktion).
- [x] Claude Code verbindet sich mit `gmcp_`-Token — per curl mit demselben Ablauf (`initialize`, `tools/list`, `list_vehicles`) geprüft, der `claude`-Client selbst nicht gestartet (`claude mcp add --transport http garage https://quantum-canvas.de/garage/mcp --header "Authorization: Bearer <token>"`) und ruft `list_vehicles` auf.

## Datenmodell — `app/migrations/006_vehicle.sql`

- `vehicles`: `id` PK, `garage_id` FK→garages NOT NULL, `label` VARCHAR(80) NOT NULL (Anzeigename, z. B. „Golf 7“), `status` ENUM('aktiv','verkauft','stillgelegt','geloescht') NOT NULL DEFAULT 'aktiv', `status_reason` VARCHAR(300) NULL, `created_by` VARCHAR(160) NOT NULL, `created_at`, `updated_at`; INDEX (`garage_id`, `status`).
- `vehicle_facts`: `id` PK, `garage_id` NOT NULL, `vehicle_id` FK→vehicles NOT NULL, `field` VARCHAR(40) NOT NULL, `value` VARCHAR(500) NOT NULL (immer als Text, Typ prüft der Service), `source` ENUM('beleg','nutzer','recherche','agent','werkstatt') NOT NULL, `source_url` VARCHAR(500) NULL, `source_document_id` INT UNSIGNED NULL (ohne FK bis Meilenstein 3), `confidence` ENUM('bestaetigt','wahrscheinlich','unsicher') NOT NULL, `status` ENUM('aktuell','abgeloest','strittig') NOT NULL, `actor` VARCHAR(160) NOT NULL, `user_id` INT UNSIGNED NULL, `created_at`; INDEX (`vehicle_id`, `field`, `status`), INDEX (`field`, `value`(40)).
- `open_items`: `id` PK, `garage_id` NOT NULL, `vehicle_id` NULL FK→vehicles, `kind` ENUM('konflikt','nachfragen','beobachten') NOT NULL, `text` TEXT NOT NULL, `ref_table` VARCHAR(40) NULL, `ref_id` INT UNSIGNED NULL, `status` ENUM('offen','geloest','verworfen') NOT NULL DEFAULT 'offen', `resolution` TEXT NULL, `resolved_by` VARCHAR(160) NULL, `resolved_at` NULL, `created_by` VARCHAR(160) NOT NULL, `created_at`; INDEX (`garage_id`, `status`).

Rangfolge der Konfidenz: `bestaetigt` > `wahrscheinlich` > `unsicher`.

### Erlaubte Felder (Konstante `VehicleService::FIELDS`, Feld → Typ)

`fin` fin · `hsn` text(4) · `tsn` text(3–10) · `marke` text · `modell` text · `variante` text · `motorcode` text · `getriebecode` text · `erstzulassung` date · `farbe` text · `farbcode` text · `kraftstoff` text · `leistung_kw` int · `hubraum_ccm` int · `schadstoffklasse` text · `co2_g_km` int · `leergewicht_kg` int · `zul_gesamtgewicht_kg` int · `listenneupreis_eur` int · `schluesselanzahl` int · `ablageort_papiere` text. `text` = 1–200 Zeichen nach `trim`.

## Checkliste

### MCP-Schicht kopieren
- [x] `app/src/Mcp/Protocol.php`, `ProtocolError.php`, `Schema.php`, `Tool.php`, `ToolResult.php` mit Namespace; `Tool` prüft Scopes gegen `Garage\Auth\Scopes`.
- [x] `app/src/Mcp/Registry.php`: `call(string $name, array $args, Actor $actor)` — nimmt den fertigen Akteur statt `$auth`; Scope-Prüfung über `$actor->scopes`.
- [x] `app/src/Mcp/Resources.php`, `Prompts.php`: Klassen behalten, Kataloge leer (`resources/list` und `prompts/list` liefern leere Listen; `resources/read` und `prompts/get` → `INVALID_PARAMS` „unbekannt“).
- [x] `app/src/Auth/McpAuth.php`: Präfixe `gmcp_` (über `TokenService::authenticate`) und `goa_` (über `TokenIssuer::authenticate`); Rückgabe enthält `user_id`.
- [x] `app/src/Mcp/McpController.php`: `SERVER_NAME` `garage`; `services()` nur `tokens`, `vehicles`, `pdo`, `config`; nach der Anmeldung `Actor::forUser($auth['user_id'], $auth['kind'], ($auth['kind'] === 'oauth' ? 'oauth:' : 'mcp:') . $auth['name'], $auth['scopes'], $pdo)`; `registry()` mit einer Gruppe „Fahrzeuge“; Scope-Texte `garage:read`.
- [x] `app/mcp/instructions.md`: die fünf Regeln aus dem Konzept („MCP-Oberfläche“ → Server-Anweisungen), ergänzt um „Vor `create_vehicle` mit `list_vehicles` prüfen, ob es das Fahrzeug schon gibt.“

### Fachlogik
- [x] `app/src/Domain/VehicleRepository.php`: jede Methode nimmt `Actor` und hängt `garage_id IN (<actor->garageIds()>)` an; leere Liste → leeres Ergebnis ohne Abfrage. Methoden: `listForActor`, `find(int $id, Actor)`, `insert`, `currentFacts(vehicleId)`, `disputedFacts(vehicleId)`, `insertFact`, `supersede(factId)`, `countOpenItems(vehicleIds)`.
- [x] `app/src/Domain/OpenItemRepository.php`: `insert(...)`: int.
- [x] `app/src/Domain/VehicleService.php`: `list(Actor)`, `get(int, Actor)`, `create(string $label, ?int $garageId, array $facts, Actor)`, `setFacts(int $vehicleId, array $facts, Actor)` — Validierung, Konfliktregel aus AK, alles in `Transaction::run`, Audit je Feld. Rechteprüfung: Fahrzeug nicht gefunden oder fremde Garage → `DomainError('not_found')`; gefunden, aber `!canWrite` → `DomainError('forbidden', 'Dieses Konto darf in der Garage nur lesen.' bzw. 'Dem Zugang fehlt garage:write.')`.
- [x] `app/src/Mcp/Tools/VehicleTools.php`: vier Tools nach README-Kontrakt; Beschreibungen nennen Beispiel-Eingaben; `readOnlyHint`/`destructiveHint`-Annotationen wie DISPATCH (keins ist destruktiv).
- [x] Route `POST /garage/mcp`.

### Prüfen (auf Strato, nach `deploy.cmd`)
- [x] Die Mandanten-Fälle mit zwei festen Tokens (Nutzer A, Nutzer B in „Test B“) per `curl` gegen `/garage/mcp`: fremdes Fahrzeug lesen/schreiben, fremde `garage_id` beim Anlegen, Token nur `garage:read` schreibt, Rolle `lesend` schreibt. Liste mit Ergebnis in `## Report-Back`.
- [x] Alle fünf Zweige der Konfliktregel einmal durchspielen und das Ergebnis von `get_vehicle` danach festhalten.
- [ ] claude.ai-Verbindung aus Phase 3 jetzt bis `list_vehicles` durch — offen: braucht die Anmeldung des Benutzers bei claude.ai (siehe FINDINGS, Phase 5).

### Doku
- [x] ADR-007 „Fahrzeugfakten als Zeilen mit Herkunft“ (E3, E8; verworfen: Spalten + JSON-Herkunft — Konflikt „daneben speichern“ bräuchte ohnehin eine Nebentabelle).
- [x] ADR-008 „Agentenrechte: Scope und Rolle, `create_vehicle`, nur CIMD“ (E4, E5, E6).
- [x] `docs/models.md`, `docs/routes.md`, `docs/code-map.md` (`Mcp/`, `Domain/Vehicle*`), `docs/glossary.md` (Fakt, strittig, abgelöst, falls nicht vorhanden).

## Report-Back

**Ergebnis:** MCP-Schicht aus DISPATCH, vier Tools (`list_vehicles`, `get_vehicle`, `create_vehicle`, `set_vehicle_facts`), Konfliktregel, Klärfälle und Mandantentrennung stehen; deployt (Migration `006_vehicle.sql` angewandt, Rauchtest grün). Geprüft per curl gegen `https://quantum-canvas.de/garage/mcp` mit vier festen Tokens (Nutzer A `read+write`, A nur `read`, Nutzer B in Garage „Test B M4“, Nutzer C mit Rolle `lesend` in Garage A, Scope `read+write`): 62 Prüfungen, alle grün.

**Kopiert aus DISPATCH** (`app/src/`): `Mcp/Protocol`, `ProtocolError`, `Schema`, `Tool`, `ToolResult` (nur Namespace), `Registry` (nimmt `Actor`), `McpController` (ein Dienstsatz, Akteur aus Token), `Resources`/`Prompts` (leer), `Auth/McpAuth` (plus Nutzerstatus). Ohne Bild-Hilfsklasse `ImageBlocks` (kommt mit Meilenstein 3).

**Mandanten-Fälle (alle ✔):**
- B liest Fahrzeug von A (`get_vehicle`) → `not_found`; B schreibt an Fahrzeug von A (`set_vehicle_facts`) → `not_found`, nie `forbidden`; umgekehrt A → Fahrzeug von B dasselbe.
- A legt mit `garage_id` von Garage B an → `not_found`; unbekannte Fahrzeug-ID → gleiche Meldung.
- `list_vehicles` zeigt B nur Garage B, A nur Garage A; Klärfälle von A erscheinen bei B nicht.
- Token nur `garage:read`: `tools/list` zeigt nur `list_vehicles`/`get_vehicle`; `create_vehicle` und `set_vehicle_facts` → HTTP 403, Fehler `-32003`, `WWW-Authenticate: …insufficient_scope`; `get_vehicle` geht.
- Nutzer C (Rolle `lesend`, Scope write): `set_vehicle_facts` und `create_vehicle` (mit und ohne `garage_id`) → `forbidden` „Dieses Konto darf in der Garage nur lesen.“; Lesen geht; nichts geschrieben.
- Ohne Token → 401 mit `resource_metadata`-Adresse; falsches Token → 401 mit `invalid_token`; fremder `Origin` → 403; GET → 405.

**Fünf Zweige der Konfliktregel** (Fahrzeug „Golf 7“, `leistung_kw` und `farbe`): (1) kein Wert → `angelegt`; (2) gleicher Wert, niedrigere oder gleiche Konfidenz → `unveraendert`; (3) gleicher Wert, höhere Konfidenz → `aktualisiert`; (4) anderer Wert, aktueller nur `unsicher` → `aktualisiert` (Grau → Blau); (5) anderer Wert gegen `bestaetigt` → `klaerfall` mit `open_item_id` 1. Danach `get_vehicle`: `leistung_kw` 110 (bestätigt, Quelle `beleg`), `farbe` Blau (wahrscheinlich), `disputed`: 120 mit `open_item_id` 1; `list_vehicles` zeigt `open_items: 1`. Derselbe Widerspruch ein zweites Mal → derselbe Klärfall, keine zweite Zeile.

**Ungültige Werte** (je `invalid_value` mit Feldname und Beispiel, nichts geschrieben): FIN mit 16 Zeichen und mit „O“, Datum `14.03.2016` und `2016-02-30`, Zahl `-5` und `110 kW`, unbekanntes Feld, `source` und `confidence` außerhalb der Liste, HSN mit 5 Zeichen; Mischanfrage (1 gültig, 1 ungültig) schreibt gar nichts; doppeltes Feld und leere `facts` abgewiesen; `create_vehicle` mit ungültigem Fakt legt kein Fahrzeug an.

**Abweichungen:**
- AK „Token ohne `garage:write` → Tool-Fehler `forbidden`“: der Endpunkt antwortet wie DISPATCH mit dem Protokollfehler `insufficient_scope` (403), damit Clients einen erweiterten Scope anfragen können (ADR-008). `forbidden` gibt es für die Rolle `lesend`.
- Zusätzlich zu den fünf Zweigen: derselbe offene Widerspruch wird nicht doppelt angelegt.
- Der Nutzerstatus wird bei jedem MCP-Aufruf geprüft (FINDINGS aus Phase 3), nicht nur bei `goa_`.

**OAuth-Token am Endpunkt:** mit dem CIMD-Dokument von claude.ai als Client per curl: `authorize` → Zustimmung (per API statt Browser) → Token → `tools/list` (4 Tools), `list_vehicles`, `set_vehicle_facts` (Akteur `oauth:Claude`), nur `garage:read` → `create_vehicle` 403 `insufficient_scope`, nach `revoke` → 401. 9 Prüfungen grün.

**Nicht geprüft:**
- `audit_log`-Einträge mit `before_json`/`after_json`: im Code je Schreibung vorhanden, aber ohne Datenbankzugriff nicht eingesehen (kein Lese-Endpunkt).
- Gesperrter Nutzer mit festem Token: kein Weg, einen Nutzer zu sperren.
- Echte Verbindung aus claude.ai und ChatGPT, die Zustimmungsseite im Browser und der `claude`-Client mit `claude mcp add`: brauchen Anmeldung/Client des Benutzers; der Ablauf ist per curl mit denselben Nachrichten belegt.
- Die Testkonten `test-m4-a@example.com`, `test-m4-b@example.com`, `test-m4-c@example.com` (Garagen 12 „Test A M4“ und 13 „Test B M4“) mit je einem festen Token stehen auf Strato; sperren geht erst mit Datenbankzugriff.
