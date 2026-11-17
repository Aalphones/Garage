# Phase 6 — Akte, Resources, Prompts

Rating: **standard** (liest nur, setzt Vorhandenes zusammen). Status: **complete**.

## Kontext — vorher lesen

- [`README.md`](README.md) → Kontrakt (`get_dossier`), finale AK.
- Phasen 1–5 → Report-Back (Lese-Methoden der Services: `VehicleService::get`, `EventService::list/get`, `DueService::dueItems`, `MeasurementService`, `TireService`, `PartService`, `DamageService`, `NoteService::listCurrent`, `OpenItemService::list`).
- `docs/design/konzept-garage.md` → „MCP-Oberfläche“ (Resources, Prompts, Server-Anweisungen), Kurzfassung (Akte als Markdown für Agenten und Verkauf).
- Code: `app/src/Mcp/Resources.php`, `Prompts.php`, `McpController.php` (`readResource`, `getPrompt`, `readOnlyList`), `app/src/Mcp/ToolResult.php`, `app/mcp/instructions.md`.
- Fehlerklassen: MCP — Server nach altem Schema: an `Protocol`/`McpController`-Dispatch nichts ändern außer der Übergabe von Diensten und Akteur an `Resources`/`Prompts`.

## Abnahmekriterien

- [x] `get_dossier` für das Testfahrzeug aus Phase 1–5 liefert Markdown mit allen Abschnitten in der Reihenfolge unten; leere Abschnitte erscheinen mit einem Satz „Noch nichts erfasst.“; jeder Wert trägt seine Herkunft in Klammern („(Beleg, bestätigt)“); geschätzte Werte tragen „≈“.
- [x] `resources/list` nennt `garage://fahrzeuge`, `garage://leitfaden/erfassung`, `garage://leitfaden/herkunft`; `resources/templates/list` nennt `garage://fahrzeug/{id}/akte` und `garage://fahrzeug/{id}/faelligkeiten`.
- [x] `resources/read` `garage://fahrzeug/<id>/akte` liefert denselben Text wie `get_dossier` (`mimeType` `text/markdown`); mit fremder ID → Fehler `INVALID_PARAMS` „unbekannt“ (wie unbekannte Adresse, nie ein Hinweis auf Existenz).
- [x] `prompts/list` nennt `beleg_erfassen` und `was_steht_an`; `prompts/get` liefert je eine Nachricht mit Arbeitsanweisung (unten).
- [x] `initialize`/`server/discover` liefern die neuen Server-Anweisungen.
- [x] Mandanten: `get_dossier` und Resources von B auf Fahrzeug A → `not_found` bzw. „unbekannt“.

## Akte — Aufbau (`app/src/Domain/DossierService.php`)

`render(int $vehicleId, Actor): string` — Markdown, Überschriften fest:

1. `# <label>` und eine Zeile: Kennzeichen (aktuelle Zulassung: `valid_to` leer oder ≥ heute, neueste), Status, „Stand: <heute TT.MM.JJJJ>“.
2. `## Stammdaten` — Tabelle Feld | Wert | Herkunft (alle `facts`, Feldnamen lesbar über eine Konstante `LABELS` in `DossierService`, z. B. `leistung_kw` → „Leistung (kW)“); darunter „Strittig:“-Liste aus `disputed`.
3. `## Zulassung` — Tabelle Von | Bis | Kennzeichen | Halter; Zeile „Halterzahl: <holder_count>“.
4. `## Ausstattung` — zwei Listen „Serie“ und „Sonder“ (Code, Name, Neupreis).
5. `## Spezifikationen` — je Bereich `### <bereich>` und Liste `Schlüssel: Wert (Herkunft)`.
6. `## Kilometerstand` — letzter gültiger Stand mit Datum, Prognose „heute ≈ <km> km (≈ <per_day> km/Tag, aus <von> bis <bis>)“.
7. `## Fälligkeiten` — Tabelle Aufgabe | fällig | Status | Rechenweg aus `DueService::dueItems($vehicleId, 120, …)`; Status als Wort, `rule_uncertain` als „Regel unbelegt“.
8. `## Wartungsplan` — Tabelle Aufgabe | Regel | Modus | Herkunft.
9. `## Scheckheft` — je Ereignis (neueste zuerst) `### <TT.MM.JJJJ> · <Art> · <Titel>`, Zeile Werkstatt, km, Rechnung, Brutto, Herkunft; Positionen als Tabelle Typ | Beschreibung | Teilenummer | Menge | Netto.
10. `## Messwerte` — je Größe und Position der jüngste Wert mit Datum, darunter Verlauf als Liste.
11. `## Reifen` — je Satz eine Zeile, montierter Satz markiert, DOT-Alter.
12. `## Teile` — je Bauteil, verbaute zuerst.
13. `## Schäden` und `## Rückrufe`.
14. `## Notizen` — je Thema `### <topic>` mit dem Text der neuesten Revision (Überschriften im Notiztext um zwei Ebenen tiefer setzen: `#` → `###`).
15. `## Offene Klärfälle` — Liste mit ID und Text.

