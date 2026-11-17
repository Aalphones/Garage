# Phase 5 — Klärfälle, Verlauf, Suche

Rating: **heikel** (Lösen ändert Daten, nur Menschen dürfen es; neue Web-API und Seite). Status: **pending**.

## Kontext — vorher lesen

- [`README.md`](README.md) → E3, E4, E5, E10, Kontrakt (Klärfall-Vorschlag, Routen, Tools `list_open_items`, `open_item`, `list_changes`, `search`).
- Phasen 1–4 → Report-Back (welche `ref_table`-Werte Klärfälle tatsächlich tragen).
- `docs/decisions/008-agentenrechte-scope-und-rolle.md` (kein Agenten-Recht zum Lösen).
- `docs/conventions/frontend.md` (CSP, kein Inline-Skript, Sichtbarkeit über `hidden`), `docs/conventions/dod.md` (Oberfläche: 360 px, hell/dunkel, leere Zustände).
- Code-Vorbilder: `app/src/Api/OAuthController.php` (JWT-Route mit `{slug}`), `app/src/Api/TokenController.php` (DELETE mit 404 für fremde), `web/js/grant.js` (Liste + Knopf + `window.confirm`), `web/js/account.js`, `web/index.html`, `web/css/base.css`, `web/js/dom.js`.
- Fehlerklassen: keine einschlägigen im Vault.

## Abnahmekriterien

- [ ] `list_open_items` liefert offene Klärfälle der eigenen Garagen mit `id, vehicle_id, vehicle_label, kind, text, created_by, created_at, ref_table, ref_id, proposal`; `status: alle` liefert auch gelöste mit `resolution`, `resolved_by`, `resolved_at`.
- [ ] `open_item` (Agent) legt `nachfragen`/`beobachten` an; gleicher offener Text am selben Fahrzeug → `unveraendert` mit derselben ID; `kind: konflikt` → `invalid_value` („Konflikte entstehen nur aus Widersprüchen beim Schreiben“).
- [ ] Kein Agent kann lösen: es gibt kein Lösen-Tool; ein `gmcp_`- oder `goa_`-Token an `POST /garage/api/open-items/{id}/resolve` → 401.
- [ ] Je `ref_table` aus dem README-Kontrakt einmal „neu“ und einmal „bestehend“ über die API durchgespielt; danach zeigen `get_vehicle`/`get_event`/`get_due_items`/Lese-Tools den erwarteten Wert; je Lösung ein Audit-Eintrag `open_item.resolve` mit vorher/nachher.
- [ ] Zweites Lösen desselben Klärfalls → 409 `already_resolved`; fremder Klärfall → 404; Rolle `lesend` → 403 `forbidden`; `decision` passt nicht zur Art (`neu` bei `beobachten`) → 422 `invalid_value`.
- [ ] Platzhalter-Seite: nach der Anmeldung erscheint im Konto der Abschnitt „Klärfälle“; je Konflikt beide Werte mit Herkunft und drei Knöpfe; nach Klick und Bestätigung verschwindet der Eintrag; leer → „Keine offenen Klärfälle. Agenten legen sie an, wenn sich Angaben widersprechen.“ Bei 360 px kein waagerechtes Scrollen, hell und dunkel lesbar, Konsole ohne Fehler und ohne CSP-Meldung.
- [ ] `list_changes` liefert die letzten Einträge des Audit-Logs der eigenen Garagen (mit `vehicle_id`-Filter), nie fremde.
- [ ] `search` findet „Ölfilter“ in Positionen, Teilen und Notizen eines Fahrzeugs; Eingabe unter 3 Zeichen → `invalid_value`; `%` und `_` in der Suche werden wörtlich gesucht.

## Kontrakt dieser Phase

### Web-API

`GET /garage/api/open-items` → `{open_items: [{id, garage_id, vehicle_id, vehicle_label, kind, text, created_by, created_at, can_resolve, decisions: [...], current: {value, source, source_url, confidence, actor} | null, proposed: {value, source, source_url, confidence} | null}]}` — nur `offen`, neueste zuerst. `current`/`proposed` je `ref_table`:

