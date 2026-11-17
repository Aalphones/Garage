# 010 — Klärfall mit Vorschlag, Lösen nur im Browser

**Status:** Akzeptiert (2026-11-17)

## Kontext

Ein bestätigter Wert wird nie überschrieben (ADR-007, ADR-009). Widerspricht eine neue Angabe, entsteht ein Klärfall. Damit ein Mensch den neuen Wert übernehmen kann, ohne ihn neu zu erfassen, muss der Klärfall ihn tragen. Außerdem entscheiden Agenten nicht über die Daten, die sie selbst melden (ADR-008): Lösen ist eine menschliche Handlung.

## Entscheidung

- **Der Klärfall trägt den Vorschlag** in `open_items.proposal_json`. Form je `ref_table`: `vehicle_facts` ohne Vorschlag (`ref_id` ist die strittige Zeile); `odometer_readings` `{conflicts_with:[ids]}`; `task_rules` die Regelfelder samt Herkunft; `events` `{items}` (Positionen) oder `{field,value}` (Spalte); übrige Listen-Tabellen `{field,value,source,source_url,confidence}`.
- **Lösen nur im Browser.** `OpenItemService::resolve` verlangt `Actor::via = web` und Rolle `inhaber`/`mitglied`. Es gibt kein Lösen-Tool; die Web-Route nimmt nur Access-JWTs (`gmcp_`/`goa_` → 401). Entscheidungen: Konflikt `bestehend` · `neu` · `verwerfen`; Nachfrage/Beobachtung `erledigt` · `verwerfen`. Optionale Notiz bis 500 Zeichen.
- **Die Wirkung von „neu“ liegt in einer Klasse** (`OpenItemEffect`), die die Repositories der Phasen 1–4 nutzt: Fakt ablösen, Kilometerstand gültig setzen und widersprechende verwerfen, Regel ersetzen, Positionen ersetzen, Spalte setzen (Name gegen `RecordMerger::TABLES` geprüft). „Bestehend“ stellt den bestätigten Zustand her (strittige Zeile verworfen). Alles läuft in einer Transaktion; Sperrreihenfolge Fahrzeug → Klärfall.
- **Genau ein Audit-Eintrag je Lösung** (`open_item.resolve`, vorher/nachher mit Klärfall und betroffenen Werten). Zweites Lösen → 409 `already_resolved`.
- **Verlauf und Suche** sind Lese-Tools über dieselben Mandantengrenzen: `list_changes` liest `audit_log` (mit `vehicle_id`-Filter), `search` sucht in Ereignissen, Positionen, Teilen, Notizen und offenen Klärfällen (LIKE mit Escape-Zeichen `|`, 3–100 Zeichen).
- **Platzhalter-Seite im Konto** (Abschnitt „Klärfälle“ in `web/index.html`, `web/js/openitem.js`) bis zur Gestaltung in Meilenstein 4.

## Verworfen

- **Lösen per Tool mit Bestätigungs-Prompt:** der Agent könnte die Bestätigung selbst erzeugen; widerspricht ADR-008.
- **Neuen Wert nicht speichern, Agent soll später erneut melden:** der Mensch hätte nichts zum Vergleichen, und der Agent bekäme keinen Hinweis, dass etwas offen ist.

## Konsequenzen

- Jede neue Listen-Tabelle mit Klärfall braucht einen Fall in `OpenItemView` (Anzeige) und `OpenItemEffect` (Wirkung); `ref_table` ist die Weiche.
- Nach „neu“ bei einem Kilometerstand werden spätere gültige Stände, die dem neuen Stand widersprechen, nicht neu bewertet (offener Randfall, FINDINGS).
- Reservierte SQL-Wörter als Alias vermeiden (`stored` brach „neu“ bei Teilen, Zulassungen und Ereignis-Spalten).