Datumsformat `TT.MM.JJJJ`, Zahlen mit Tausenderpunkt, Euro mit Komma und „€“. Pipe-Zeichen und Zeilenumbrüche in Tabellenzellen escapen (`|` → `\|`, Umbruch → Leerzeichen).

## Resources und Prompts

- `Resources` bekommt im Konstruktor `array $services, Actor $actor`; `McpController::readOnlyList`/`readResource` übergeben sie (`new Resources($services, $actor)`). URIs per Regex `^garage://fahrzeug/([1-9][0-9]{0,9})/(akte|faelligkeiten)$`. `garage://fahrzeuge` → JSON von `VehicleService::list` (`application/json`); `…/faelligkeiten` → JSON von `DueService::dueItems($id, 36, …)`; Leitfäden → Dateien `app/mcp/leitfaden-erfassung.md` und `app/mcp/leitfaden-herkunft.md` (`text/markdown`). `DomainError('not_found')` aus den Services wird zu `unknown($uri)`.
- `app/mcp/leitfaden-erfassung.md`: wie man eine Rechnung zerlegt — Kopf (Datum, km, Werkstatt, Rechnungsnummer, Summen), Positionen (Typ, Beschreibung, Teilenummer, Menge, Netto), `task_key` für wiederkehrende Arbeiten (Liste üblicher Schlüssel: `oelwechsel`, `oelfilter`, `luftfilter`, `innenraumfilter`, `zuendkerzen`, `bremsfluessigkeit`, `kuehlmittel`, `zahnriemen`, `dsg_oel`, `hu`, `inspektion`), erst `validate_service_event`, dann `record_service_event`; unleserlicher Wert → `open_item`, nie raten.
- `app/mcp/leitfaden-herkunft.md`: wann welche Konfidenz — Beleg/Fahrzeugschein → `bestaetigt`; Werkstatt-Aussage → `bestaetigt` mit `source: werkstatt`; Webseite → höchstens `wahrscheinlich` mit `source_url`; eigene Ableitung → `unsicher`.
- `Prompts` bekommt eine feste Liste: `beleg_erfassen` (Argument `fahrzeug`, optional) → Nachricht „Erfasse den Beleg aus dem Chat für <fahrzeug oder ‚das passende Fahrzeug (list_vehicles)‘>. Lies garage://leitfaden/erfassung, rufe validate_service_event, zeige mir das Ergebnis samt Klärfällen und rufe nach meinem Okay record_service_event.“; `was_steht_an` (Argument `fahrzeug`, optional) → „Rufe get_due_items <für fahrzeug> und fasse zusammen: was ist überfällig, was kommt in 3 Monaten, welche Regeln sind unbelegt. Schätze Kosten nur, wenn das Scheckheft vergleichbare Ereignisse hat (list_events), und nenne die Quelle.“ Argument-Schema wie DISPATCH (`arguments: [{name, description, required: false}]`).

## Checkliste

- [x] `ToolResult::markdown(string $text): self` — ein Textblock mit dem Markdown, kein JSON.
- [x] `app/src/Domain/DossierService.php` wie oben; `app/src/Mcp/Tools/DossierTools.php`: `get_dossier` (read, Beschreibung: „Die ganze Akte als Markdown, Aufbau fest; für Export, Verkauf und als Kontext.“).
- [x] `Resources`, `Prompts`, `McpController` (Übergabe), zwei Leitfaden-Dateien.
- [x] `app/mcp/instructions.md` neu fassen: Einleitung um die neuen Bereiche erweitern; Regeln 1–5 behalten, Regel 3 ergänzen „Vor record_service_event list_events lesen; im Zweifel validate_service_event“, neue Regel 6 „Kilometerstände und Messwerte immer mit Datum; nie raten — Unleserliches wird open_item“, Regel 7 „Fälligkeiten nicht selbst rechnen, sondern get_due_items lesen“.
- [x] `McpController::SERVER_VERSION` auf `0.2.0`.

### Prüfen (auf Strato, nach `deploy.cmd`)

- [x] AK per `curl`; den Markdown-Text von `get_dossier` einmal vollständig lesen und gegen die Daten aus Phase 1–5 abgleichen.

### Doku

