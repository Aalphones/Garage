# Phase 6 — Reiter Scheckheft und Wartung

Rating: standard · Modell: sonnet · braucht Phase 3, 4 und 5

## Kontext

- `artifacts/entwurf-oberflaeche.html` — „Golf · Scheckheft“ (Zeitstrahl und Tabelle), „Golf · Wartung“ (Tabelle und Karten); Vorlage `tabBook`, `tabPlan` im Skript
- README → „Kontrakt“ (`vehicles/{id}/events`, `events/{id}`, `vehicles/{id}/due`, `records/{type}/{id}/delete|restore`)
- `web/js/vehicle.js`, `provenance.js`, `toast.js` (Phase 1/5)

## AK

Scheckheft (`web/js/tab-book.js`, exportiert `renderBook(container, vehicle)`):
- [ ] Werkzeugleiste: „<n> Ereignisse · <m> Lücken“ links, Umschalter „Zeitstrahl“ / „Tabelle“ rechts; Wahl je Nutzer in `localStorage['garage.view.scheckheft']` (Standard Zeitstrahl).
- [ ] Zeitstrahl: Jahreszeilen, Ereignisse neueste zuerst, Lücken (aus `gaps`) an ihrem `expected_by`-Jahr als rot gestrichelte Karte mit Titel „Lücke: <Aufgabe>“ und `explanation`; bei `rule_uncertain` zusätzlich „Regel unsicher“.
- [ ] Ereignis aufklappbar (`<details>`, das neueste offen): Kopf Art (Großbuchstaben, Teal), Titel, Datum · km · Werkstatt, Betrag, Abzeichen; Inhalt lädt `GET events/{id}` beim ersten Aufklappen: Positionen als Tabelle (Art, Beschreibung, Netto), Anhänge als Leiste (Vorschaubild `preview_url`, Tippen → `belege.html#beleg/<id>`), offene Klärfälle zum Ereignis als gelbe Leiste mit Link.
- [ ] „Ereignis löschen“ (leise destruktiv, nur `permissions.write`): sofort `POST records/event/{id}/delete`, Ereignis verschwindet, Leiste „Ereignis gelöscht · Rückgängig“ → `…/restore` und neu laden.
- [ ] Tabelle: Datum, Art, Was, km, Betrag, Herkunft; Lücken als rote Zeile über volle Breite; unter 768 px Standard Zeitstrahl, Umschalter bleibt.
- [ ] Leer: „Noch keine Ereignisse“ + „Gib deinem Agenten eine Werkstattrechnung — er legt das Ereignis mit allen Positionen an.“

Wartung (`web/js/tab-plan.js`, `renderPlan`):
- [ ] Leiste „<n> Aufgaben · Gelb ab <thresholds.months> Monaten oder <thresholds.km> km“ + Umschalter Tabelle/Karten (`garage.view.wartung`; unter 768 px Standard Karten).
- [ ] Hinweisleiste gelb, wenn eine Regel `rule_uncertain` ist: „<n> Regel(n) unsicher“ + erste Aufgabe + „stammt aus einer Quelle mit Sicherheit unsicher“ + Link „Klärfälle ansehen“ → `klaerfaelle.html#fz=<id>`.
- [ ] Tabelle: Aufgabe, Regel (Text aus `rule` + Abzeichen), Zuletzt (`last_done` Datum · km oder „nie belegt“), Nächstes (`due_date`, `due_km`), Fortschritt (Balken: Anteil der verstrichenen Zeit von `last_done.date` bis `effective_date`, 0–100 %, ohne Basis leer), Status (`.st` überfällig/bald/ok/unbekannt mit Wort). Rechenweg (`explanation`) als `title` am Status.
- [ ] Karten: dieselben Werte je Aufgabe als `.row` mit Balken.
- [ ] Leer: „Noch kein Wartungsplan“ + „Frag deinen Agenten: „Welche Wartungsintervalle hat mein Auto?““.
- [ ] Prüfung: 360 px ohne waagerechtes Scrollen (Tabelle im Rollbereich nur ab 768 px), hell/dunkel, Löschen + Rückgängig im Browser durchgespielt.

## Checkliste

- [ ] `tab-book.js`, `tab-plan.js`, Anbindung in `vehicle.js`
- [ ] Doc-Update: `docs/code-map.md`

## Report-Back
