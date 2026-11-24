# Phase 1 — Datenmodell und Entscheidungen

Rating: standard · Modell: sonnet

## Kontext

- `docs/conventions/php.md`, `docs/models.md` (Spaltenkonventionen), `docs/decisions/009-datensatz-abgleich-und-spezifikationen-als-fakten.md` (Abgleich), `docs/decisions/021-kleine-eingaben-durch-menschen.md` (Vorbild ADR-025)
- Vorlage einer Faktentabelle mit Herkunftsblock: `app/migrations/013_part.sql` (Spalten `source … updated_at`, Index, Fremdschlüssel)
- `app/src/Db/Migrator.php` (Anweisungen einzeln, ohne Transaktion — jede Datei muss auch nach Teil-Lauf erneut startbar sein: `CREATE TABLE` nur einmal je Datei, kein `IF NOT EXISTS`-Zwang, aber keine Daten-Updates, die doppelt schaden)
- `app/src/Domain/VehicleService.php` → `FIELDS`, `TYPE_*`
- Plan-README dieses Ordners → Kontrakt
- Fehlerklassen: keine einschlägig (siehe README)

## AK

- [ ] Fünf Migrationen `app/migrations/020_contract.sql`, `021_expense.sql`, `022_valuation.sql`, `023_accessory.sql`, `024_calendar.sql` mit genau den Tabellen und Spalten unten; jede Faktentabelle trägt den Herkunftsblock aus `013_part.sql` (`source` … `updated_at`) und `INDEX idx_<tabelle>_vehicle_status (vehicle_id, status)`.
- [ ] `VehicleService::FIELDS` kennt `unfallfrei` (Text, nur `ja` oder `nein`) und `halterzahl` (Zahl 1–20); `DossierService::FACT_LABELS` (Konstante mit `'fin' => 'FIN'`) hat „Unfallfrei“ und „Anzahl Halter“.
- [ ] `LifecycleService::setVehicleStatus` setzt `vehicles.status_since` auf das heutige Datum (Europe/Berlin), `restoreVehicle` und ein Wechsel zurück auf `aktiv` setzen es auf `NULL`.
- [ ] ADR-022 bis ADR-025 liegen unter `docs/decisions/` mit dem Inhalt aus „ADR-Inhalte“ unten (Format wie ADR-021: Status, Kontext, Entscheidung, Verworfen, Konsequenzen).
- [ ] `docs/models.md` beschreibt alle neuen Tabellen und Spalten.

## Tabellen