- [x] `docs/routes.md` (Tool, Resources, Prompts), `docs/code-map.md` (`Dossier*`, Leitfäden in `app/mcp/`).

## Report-Back

**Ergebnis:** Phase 6 ist umgesetzt und auf Strato deployt (kein Migrationsschritt). `get_dossier` liefert die Akte als Markdown in 15 Abschnitten (`DossierService` + `DossierFormat`); `Resources` (Fahrzeugliste, Akte, Fälligkeiten, zwei Leitfäden) und `Prompts` (`beleg_erfassen`, `was_steht_an`) sind gefüllt; `instructions.md` neu; Server-Version 0.2.0. Protokollschicht unverändert bis auf die Übergabe von Diensten und Akteur an `Resources` in `McpController`.

**Geprüft (curl/MCP gegen Strato, 43 Prüfungen in einem Skript, Testkonten A Inhaber, B fremd, dazu ein Token nur mit `garage:write`):** Initialize und `server/discover` mit neuen Anweisungen und Version; `tools/list` nennt `get_dossier` (read-only); Akte eines Testfahrzeugs (Stammdaten mit Klärfall, zwei Zulassungen, Ausstattung, Spezifikationen, Kilometerprognose, zwei Rechnungen mit Positionen, zwei Regeln, Messwertreihe, zwei Reifensätze, Teile, Schaden, Rückruf, Notiz, Klärfall) einmal vollständig gelesen und gegen die Eingabedaten abgeglichen; Fahrzeug ohne Daten ergibt 15 Abschnitte „Noch nichts erfasst.“; Pipe im Halter und in einer Positionsbeschreibung maskiert; Notiz-Überschriften `#` → `###`, Codeblock unberührt; Resource `…/akte` ist Zeichen für Zeichen derselbe Text wie `get_dossier`; `…/faelligkeiten` hat dieselben Aufgaben wie `get_due_items`; fremde ID, nicht vorhandene ID und ungültige Adressen (`0`, führende Null, Zusatzpfad, Query) antworten gleich mit `-32602` „unbekannt“; Nutzer B bekommt für `get_dossier` `not_found`; ein Token ohne `garage:read` sieht `get_dossier` nicht, `resources/list` → 403 `-32003`; Prompts mit und ohne Argument, unbekannter Prompt und Argument mit Zeilenumbruch → `-32602`.

**Abweichungen von der Phasen-Datei:**
- Tabellen Zulassung, Wartungsplan, Fälligkeiten tragen eine zusätzliche Spalte „Herkunft“ bzw. „Rechenweg“; Zulassung und Ausstattung zeigen Herkunft je Zeile (AK „Herkunft an jedem Wert“).
- Neu: `OdometerRepository::latestValid`/`OdometerService::latestValid` — `validReadings` kennt keine Herkunft, die Akte zeigt sie beim letzten Stand.
- `Prompts` hat keinen Dienst-/Akteur-Parameter (nicht nötig: reine Texte); nur `Resources` bekommt Dienste und Akteur. Das Prompt-Argument `fahrzeug` ist ein einzeiliger Text bis 80 Zeichen, sonst `-32602`.
- Der Dienst `dossier` steht im Service-Array von `McpController::services` (neben den anderen).
- Die Regeln der Server-Anweisungen sind neu durchnummeriert (neun statt acht): Spec-Regel 6 (Kilometer/Messwerte mit Datum) und 7 (Fälligkeiten nicht selbst rechnen) ersetzen die bisherigen 6–8, deren Inhalt (Intervalle eintragen, Reifen/Teile/Schäden, Notizen) in 7–9 aufgegangen ist.
- Strittige Fakten in der Akte zeigen keine Adresse der Quelle (`VehicleService::get` liefert sie für `disputed` nicht); sie steht beim Klärfall.

**🟡 Nicht geprüft / Rest-Risiko:** Der Leitfaden-Satz „Nutzer-Aussage → bestätigt, wenn sicher“ ist eine Annahme (Spezifikation nennt nur Beleg, Werkstatt, Webseite, Ableitung). Echte Clients (claude.ai, ChatGPT, `claude mcp add`) haben `resources/read` und `prompts/get` noch nicht gegen die Akte ausgeführt. Der Rechenweg in der Fälligkeitstabelle ist der unveränderte Text von `DueCalculator` und kann lang werden. Audit-Einträge entstehen hier nicht (nur Lesen).

Testkonten dieser Phase: `test-m2-p6a|p6b-<Zeitstempel>@example.com` (vier Läufe; Garagen „Test A P6 …“, „Test B P6 …“, Fahrzeuge „Golf P6“, „Leer P6“, Token `p6-a`, `p6-a-w`, `p6-b`), passen auf `test-%@example.com`.
