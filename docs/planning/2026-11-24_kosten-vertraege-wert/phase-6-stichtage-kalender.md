# Phase 6 — Stichtage, Notfallkarte-Daten, Kalender-Abo

Rating: heikel · Modell: opus (öffentlicher Link ohne Anmeldung, RFC-5545-Format)

## Kontext

- Plan-README → Kontrakt (`GET vehicles/{id}/deadlines`, `GET vehicles/{id}/emergency`, `GET garages/{id}/calendar-link`, `POST garages/{id}/calendar-link/rotate`, `GET /garage/kalender/{slug}.ics`; `get_papers` füllt `deadlines` und `emergency`)
- ADR-024, ADR-025
- Signierter Link als Vorbild: `app/src/Auth/PrintLink.php` (Schlüssel aus `GARAGE_JWT_SECRET`, `hash_hmac`, `hash_equals`), `app/src/Api/PrintController.php` (Route ohne `/api`, 404-Seite, `no-store`/`noindex`)
- Fälligkeiten: `DueService::dueItems(null, 36, $actor)` bzw. je Fahrzeug; Felder `effective_date`, `status`, `explanation`, Aufgabentitel
- Verträge: `ContractRepository`/`ContractService` (Phase 2); Plaketten/Zubehör über `RecordRepository::listForVehicle`
- Rollen: `Actor::roles`, `Actor::ROLE_OWNER`; Garage lesen: `GarageRepository`
- Routen: `app/index.php`; `web/.htaccess` reicht Pfade ohne Datei an die Brücke (in Meilenstein 4 geprüft)
- Fehlerklassen: keine im Vault; die Format-Fallen stehen als AK unten

## AK

- [x] `DeadlineService::forVehicle(int, Actor): list<{kind, title, date, status: warn|ok, days, explanation, ref:{type,id}}>`, aufsteigend nach Datum, nur Termine ab heute bis heute + 24 Monate:
  - `kuendigung` — Verträge `versicherung`/`club`/`stellplatz`/`sonstiges` mit `main_due` und `notice_months`, ohne `ends_on`: nächste Hauptfälligkeit ≥ heute (`JJJJ-MM-TT` aus `main_due`), Stichtag = Hauptfälligkeit − `notice_months` Monate − 1 Tag; liegt der Stichtag vor heute, die übernächste Hauptfälligkeit. Prüffall: `main_due = 01-01`, 1 Monat, heute 24.11.2026 → 30.11.2026.
  - `sonderkuendigung` — offene `contract_events` der Art `beitragsrechnung` mit `amount_cents` größer als `amount_cents` der jüngsten Periode vor `occurred_on`: `occurred_on` + 1 Monat.
  - `faelligkeit` — Verträge `steuer` und `club` zur nächsten Hauptfälligkeit (Titel „Kfz-Steuer fällig“, „<Anbieter>: Beitrag fällig“).
  - `plakette` — `permits.valid_until`; `garantie` — `accessories.warranty_until`.
  - Garagen-Verträge erscheinen bei jedem aktiven Fahrzeug der Garage.
  - `status` = `warn` bei ≤ 30 Tagen, sonst `ok`; `explanation` = Rechenweg in einem Satz.