Herkunftsblock = `source ENUM('beleg','nutzer','recherche','agent','werkstatt') NOT NULL, source_url VARCHAR(500) NULL, source_document_id INT UNSIGNED NULL, confidence ENUM('bestaetigt','wahrscheinlich','unsicher') NOT NULL, status ENUM('aktiv','geloescht') NOT NULL DEFAULT 'aktiv', status_reason VARCHAR(300) NULL, actor VARCHAR(160) NOT NULL, user_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL`. Beträge immer `…_cents INT` (vorzeichenlos außer wo genannt). Alle Tabellen `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, `garage_id INT UNSIGNED NOT NULL` mit `CONSTRAINT fk_<tabelle>_garage FOREIGN KEY (garage_id) REFERENCES garages (id)`.

**020_contract.sql**

- `contracts`: id, garage_id, `vehicle_id INT UNSIGNED NULL` (NULL = Garagen-Vertrag), `kind ENUM('versicherung','steuer','club','stellplatz','finanzierung','sonstiges') NOT NULL`, `provider VARCHAR(120) NOT NULL`, `contract_number VARCHAR(60) NOT NULL DEFAULT ''`, `tariff VARCHAR(120) NULL`, `starts_on DATE NULL`, `main_due CHAR(5) NULL` (Hauptfälligkeit `MM-TT`), `notice_months TINYINT UNSIGNED NULL`, `payment_interval ENUM('jaehrlich','halbjaehrlich','vierteljaehrlich','monatlich') NULL`, `ends_on DATE NULL` (gekündigt zum), `allocation ENUM('gleich','km','fest') NULL` (nur Garagen-Vertrag, Standard dort `gleich`), `hotline VARCHAR(40) NULL`, `member_number VARCHAR(60) NULL`, `note VARCHAR(200) NULL`, Herkunftsblock; `INDEX idx_contracts_garage_status (garage_id, status)`, `INDEX idx_contracts_vehicle_status (vehicle_id, status)`.
- `contract_terms`: id, garage_id, `contract_id INT UNSIGNED NOT NULL` (FK contracts), `valid_from DATE NOT NULL`, `valid_to DATE NOT NULL`, `amount_cents INT UNSIGNED NOT NULL` (Betrag der ganzen Periode), `coverage VARCHAR(120) NULL`, `sf_liability VARCHAR(10) NULL`, `sf_comprehensive VARCHAR(10) NULL`, `type_class_liability TINYINT UNSIGNED NULL`, `type_class_partial TINYINT UNSIGNED NULL`, `type_class_full TINYINT UNSIGNED NULL`, `regional_class TINYINT UNSIGNED NULL`, `declared_km_per_year INT UNSIGNED NULL`, `deductible_note VARCHAR(120) NULL`, Herkunftsblock; `INDEX idx_contract_terms_contract (contract_id, status)`.
- `contract_allocations`: id, garage_id, contract_id (FK), `vehicle_id INT UNSIGNED NOT NULL` (FK vehicles), `share_percent DECIMAL(5,2) NOT NULL`, `valid_from DATE NOT NULL`, `valid_to DATE NULL`, Herkunftsblock; `INDEX idx_contract_allocations_contract (contract_id, status)`.
- `contract_events`: id, garage_id, contract_id (FK), `kind ENUM('beitragsrechnung','aenderung','schadenmeldung','regulierung','rueckstufung','kuendigung') NOT NULL`, `occurred_on DATE NOT NULL`, `amount_cents INT NULL` (Erstattung negativ), `event_status ENUM('offen','in_bearbeitung','erledigt') NOT NULL DEFAULT 'offen'`, `claim_number VARCHAR(60) NULL`, `damage_id INT UNSIGNED NULL`, `note VARCHAR(300) NULL`, Herkunftsblock; `INDEX idx_contract_events_contract (contract_id, status)`.

Die drei Untertabellen tragen kein `vehicle_id`: sie hängen über `contract_id` am Vertrag (Fahrzeug oder Garage). Der Index `idx_<tabelle>_vehicle_status` entfällt dort.

**021_expense.sql**

- `expenses`: id, garage_id, vehicle_id (FK, NOT NULL), `spent_on DATE NOT NULL`, `category ENUM('parken','waesche','pflege','maut','reifeneinlagerung','bussgeld','sonstiges') NOT NULL`, `amount_cents INT UNSIGNED NOT NULL`, `spread_months TINYINT UNSIGNED NOT NULL DEFAULT 1`, `note VARCHAR(200) NULL`, Herkunftsblock.
- `fuel_logs`: id, garage_id, vehicle_id (FK), `fueled_on DATE NOT NULL`, `odometer_km INT UNSIGNED NOT NULL`, `liters DECIMAL(6,2) NOT NULL`, `amount_cents INT UNSIGNED NOT NULL`, `is_full TINYINT(1) NOT NULL`, `odometer_reading_id INT UNSIGNED NULL`, `station VARCHAR(80) NULL`, Herkunftsblock.

**022_valuation.sql**

- `valuations`: id, garage_id, vehicle_id (FK), `valued_on DATE NOT NULL`, `kind ENUM('kaufpreis','verkaufspreis','gutachten','haendlerangebot','bewertung','schaetzung') NOT NULL`, `value_type ENUM('haendlereinkauf','privatverkauf','haendlerverkauf') NULL`, `amount_cents INT UNSIGNED NOT NULL`, `range_low_cents INT UNSIGNED NULL`, `range_high_cents INT UNSIGNED NULL`, `odometer_km INT UNSIGNED NULL`, `provider VARCHAR(120) NOT NULL DEFAULT ''`, `note VARCHAR(200) NULL`, Herkunftsblock.
- `market_listings`: id, garage_id, vehicle_id (FK), `url VARCHAR(500) NOT NULL`, `fetched_on DATE NOT NULL`, `price_cents INT UNSIGNED NOT NULL`, `odometer_km INT UNSIGNED NOT NULL`, `first_registration CHAR(7) NOT NULL` (`JJJJ-MM`), `same_drivetrain TINYINT(1) NOT NULL`, `equipment_note VARCHAR(300) NULL`, `postal_code VARCHAR(10) NULL`, Herkunftsblock.

**023_accessory.sql**

- `accessories`: id, garage_id, vehicle_id (FK), `name VARCHAR(120) NOT NULL`, `category ENUM('traeger','kindersitz','matten','dashcam','kabel','sonstiges') NOT NULL`, `manufacturer VARCHAR(80) NULL`, `article_number VARCHAR(60) NULL`, `bought_on DATE NULL`, `price_cents INT UNSIGNED NULL`, `warranty_until DATE NULL`, `mount_state ENUM('montiert','eingelagert') NULL`, `storage_location VARCHAR(120) NULL`, `goes_with_sale TINYINT(1) NULL`, Herkunftsblock.
- `permits`: id, garage_id, vehicle_id (FK), `kind ENUM('umweltplakette','critair','vignette','parkausweis','mautbox','sonstiges') NOT NULL`, `label VARCHAR(80) NOT NULL`, `number VARCHAR(60) NULL`, `valid_from DATE NULL`, `valid_until DATE NULL` (NULL = unbegrenzt), `price_cents INT UNSIGNED NULL`, Herkunftsblock.

**024_calendar.sql**

- `ALTER TABLE garages ADD COLUMN calendar_version INT UNSIGNED NOT NULL DEFAULT 1;`
- `ALTER TABLE vehicles ADD COLUMN status_since DATE NULL;`

## ADR-Inhalte

**ADR-022 Kosten und Verträge.** Verträge sind eigene Datensätze mit Beitragsperioden statt Rechnungsbeträgen. Ebene Fahrzeug (`vehicle_id`) oder Garage (`vehicle_id NULL`); Garagen-Verträge werden tagesgenau umgelegt: `gleich` (Betrag ÷ aktive Autos des Tages), `km` (Anteil an den km des Monats), `fest` (`contract_allocations`, Prozent mit Gültigkeit). Kein `contract_items`: ein Fahrzeug-Vertrag deckt sein Fahrzeug, ein Garagen-Vertrag alle aktiven. Vier Blöcke nach ADAC: Fixkosten (Vertragsperioden tagesgleich; HU-Ereignisse über 24 Monate; Plaketten über ihre Gültigkeit), Betrieb (Tankungen und Zubehör am Tag; Ausgaben über `spread_months`), Werkstatt (alle Ereignisse außer `kauf` und `hu` am Leistungsdatum, Bruttobetrag), Wertverlust (linear zwischen Kaufpreis und jüngstem Wert). Immer zweimal: laufend (ohne Wertverlust) und mit Wertverlust. Fehlende Blöcke werden genannt (Versicherung, Steuer, Kraftstoff, Wertverlust). Aktiv ist ein Auto ab Kaufpreis-Datum (sonst ältestem `registrations.valid_from`) bis `status_since`, wenn der Status nicht `aktiv` ist. Die vier Vertragstabellen gleicht `ContractMerger` ab (gleiche Ergebniswörter und Klärfall-Regel wie `RecordMerger`), weil Garagen-Verträge kein Fahrzeug haben. Verworfen: Beträge nur als Ausgaben (keine SF-/Typklassen, keine Stichtage); `contract_items` (kein Fall im Haushalt); Erweiterung von `RecordMerger` um Garagen-Zeilen (berührt jeden bestehenden Abgleich). Konsequenz: Kosten werden bei jedem Lesen gerechnet, nie gespeichert; ein verkauftes Fahrzeug ohne `status_since` zählt in keinem Zeitraum.

**ADR-023 Wertermittlung.** Kein Gutachten, keine fremde API. Ankerwerte (`valuations`, der Kaufpreis ist eine Bewertung der Art `kaufpreis`), Vergleichsinserate mit URL und Abrufdatum (`market_listings`; ob Motor und Getriebe passen, entscheidet der Agent beim Ablegen: `same_drivetrain`). Ein Inserat zählt, wenn `same_drivetrain`, Erstzulassungsjahr ±1, km ±25 % des eigenen geschätzten Stands und höchstens 60 Tage alt. Ab 8 zählenden: Median und mittlere Hälfte (Rang `round(q·(n−1))` auf aufsteigend sortierten Preisen). Ab 15: lineare Regression Preis ~ km + Alter in Monaten, Mitte = Vorhersage für das eigene Auto, Band = Vorhersage + 1. und 3. Quartil der Abweichungen; bei nicht lösbarem Gleichungssystem zurück auf Median. Acht Wertfaktoren mit Plus/Minus/Unbekannt und Fundstelle (Regeln in Phase 4). Neue Fakten `unfallfrei`, `halterzahl`. Verworfen: Halterzahl aus Zulassungen zählen (Halter oft leer); Motor-/Getriebecode im Inserat vergleichen (Inserate nennen sie selten). Konsequenz: Angebotspreise sind keine Verkaufspreise — die Oberfläche sagt das am Korridor.

**ADR-024 Stichtage und Kalender-Abo.** Stichtage werden gerechnet: Kündigung = nächste Hauptfälligkeit − `notice_months` Monate − 1 Tag; Sonderkündigung = `occurred_on` einer offenen Beitragsrechnung, deren Betrag über der letzten Periode liegt, + 1 Monat; Steuer und Club zur Hauptfälligkeit; Plakette `valid_until`; Zubehör `warranty_until`. Ein Kalender-Abo je Garage unter `/garage/kalender/<garage_id>-<sig>.ics`, `sig` = erste 32 Zeichen von base64url(HMAC-SHA256(Schlüssel wie `PrintLink`, `"calendar|<garage_id>|<calendar_version>"`)); Erneuern zählt `calendar_version` hoch, der alte Link antwortet 404. Inhalt: Fälligkeiten mit `effective_date` und alle Stichtage der aktiven Fahrzeuge, ganztägig. Verworfen: Token in der Datenbank (müsste zum Kopieren lesbar bleiben); Abo je Nutzer (Termine gehören der Garage). Konsequenz: wer den Link hat, sieht Kennzeichen und Termine ohne Anmeldung.

**ADR-025 Tanken, Ausgaben, Notfallkarte.** Erweitert ADR-021: Inhaber und Mitglieder tragen im Browser Tankungen (Datum, km, Liter, Betrag, voll) und Ausgaben (Datum, Kategorie, Betrag, Notiz) ein, Herkunft `nutzer`/`bestaetigt`; der km-Stand einer Tankung wird ein Kilometerstand (Rückwärts → Klärfall). Menschen löschen dazu Tankungen, Ausgaben, Verträge, Vorgänge, Bewertungen, Inserate, Zubehör und Plaketten (Garagen-Verträge nur mit Schreibrecht in der Garage). Die Notfallkarte liegt nach dem Laden im Cache des Geräts (`garage-notfall`), `notfall.html` öffnet sie ohne Netz; Abmelden löscht den Cache. Verworfen: ganze App offline (Aktualität nach Deploy, Aufwand); Notfallkarte in `localStorage` (ADR-018: keine Daten dort). Konsequenz: Kennzeichen, FIN, Vertragsnummern stehen unverschlüsselt im Browser-Cache, bis abgemeldet wird.

## Checkliste

- [ ] Migrationen 020–024 schreiben (Spalten exakt wie oben, Reihenfolge der Spalten wie gelistet, Herkunftsblock am Ende).
- [ ] `VehicleService::FIELDS`: `'unfallfrei' => ['type' => self::TYPE_TEXT, 'choices' => ['ja', 'nein'], 'example' => 'ja']`, `'halterzahl' => ['type' => self::TYPE_INT, 'min' => 1, 'max' => 20, 'example' => '2']`; in der Validierung von `TYPE_TEXT` `choices` prüfen (Fehlertext nennt die erlaubten Werte); `TYPE_INT` `min`/`max` prüfen, falls noch nicht vorhanden (vorher lesen).
- [ ] `DossierService` Feldnamen-Konstante um beide Felder ergänzen.
- [ ] `LifecycleService::setVehicleStatus`/`restoreVehicle`: `status_since` schreiben (über `VehicleRepository`, Methode `setStatus` o. ä. — vorhandene Methode erweitern, die `status` schreibt).
- [ ] ADR-022 bis 025 anlegen.
- [ ] `docs/models.md`: Abschnitt „Kosten und Verträge“, „Wert“, „Zubehör und Plaketten“, Spalten `garages.calendar_version`, `vehicles.status_since`.
- [ ] Syntax: lokal gibt es kein PHP — nach dem Push den CI-Lauf (`php -l` je Datei) ansehen; rot → in dieser Phase beheben.

## Report-Back