| `ref_table` | `current` | `proposed` |
|---|---|---|
| `vehicle_facts` | aktuelle Zeile desselben Feldes | die `strittig`-Zeile `ref_id` |
| Listen-Tabelle | Spalte `proposal.field` der Zeile `ref_id` mit Herkunft der Zeile | `proposal` |
| `events` (Positionen) | aktive Positionen als Text (eine Zeile je Position: „Arbeit · Ölwechsel · 89,00 €“) | `proposal.items` ebenso |
| `odometer_readings` | Stände `proposal.conflicts_with` als „26.910 km am 02.03.2026“ | der Stand `ref_id` |
| `task_rules` | aktuelle Regel als „15.000 km / 24 Monate, fest“ | `proposal` ebenso |
| ohne `ref_table` | `null` | `proposal.suggestion` als `value`, sonst `null` |

`decisions`: Konflikt → `["bestehend", "neu", "verwerfen"]`; `nachfragen`/`beobachten` → `["erledigt", "verwerfen"]`. `can_resolve` = Rolle `inhaber`/`mitglied`.

`POST /garage/api/open-items/{id}/resolve` Body `{decision, note?}` (`note` 0–500) → 200 `{open_item_id, status, decision}`. Fehler: 404 `not_found` (fremd/unbekannt), 403 `forbidden` (Rolle `lesend`), 409 `already_resolved`, 422 `invalid_value`.

### Lösen (`OpenItemService::resolve`)

In einer Transaktion: Klärfall `FOR UPDATE` (mit `garage_id IN (…)`), `status` muss `offen` sein; Akteur muss `via = web` haben (sonst `DomainError('forbidden', 'Klärfälle löst nur ein Mensch in der Oberfläche.')`) und `canWrite(garage_id)`. Wirkung je `ref_table` nach README-Tabelle „Klärfall-Vorschlag“; bei Listen-Tabellen setzt „neu“ die Spalte `proposal.field` (Name gegen `RecordMerger::TABLES[$table]['columns']` geprüft, sonst `LogicException`) und `source`, `source_url`, `confidence` der Zeile auf die des Vorschlags, `updated_at`. Danach `status` = `geloest` (bestehend, neu, erledigt) bzw. `verworfen`; `resolution` = `<decision>` + optional `: <note>`; `resolved_by` = Akteur-Name; `resolved_at = UTC_TIMESTAMP()`. Audit `open_item.resolve` (before: Klärfall + betroffene Werte, after: neue Werte).

## Checkliste

### Fachlogik

