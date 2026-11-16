# Phase 4 — MCP und Fahrzeug-Tools

Rating: **heikel** (Mandantentrennung, Konfliktregel der Fakten).

## Kontext — vorher lesen

- [`README.md`](README.md) → E3, E4, E5, E8, Kontrakt (Akteur, Rollen, MCP-Tools).
- `docs/design/konzept-garage.md` → „Datenmodell“ (Herkunft als Pflichtfeld, Regeln im Code) und „MCP-Oberfläche“ (Scopes, Server-Anweisungen 1–5).
- `AGENTS.md` → Critical Rules 3–5.
- DISPATCH ADR-016, ADR-017, ADR-018 (`C:\Users\sasch\develop\Blog\docs\decisions\`).
- DISPATCH-Vorlagen (`C:\Users\sasch\develop\Blog\app\`): `src/Mcp/Protocol.php`, `ProtocolError.php`, `Registry.php`, `Schema.php`, `Tool.php`, `ToolResult.php`, `McpController.php`, `Resources.php`, `Prompts.php`, `src/Auth/McpAuth.php`, ein Tool-Beispiel `src/Mcp/Tools/CategoryTools.php`, `mcp/instructions.md`.
- Fehlerklasse MCP (`~/.claude/knowledge/protokolle/mcp.md` → Fehlerklassen): Server nach altem Schema — die kopierte Schicht muss `server/discover` (2026-07-28) **und** `initialize` (ältere Clients) können; nichts davon wegkürzen.

## Abnahmekriterien

- [ ] `POST /garage/mcp` ohne Token → 401 mit `WWW-Authenticate: Bearer … resource_metadata="https://quantum-canvas.de/.well-known/oauth-protected-resource/garage/mcp"`.
- [ ] `tools/list` zeigt je Token nur Tools seiner Scopes: nur `garage:read` → `list_vehicles`, `get_vehicle`.
- [ ] `create_vehicle` mit Rolle `lesend` oder ohne `garage:write` → Tool-Fehler `forbidden` mit Satz, was fehlt. Mit einer fremden `garage_id` → `not_found`.
- [ ] `get_vehicle` / `set_vehicle_facts` mit der ID eines Fahrzeugs aus Garage „Test B“ durch Nutzer A → `not_found` (nie `forbidden`).
- [ ] Konfliktregel `set_vehicle_facts`, je Feld:
  - kein aktueller Wert → neue Zeile `aktuell`, Ergebnis `angelegt`;
  - gleicher Wert, gleiche oder niedrigere Konfidenz → nichts schreiben, `unveraendert`;
  - gleicher Wert, höhere Konfidenz → alte Zeile `abgeloest`, neue `aktuell`, `aktualisiert`;
  - anderer Wert, aktueller ist **nicht** `bestaetigt` → alte `abgeloest`, neue `aktuell`, `aktualisiert`;
  - anderer Wert, aktueller ist `bestaetigt` → neue Zeile `strittig` + Klärfall (`open_items`, Art `konflikt`, Text nennt Feld, beide Werte und Quellen), `klaerfall` mit `open_item_id`.
- [ ] Jede Schreibung erzeugt einen `audit_log`-Eintrag mit `before_json`/`after_json`.
- [ ] Ungültige Werte (FIN nicht 17 Zeichen aus `A–H J–N P R–Z 0–9`, Datum nicht `YYYY-MM-DD`, Zahl nicht ganzzahlig ≥ 0, unbekanntes Feld, `source` oder `confidence` außerhalb der Liste) → Tool-Fehler `invalid_value` mit Feldname und Beispiel; keine Zeile geschrieben (ganze Anfrage in einer Transaktion).
- [ ] Claude Code verbindet sich mit `gmcp_`-Token (`claude mcp add --transport http garage https://quantum-canvas.de/garage/mcp --header "Authorization: Bearer <token>"`) und ruft `list_vehicles` auf.

## Datenmodell — `app/migrations/006_vehicle.sql`

- `vehicles`: `id` PK, `garage_id` FK→garages NOT NULL, `label` VARCHAR(80) NOT NULL (Anzeigename, z. B. „Golf 7“), `status` ENUM('aktiv','verkauft','stillgelegt','geloescht') NOT NULL DEFAULT 'aktiv', `status_reason` VARCHAR(300) NULL, `created_by` VARCHAR(160) NOT NULL, `created_at`, `updated_at`; INDEX (`garage_id`, `status`).
- `vehicle_facts`: `id` PK, `garage_id` NOT NULL, `vehicle_id` FK→vehicles NOT NULL, `field` VARCHAR(40) NOT NULL, `value` VARCHAR(500) NOT NULL (immer als Text, Typ prüft der Service), `source` ENUM('beleg','nutzer','recherche','agent','werkstatt') NOT NULL, `source_url` VARCHAR(500) NULL, `source_document_id` INT UNSIGNED NULL (ohne FK bis Meilenstein 3), `confidence` ENUM('bestaetigt','wahrscheinlich','unsicher') NOT NULL, `status` ENUM('aktuell','abgeloest','strittig') NOT NULL, `actor` VARCHAR(160) NOT NULL, `user_id` INT UNSIGNED NULL, `created_at`; INDEX (`vehicle_id`, `field`, `status`), INDEX (`field`, `value`(40)).
- `open_items`: `id` PK, `garage_id` NOT NULL, `vehicle_id` NULL FK→vehicles, `kind` ENUM('konflikt','nachfragen','beobachten') NOT NULL, `text` TEXT NOT NULL, `ref_table` VARCHAR(40) NULL, `ref_id` INT UNSIGNED NULL, `status` ENUM('offen','geloest','verworfen') NOT NULL DEFAULT 'offen', `resolution` TEXT NULL, `resolved_by` VARCHAR(160) NULL, `resolved_at` NULL, `created_by` VARCHAR(160) NOT NULL, `created_at`; INDEX (`garage_id`, `status`).

Rangfolge der Konfidenz: `bestaetigt` > `wahrscheinlich` > `unsicher`.

### Erlaubte Felder (Konstante `VehicleService::FIELDS`, Feld → Typ)

`fin` fin · `hsn` text(4) · `tsn` text(3–10) · `marke` text · `modell` text · `variante` text · `motorcode` text · `getriebecode` text · `erstzulassung` date · `farbe` text · `farbcode` text · `kraftstoff` text · `leistung_kw` int · `hubraum_ccm` int · `schadstoffklasse` text · `co2_g_km` int · `leergewicht_kg` int · `zul_gesamtgewicht_kg` int · `listenneupreis_eur` int · `schluesselanzahl` int · `ablageort_papiere` text. `text` = 1–200 Zeichen nach `trim`.

## Checkliste

### MCP-Schicht kopieren
- [ ] `app/src/Mcp/Protocol.php`, `ProtocolError.php`, `Schema.php`, `Tool.php`, `ToolResult.php` mit Namespace; `Tool` prüft Scopes gegen `Garage\Auth\Scopes`.
- [ ] `app/src/Mcp/Registry.php`: `call(string $name, array $args, Actor $actor)` — nimmt den fertigen Akteur statt `$auth`; Scope-Prüfung über `$actor->scopes`.
- [ ] `app/src/Mcp/Resources.php`, `Prompts.php`: Klassen behalten, Kataloge leer (`resources/list` und `prompts/list` liefern leere Listen; `resources/read` und `prompts/get` → `INVALID_PARAMS` „unbekannt“).
- [ ] `app/src/Auth/McpAuth.php`: Präfixe `gmcp_` (über `TokenService::authenticate`) und `goa_` (über `TokenIssuer::authenticate`); Rückgabe enthält `user_id`.
- [ ] `app/src/Mcp/McpController.php`: `SERVER_NAME` `garage`; `services()` nur `tokens`, `vehicles`, `pdo`, `config`; nach der Anmeldung `Actor::forUser($auth['user_id'], $auth['kind'], ($auth['kind'] === 'oauth' ? 'oauth:' : 'mcp:') . $auth['name'], $auth['scopes'], $pdo)`; `registry()` mit einer Gruppe „Fahrzeuge“; Scope-Texte `garage:read`.
- [ ] `app/mcp/instructions.md`: die fünf Regeln aus dem Konzept („MCP-Oberfläche“ → Server-Anweisungen), ergänzt um „Vor `create_vehicle` mit `list_vehicles` prüfen, ob es das Fahrzeug schon gibt.“

### Fachlogik
- [ ] `app/src/Domain/VehicleRepository.php`: jede Methode nimmt `Actor` und hängt `garage_id IN (<actor->garageIds()>)` an; leere Liste → leeres Ergebnis ohne Abfrage. Methoden: `listForActor`, `find(int $id, Actor)`, `insert`, `currentFacts(vehicleId)`, `disputedFacts(vehicleId)`, `insertFact`, `supersede(factId)`, `countOpenItems(vehicleIds)`.
- [ ] `app/src/Domain/OpenItemRepository.php`: `insert(...)`: int.
- [ ] `app/src/Domain/VehicleService.php`: `list(Actor)`, `get(int, Actor)`, `create(string $label, ?int $garageId, array $facts, Actor)`, `setFacts(int $vehicleId, array $facts, Actor)` — Validierung, Konfliktregel aus AK, alles in `Transaction::run`, Audit je Feld. Rechteprüfung: Fahrzeug nicht gefunden oder fremde Garage → `DomainError('not_found')`; gefunden, aber `!canWrite` → `DomainError('forbidden', 'Dieses Konto darf in der Garage nur lesen.' bzw. 'Dem Zugang fehlt garage:write.')`.
- [ ] `app/src/Mcp/Tools/VehicleTools.php`: vier Tools nach README-Kontrakt; Beschreibungen nennen Beispiel-Eingaben; `readOnlyHint`/`destructiveHint`-Annotationen wie DISPATCH (keins ist destruktiv).
- [ ] Route `POST /garage/mcp`.

### Prüfen (auf Strato, nach `deploy.cmd`)
- [ ] Die Mandanten-Fälle mit zwei festen Tokens (Nutzer A, Nutzer B in „Test B“) per `curl` gegen `/garage/mcp`: fremdes Fahrzeug lesen/schreiben, fremde `garage_id` beim Anlegen, Token nur `garage:read` schreibt, Rolle `lesend` schreibt. Liste mit Ergebnis in `## Report-Back`.
- [ ] Alle fünf Zweige der Konfliktregel einmal durchspielen und das Ergebnis von `get_vehicle` danach festhalten.
- [ ] claude.ai-Verbindung aus Phase 3 jetzt bis `list_vehicles` durch.

### Doku
- [ ] ADR-007 „Fahrzeugfakten als Zeilen mit Herkunft“ (E3, E8; verworfen: Spalten + JSON-Herkunft — Konflikt „daneben speichern“ bräuchte ohnehin eine Nebentabelle).
- [ ] ADR-008 „Agentenrechte: Scope und Rolle, `create_vehicle`, nur CIMD“ (E4, E5, E6).
- [ ] `docs/models.md`, `docs/routes.md`, `docs/code-map.md` (`Mcp/`, `Domain/Vehicle*`), `docs/glossary.md` (Fakt, strittig, abgelöst, falls nicht vorhanden).

## Report-Back
