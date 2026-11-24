# Phase 8 — Fahrzeug-Aktionen, Druckansicht, gelöschte Fahrzeuge

Rating: standard · Modell: sonnet · braucht Phase 4 und 5

## Kontext

- `artifacts/entwurf-oberflaeche.html` — Fahrzeugkopf mit „Drucken · Markdown · Status · Löschen“, Blatt „Status ändern“ (Springen → Golf · Übersicht, dann „Status“), Blatt „löschen?“, Garage-Filter „Gelöscht“ mit „Wiederherstellen“ (Rolle Inhaber), Bildschirm „Akte drucken (Golf)“; Vorlage `viewVehicle` (`.vacts`), die `status`/`delcar`-Zweige im Klick-Handler, `carCard` für `geloescht`, `viewPrint`
- README → „Kontrakt“ (Schreiben); `docs/decisions/019-loeschen-und-status-durch-menschen.md`, `018-…` (Druck)
- `web/js/vehicle.js`, `garage.js`, `sheet.js`, `toast.js`; `app/src/Api/PrintController.php`, `Domain/DossierService.php`, `DossierFormat.php` (Phase 4)

## AK

Aktionen im Fahrzeugkopf (`.vacts`, Symbole 16 px):
- [x] „Drucken“ → `POST vehicles/{id}/print-link`, dann `window.open(url, '_blank', 'noopener')`; „Markdown“ → `GET vehicles/{id}/dossier` mit JWT, Download als Blob mit Dateinamen aus `Content-Disposition`. Beide für jede Rolle.
- [x] Nur `permissions.owner`: „Status“ öffnet Blatt „Status des <Name> ändern“ mit Satz „Verkaufte und stillgelegte Fahrzeuge bleiben mit ganzer Akte erhalten, zählen aber nicht mehr bei Fälligkeiten.“, Radiogruppe Aktiv/Verkauft/Stillgelegt (aktueller vorgewählt), Feld „Grund (optional)“, Knöpfe „Abbrechen“ / „Status speichern“ → `PATCH vehicles/{id}`, Leiste „Status gespeichert“, Kopf zeigt neuen Status.
- [x] Nur `permissions.owner`: „Löschen“ (rot) öffnet Blatt „<Name> löschen?“ mit Satz „Das Fahrzeug verschwindet aus Garage, Fälligkeiten und Suche. Akte und Belege bleiben gespeichert und lassen sich 30 Tage wiederherstellen.“, Pflichtfeld „Grund“, „Abbrechen“ / „Fahrzeug löschen“ (destruktiv, erst aktiv mit Grund) → `POST …/delete`, dann `garage.html` mit Leiste „<Name> gelöscht · Rückgängig“ (→ `…/restore`).
- [x] Unter 768 px stehen die vier Knöpfe in einer Zeile unter dem Kopf (kein Umbruch bei 360 px: Abstand 0, Innenabstand 0,45 rem).

Gelöschte Fahrzeuge (`garage.js`):
- [x] Chip „Gelöscht“ nur, wenn der Nutzer in mindestens einer Garage `inhaber` ist; lädt `GET vehicles?deleted=1`; Karte ohne Link: Name, Schild, „gelöscht am <Datum> · <Grund> · noch <n> Tage wiederherstellbar“, Knopf „Wiederherstellen“ → `…/restore`, Leiste „<Name> wiederhergestellt“, Filter springt auf „Aktiv“. Leer: „Keine gelöschten Fahrzeuge der letzten 30 Tage.“

Druckansicht (`PrintController` + `app/templates/akte-druck.php` + `web/css/print.css`):
- [x] Server-HTML ohne Skript: `<h1>` Modell, Kopfzeile Kennzeichen · EZ · Stand · ≈ km (geschätzt); Abschnitte in der Reihenfolge von `DossierService` (Stammdaten, Zulassung, Ausstattung, Spezifikationen, Kilometerstand, Fälligkeiten, Wartungsplan, Scheckheft, Messwerte, Reifen, Teile, Schäden, Rückrufe, Notizen, Offene Klärfälle, Belege) als Tabellen; Herkunft je Wert als kleiner Text „Beleg, bestätigt“. Daten aus denselben Services wie `DossierService` (keine Markdown-Umwandlung): `DossierService` bekommt dafür `sections(int $vehicleId, Actor $actor): list<array{title: string, rows: list<…>}>`, `render()` baut sein Markdown daraus — eine Quelle für beide.
- [x] `print.css`: weißes Papier, schwarze Schrift, Systemschrift 11 pt, Tabellen mit Linien, `@page { margin: 15mm }`, Abschnittsüberschriften nicht am Seitenende (`break-after: avoid`); oben ein Hinweis „Mit Strg+P / Teilen → Drucken als PDF speichern“, der im Druck verschwindet (`@media print`). Eingebunden über `<link>` (CSP `style-src 'self'`), keine Bilder außer optional dem Titelbild über einen signierten Datei-Link.
- [ ] Druckseite am Handy (360 px) lesbar: Innenabstand 1 rem, jede Tabelle in einem eigenen Container mit `overflow-x: auto` — die Seite selbst rollt nie seitlich.
- [ ] Prüfung nach README → „Breakpoints und Handy“ für Kopf mit Aktionen, beide Blätter, Gelöscht-Filter; Druckvorschau im Browser (Chrome) zeigt alle Abschnitte ohne abgeschnittene Tabellen; Seite funktioniert mit abgeschaltetem JavaScript; abgelaufener Link → Hinweisseite.

## Checkliste

- [x] `vehicle.js`, `garage.js`, Blätter
- [x] `DossierService::sections`, `render` darauf umgestellt (Markdown-Ausgabe von `get_dossier` byte-gleich wie vorher — vorher/nachher per `curl` vergleichen und im Report-Back nennen)
- [x] `PrintController`, Vorlage, `print.css`
- [x] Doc-Update: `docs/code-map.md` (`app/templates/`, `web/css/print.css`), `docs/routes.md`

## Report-Back

Phase 8 umgesetzt (Status: complete, ungeprüft zur Laufzeit — kein PHP/Browser im private-Profil).

- `DossierService`: `document()` (Kopf + Abschnitte), `sections()`; Abschnitte sind Blöcke (`table`, `heading`, `text`, `list`, `markdown`) statt Markdown-Zeilen, `render()` baut das Markdown daraus. Byte-Gleichheit nur per Durchsicht belegt (Trennzeile `''` zwischen Blöcken, leerer Tabellenblock bei „Strittig“ ohne Fakten); **kein `curl`-Vorher/Nachher möglich** (kein PHP lokal) — nach dem Deploy `get_dossier` gegen einen Stand vor `HEAD~` vergleichen.
- Abweichung: Schlüssel heißt `blocks` statt `rows`; deleted-Liste der API trägt kein Kennzeichen, die Karte „Gelöscht“ zeigt deshalb nur Name (Kontrakt Phase 2 unverändert).
- Druck: Titelbild entfällt (optional im Plan). Drucken öffnet per `window.open` nach dem API-Aufruf — ein Popup-Blocker kann es abfangen.
- Rückgängig nach Fahrzeug löschen: Wechsel auf `garage.html` über `sessionStorage` (`garage.deleted`), die Leiste kommt dort.