- [x] Prüfzeile je Vertrag in `get_papers` (`check: {status: ok|warn|unbekannt, text, explanation}|null`): **Steuer** gegen den Betrag der jüngsten Periode — Benziner ab EZ 07/2009: 2 € je angefangene 100 cm³ + 2 € je g CO₂ über der Freigrenze (120 g/km bei EZ bis 2011, 110 g/km 2012–2013, 95 g/km ab 2014); Diesel: 9,50 € je angefangene 100 cm³ + CO₂-Anteil wie Benziner; anderer Kraftstoff oder fehlender Fakt (`hubraum_ccm`, `co2_g_km`, `erstzulassung`, `kraftstoff`) → `unbekannt` mit Grund („CO₂ (Feld V.7) fehlt“); Abweichung > 1 € → `warn`. Prüffall Golf: 1.498 cm³, 147 g/km, EZ 2019 → 15 × 2 + 52 × 2 = 134 € → `ok`. **Versicherung**: Prognose km/Jahr (`DueService::forecastFor` → `per_day × 365`, gerundet auf 100) gegen `declared_km_per_year` der jüngsten Periode; darüber → `warn`, sonst `ok`, ohne Angabe → `null`. **Garagen-Vertrag**: `ok` mit Umlage-Rechenweg (Betrag, Schlüssel, Anteil).
- [x] `PaperService::emergency(int, Actor)`: `{vehicle_id, label, plate (gültige Zulassung), fin, insurer: {provider, contract_number, hotline}|null (aktiver Fahrzeug-Vertrag versicherung), breakdown: {provider, member_number, hotline}|null (Garagen- oder Fahrzeug-Vertrag club), as_of (heute)}`; `get_papers` liefert jetzt `deadlines` und `emergency`.
- [x] `CalendarLink`: `url(int $garageId, int $version): string` = `<Basis-URL>/garage/kalender/<garageId>-<sig>.ics`, `sig` = erste 32 Zeichen base64url (ohne `=`) von HMAC-SHA256 mit Schlüssel `hash_hmac('sha256', 'garage-calendar', GARAGE_JWT_SECRET, true)` über `"calendar|<garageId>|<version>"`; `verify(string $slug): ?int` (Garage-ID bei Gültigkeit, Vergleich mit `hash_equals`, Version aus `garages.calendar_version`).
- [x] `GET garages/{id}/calendar-link` für jede Rolle der Garage; `POST …/rotate` nur `inhaber` (sonst 403), zählt `calendar_version` hoch, Audit `calendar.rotate`, liefert die neue URL. Fremde Garage → 404.
- [x] `GET /garage/kalender/{slug}.ics`: ungültig/alt → 404 ohne Inhalt; gültig → `Content-Type: text/calendar; charset=utf-8`, `Cache-Control: private, max-age=3600`, `X-Robots-Tag: noindex`. Inhalt nach RFC 5545: Zeilenende CRLF; `BEGIN:VCALENDAR`, `VERSION:2.0`, `PRODID:-//GARAGE//Kalender//DE`, `CALSCALE:GREGORIAN`, `X-WR-CALNAME:GARAGE – <Garagenname>`, `REFRESH-INTERVAL;VALUE=DURATION:PT6H`, `X-PUBLISHED-TTL:PT6H`; je Termin ein `VEVENT` mit `UID:<kind>-<ref.type>-<ref.id>-<datum>@garage` (stabil über Abrufe), `DTSTAMP:<jetzt UTC JJJJMMTTTHHMMSSZ>`, `DTSTART;VALUE=DATE:JJJJMMTT`, `DTEND;VALUE=DATE:<Folgetag>`, `SUMMARY:<Kennzeichen oder Name>: <Titel>`, `DESCRIPTION:<Rechenweg>`; Texte maskiert (`\\`, `\;`, `\,`, Zeilenumbruch → `\n`); Zeilen nach 75 Oktetten gefaltet (CRLF + Leerzeichen), ohne ein UTF-8-Zeichen zu zerteilen.
- [x] Inhalt des Abos: Fälligkeiten (`effective_date` vorhanden, Status nicht `unbekannt`) und alle Stichtage der Fahrzeuge mit Status `aktiv`; der Aufruf läuft ohne Nutzer — `CalendarService` baut dafür einen Lese-Akteur nur für diese Garage (Muster prüfen: wie `PrintController` den Akteur aus der Link-Nutzlast baut; hier Rolle `lesend`, `via` = eigener Wert `calendar`, keine Schreibrechte).

## Checkliste

- [x] `app/src/Domain/DeadlineService.php`, `app/src/Domain/CalendarService.php` (`feed(int $garageId): string`, `rotate`), `app/src/Auth/CalendarLink.php`, `app/src/Domain/Ical.php` (reine Klasse: `escape`, `fold`, `event`, `calendar`).
- [x] `PaperService::emergency`; `get_papers` ergänzen.
- [x] `app/src/Api/CalendarController.php` (`link`, `rotate`, `feed`), `PaperController` (`deadlines`, `emergency`, `papers`) + Routen.
- [ ] `.ics`-Ausgabe einmal lokal erzeugen ist nicht möglich (kein PHP) → nach dem Deploy mit `curl` holen und gegen https://icalendar.org/validator.html prüfen; Ergebnis in Report-Back.
- [x] Doku: `docs/routes.md`, `docs/code-map.md`, `docs/glossary.md` (Stichtag, Kalender-Abo).
- [ ] Syntax: CI-Lauf nach dem Push ansehen.

## Report-Back

- Status: complete (2026-11-24). Neu: `DeadlineService`, `ContractCheck`, `CalendarService`, `Ical`, `Auth\CalendarLink`, `Api\PaperController`, `Api\CalendarController`; `PaperService` liefert Prüfzeilen, `deadlines` und `emergency`; `Actor::calendar` (Rolle `lesend`, `via` `calendar`), `Actor::isOwner`; `GarageRepository::find|calendarVersion|bumpCalendarVersion`; sechs Routen.
- Abweichung Steuer: Für Erstzulassung ab 2021 rechnet `ContractCheck` den CO₂-Anteil gestaffelt nach § 9 Abs. 1 Nr. 2 Buchst. c KraftStG (2,00/2,20/2,50/2,90/3,40/4,00 € je g in 20-g-Stufen ab 95 g/km) statt pauschal 2 €; sonst gäbe jedes neuere Auto über 115 g/km ein falsches `warn`. Prüffall Golf (EZ 2019) bleibt 134 €. Der Beitrag wird anteilig auf die Länge der Periode verglichen.
- Abweichung Ausgabe: jeder Stichtag trägt zusätzlich `level` (`fahrzeug`|`garage`); das Abo führt Garagen-Termine damit einmal unter dem Garagennamen statt je Fahrzeug mit derselben `UID`.
- Fälligkeiten im Abo: `UID` `wartung-task-<vehicle_id>.<task_key>-<datum>@garage`; ändert sich das wirksame Datum (km-Prognose), ersetzt ein neuer Termin den alten.
- Offen: `.ics` nach dem Deploy mit `curl` holen und gegen https://icalendar.org/validator.html prüfen; CI-Lauf (PHP-Syntax, lokal kein PHP) ansehen.