- [ ] `OpenItemRepository`: `list(?int $vehicleId, string $status, Actor)`, `findForUpdate(int $id, Actor)`, `resolve(int $id, string $status, string $resolution, Actor)`, `findOpenByText(int $vehicleId, string $kind, string $text, Actor)`.
- [ ] `app/src/Domain/OpenItemService.php`: `list(...)` (Tool-Form), `listForHumans(Actor)` (API-Form mit `current`/`proposed`/`decisions`, siehe Kontrakt), `create(int $vehicleId, string $kind, string $text, ?string $suggestion, Actor)` (`text` 1–1000, `suggestion` 1–1000, Proposal `{suggestion}`), `resolve(int $id, string $decision, ?string $note, Actor)`.
- [ ] Wirkungen „neu“ als private Methoden je `ref_table`, die die Repositories der Phasen 1–4 nutzen (`VehicleRepository::supersede` + neue `VehicleRepository::setFactStatus(int $factId, string $status, Actor)`, `RecordMerger::applyProposal(string $table, int $rowId, array $proposal, Actor)` (neu, gleiche Prüfungen wie `merge`), `EventService::replaceItems`, `OdometerRepository::setStatus`, `TaskRepository::insertRule`/`supersedeRule`).
- [ ] `app/src/Domain/ChangeService.php`: `list(?int $vehicleId, int $limit, Actor)` — `audit_log` mit `garage_id IN (…)`, optional `vehicle_id = ?`, `ORDER BY id DESC LIMIT ?`; je Eintrag `{id, at, actor, action, target, vehicle_id, before, after}` (JSON dekodiert).
- [ ] `app/src/Domain/SearchService.php`: `search(string $query, ?int $vehicleId, Actor)` — `query` trimmen, 3–100 Zeichen; `%`, `_`, `\` escapen, `LIKE ? ESCAPE '\\'`; je Quelle höchstens 10 Treffer, zusammen höchstens 30: `events` (`title`, `invoice_number`), `event_items` (`description`, `part_number`), `parts` (`component`, `oem_number`, `article_number`), `notes` + neueste `note_revisions` (`topic`, `body`), `open_items` (`text`); immer `garage_id IN (…)` und `status` aktiv/offen. Treffer `{type: event|event_item|part|note|open_item, id, vehicle_id, title, snippet}` (`snippet` = bis 160 Zeichen um den Fund).

### Tools und API

- [ ] `app/src/Mcp/Tools/OpenItemTools.php`: `list_open_items` (read), `open_item` (write).
- [ ] `app/src/Mcp/Tools/ChangeTools.php`: `list_changes` (read); `app/src/Mcp/Tools/SearchTools.php`: `search` (read).
- [ ] `app/src/Api/OpenItemController.php`: `list(Request)`, `resolve(Request, int $id)` nach Muster `OAuthController` (`ApiAuth::user`, `DomainError` → `ApiErrors`); neuer Fehlercode `already_resolved` → 409 in `ApiErrors`.
- [ ] `app/index.php`: Routen `GET /garage/api/open-items`, `POST /garage/api/open-items/{id}/resolve`.
- [ ] `McpController`: Dienste `openItems`, `changes`, `search`; Gruppe „Klärfälle und Verlauf“.

### Platzhalter-Seite (funktional, Gestaltung in M4)

- [ ] `web/index.html`: im Abschnitt `#account` **vor** „Garagen“ ein Block `<h3>Klärfälle</h3>`, Hinweis `<p class="hint">` „Hier entscheidest du, wenn zwei Angaben sich widersprechen. ‚Bisheriger Wert gilt‘ behält den gespeicherten Wert, ‚Neuer Wert gilt‘ übernimmt den gemeldeten, ‚Verwerfen‘ schließt den Klärfall ohne Änderung.“, `<p id="open-item-error" class="error" role="alert" tabindex="-1" hidden>`, `<ul id="open-item-list" class="plain-list">`, `<p id="open-item-empty" hidden>` mit dem Leertext aus den AK.
- [ ] `web/js/openitem.js`: `export function setupOpenItems()` nach Muster `grant.js` (`load`, `clear`); je Eintrag `<li>`: `<strong>` Fahrzeug, `<small>` Art + Datum, `<p>` Text, bei `current`/`proposed` zwei Zeilen „Bisher: … (Herkunft, Konfidenz)“ / „Neu: …“, dann je Entscheidung ein `<button type="button">` mit Beschriftung `bestehend` → „Bisheriger Wert gilt“, `neu` → „Neuer Wert gilt“, `verwerfen` → „Verwerfen“, `erledigt` → „Erledigt“, `aria-label` mit Fahrzeug; vor dem Senden `window.confirm('…')` mit der Wirkung im Klartext; ohne `can_resolve` keine Knöpfe, stattdessen `<small>` „Nur lesend — lösen kann ein Inhaber oder Mitglied.“
- [ ] `web/js/account.js`: `setupOpenItems()` einbinden, in `showAccount` mitladen, beim Abmelden `clear()`.
- [ ] `web/css/base.css`: nur falls nötig eine Regel für Knopfreihen (umbrechend, `gap`), keine neuen Farben.

### Prüfen (auf Strato, nach `deploy.cmd`)

- [ ] API-Fälle per `curl` (Login → JWT) für alle AK; Lösungen je `ref_table`.
- [ ] Seite im Browser mit Testkonto A (Inhaber) und C (lesend), 360 px über die Gerätesimulation, hell und dunkel; Konsole prüfen. Was der Umsetzer nicht im Browser prüfen kann, ausdrücklich im Report-Back nennen.

### Doku

- [ ] ADR-010 „Klärfall mit Vorschlag, Lösen nur im Browser“ (E3, E4, E5; verworfen: Lösen per Tool mit Bestätigungs-Prompt — widerspricht ADR-008).
- [ ] `docs/routes.md` (Routen, Tools), `docs/code-map.md` (`OpenItem*`, `Change*`, `Search*`, `web/js/openitem.js`), `docs/glossary.md` (Vorschlag, Entscheidung).

## Report-Back
