# Code-Map — GARAGE

Feature → Ordner. Bewusst grob, keine Zeilennummern. Steht: Gerüst (Bootstrap, Http, Env, Log, Migrator, Brücke, Dev-Router, Deploy), Konten (Einladung, Anmeldung, Akteur), OAuth-Server, MCP-Endpunkt mit Fahrzeugen, Datensatz-Abgleich (Zulassung, Ausstattung, Spezifikationen), Scheckheft und Kilometer, Wartungsplan, Messwerte/Reifen/Teile, Klärfälle/Verlauf/Suche, Belegablage (Ablage-Kern: Tickets, Bilder, signierte Links). Übrige Ordner entstehen mit ihren Meilensteinen.

## Namensschema (erst raten, dann suchen)

Eine Domäne `<x>` (vehicle, event, task, measurement, tire, part, document, inbox, contract, expense, valuation, garage, user …) heißt in jeder Schicht gleich:

| Schicht | Datei |
|---|---|
| Fachlogik | `app/src/Domain/<X>Service.php` |
| Datenbankzugriff | `app/src/Domain/<X>Repository.php` |
| MCP-Tools | `app/src/Mcp/Tools/<X>Tools.php` |
| Web-API | `app/src/Api/<X>Controller.php` |
| Migration | `app/migrations/NNN_<x>.sql` |
| Oberfläche | `web/js/<x>.js` |

## Repo → Server

| Im Repo | Auf dem Server (`www/`) | Inhalt |
|---|---|---|
| `app/` | `garage-app/` | gesamter PHP-Code, Migrationen, Texte für Agenten, `.env`, `storage/` — gesperrt (ADR-002) |
| `web/` | `garage/` | Brücke `index.php`, `.htaccess` (Rewrite, eigene CSP), Oberfläche: Seiten `index.html` (Anmeldung + Konto), `einladung.html`, `zustimmung.html` direkt in `web/` (Unterordner gingen an die Brücke), Skripte in `js/`, Stil in `css/` |
| — | `.htaccess` an der Wurzel | gehört DISPATCH; leitet `/.well-known/<art>/garage…` an die Brücke (ADR-001) |
| `deploy.cmd`, `deploy.env.example` | — | Deploy (ADR-003); Vorlage für `deploy.env` (nicht im Git) |
| `dev-router.php`, `dev.env.example` | — | lokaler Server `php -S localhost:8081 dev-router.php`, Vorlage für `app/.env.local` |
| `docs/design/` | — | Fachkonzept, später Entwürfe der Oberfläche |

## Ordner in `app/`

| Ordner | Aufgabe |
|---|---|
| `bootstrap.php` | Autoloader `Garage\`, `.env` laden, Fehler-Handler; liefert die Konfiguration |
| `config.php` | Basis-URL (Domain), Basis-Pfad `/garage`, Pfade |
| `index.php` | registriert alle Routen am `Http\Router` (Tabelle: [`routes.md`](routes.md)) |
| `src/Http/` | `Router` (Methode + Pfad mit `{id}`/`{slug}`, 405/404), `Request`, `Response` |
| `src/Db/` | `Connection` (PDO, UTC), `Migrator` (ohne Transaktion, Anweisungen einzeln), `Transaction` |
| `src/Auth/` | `DeployToken`, `Jwt` (Access-JWT), `LoginThrottle`, `FileLink` (signierte Datei-Links), `UserSession` (Login/Refresh/Logout), `ApiAuth` (JWT → `Actor`, nur Deploy-Token), `Scopes` |
| `src/Api/` | `HealthController`, `DeployController`, `AuthController`, `InvitationController`, `MeController`, `TokenController`, `OAuthController` (Zustimmung, verbundene Apps), `OpenItemController` (Klärfälle listen und lösen), `UploadController` (Datei-Upload per Ticket), `FileController` (Datei per signiertem Link), `ApiErrors` (Fehlercode → Status) |
| `src/Domain/` | `DomainError`, `Actor`; Konten und Garagen: `UserRepository`, `GarageRepository`, `InvitationService`/`InvitationRepository`, `TokenService` (feste `gmcp_`-Tokens), `TokenRepository` (Sitzungen und feste Tokens), `AuditRepository`; Fahrzeuge: `VehicleService` (Validierung, Konfliktregel), `VehicleRepository` (Fahrzeuge und Fakten, immer mit Akteur), `OpenItemRepository` (Klärfälle anlegen, mit Vorschlag); gemeinsame Bausteine: `Provenance` (Herkunft prüfen, Konfidenz vergleichen), `Validate` (Eingabeprüfer mit Beispieltext), `VehicleAccess` (Fahrzeug lesen/schreiben, sperrt die Zeile); Datensatz-Abgleich: `RecordMerger` (Schlüssel je Tabelle in `TABLES`, ADR-009), `RecordRepository` (Listen je Fahrzeug), `RegistrationService`, `EquipmentService`; Spezifikationen laufen über `VehicleService`; Scheckheft: `EventService` (Ereignis mit Werkstatt, Positionen, Trockenlauf; ruft `RecordMerger` und `OdometerService`), `EventRepository` (Lesen, Positionen schreiben), `WorkshopRepository`, `OdometerService` (Vorwärts-Regel, ADR-011), `OdometerRepository`; Wartungsplan: `TaskService` (Aufgabe + Intervallregel, Abgleich, Klärfall), `TaskRepository` (Aufgaben, Regeln, letzte Ausführungen), `DueService` (liest Stände und Regeln, ruft die reinen Rechner; `dueItems`, `summary` für `list_vehicles`, `forecastFor` für `get_vehicle`), `DueCalculator` und `OdometerForecast` (reine Klassen ohne Datenbank: Fälligkeit „was zuerst eintritt“ mit Rechenweg, Kilometerprognose; ADR-011 Teil 2); `Money` (Cent ↔ Euro-Text); Messwerte, Reifen, Teile, Schäden, Notizen: `MeasurementService` (Einheiten je Größe, Stand über `OdometerService`), `TireService`/`TireRepository` (Sätze über `RecordMerger`, Montagen eigen, `dotAgeYears`), `PartService`, `DamageService` (Schäden und Rückrufe), `NoteService`/`NoteRepository` (Revisionen, `listCurrent` für die Akte), `EventLink` (Verweis auf ein Ereignis des Fahrzeugs prüfen); Klärfälle, Verlauf, Suche: `OpenItemService` (`list`, `listForHumans`, `create`, `resolve` — nur Mensch, ADR-010), `OpenItemView` (Bisher/Neu je `ref_table`), `OpenItemEffect` (Wirkung von „neu“/„bestehend“ je `ref_table`), `ChangeService` (Audit-Log lesen), `SearchService`/`SearchRepository` (LIKE-Suche über Ereignisse, Positionen, Teile, Notizen, Klärfälle, Belege); Akte: `DossierService` (die 16 Abschnitte als Markdown, der letzte „Belege“ aus `DocumentRepository`, liest nur über die Dienste der Bereiche), `DossierFormat` (deutsche Datums-/Zahlen-/Euroformate, Herkunft in Klammern, Tabellen); Belege (ADR-012): `DocumentService` (Ticket ausstellen/einlösen, Datei prüfen und ablegen, Dublette, Links, Darstellung; `attach` mit `TARGETS`, `storeFromUrl`/`importFromUrl` über `Util\HttpClient`, `forTarget`/`forVehicle` für die Lese-Tools; ADR-013, ADR-015), `DocumentRepository` (Belege und Tickets; `findById`/`findTicketByHash` bewusst ohne Akteur) |
| `src/OAuth/` | OAuth-Server aus DISPATCH (ADR-005), nur CIMD-Clients: `Discovery`/`DiscoveryController` (Aussteller mit Pfad `/garage`), `AuthorizeController`, `Consent` (nur Mensch im Browser), `TokenIssuer` (Code-Tausch, Rotation, `authenticate` für den MCP-Endpunkt), `TokenController`, `ClientRegistry` (CIMD-Abruf), `OAuthRepository` |
| `src/Util/` | `Env`, `Log`, `HttpClient`/`HttpResponse` (einziger Weg nach außen, SSRF-Schutz), `ImageProcessor` (Bild prüfen, drehen, als JPEG in drei Größen) |
| `src/Mcp/`, `mcp/` | MCP-Schicht aus DISPATCH (ADR-005): `McpController` (Endpunkt `/garage/mcp`, Anmeldung, Methoden), `Protocol` (beide Generationen), `Registry`/`Tool`/`Schema`/`ToolResult`, `Resources` (Fahrzeugliste, Akte, Fälligkeiten, Leitfäden; mit Diensten und Akteur) / `Prompts` (`beleg_erfassen`, `was_steht_an`), `Tools/VehicleTools` (fünf Tools), `Tools/RegistrationTools`, `Tools/EquipmentTools`, `Tools/EventTools` (vier Tools), `Tools/OdometerTools`, `Tools/TaskTools` (`upsert_task_rule`, `get_due_items`), `Tools/MeasurementTools`, `Tools/TireTools`, `Tools/PartTools`, `Tools/DamageTools` (`record_damage`, `record_recall`), `Tools/NoteTools`, `Tools/OpenItemTools` (`list_open_items`, `open_item`), `Tools/ChangeTools` (`list_changes`), `Tools/SearchTools` (`search`), `Tools/DossierTools` (`get_dossier`), `Tools/ProvenanceSchema` (gemeinsame Herkunftsfelder im Eingabeschema); `Tools/DocumentTools` (`request_upload`, `store_document` mit Datei-Parameter, `import_document_from_url`, `attach_document`, `get_document`, `list_documents`; `get_event`/`get_vehicle` hängen `documents` per `DocumentService::forTarget`/`forVehicle` an), `Tool` (optionales `meta` → `_meta` in `tools/list`), `ImageBlocks` (Bildblöcke für `get_document`); `Auth/McpAuth` (Token → Nutzer); `mcp/instructions.md` (Server-Anweisungen), `mcp/leitfaden-erfassung.md` und `mcp/leitfaden-herkunft.md` (als Resources `garage://leitfaden/…`) |
| `migrations/` | nummerierte SQL-Dateien (Spalten: [`models.md`](models.md)) |
| `storage/` | `logs/`, `documents/` — nur auf dem Server und lokal, nicht im Git |

## Ordner in `web/`

| Ordner | Aufgabe |
|---|---|
| `js/` | ES-Module: `api.js` (Access-Token im Speicher, Refresh bei 401, `login`/`logout`/`restore`), `dom.js` (Bauhelfer, Fehlermeldung mit Fokus), `account.js` (Einstieg `index.html`), `login.js` (Anmeldeformular, geteilt), `token.js` (feste Zugänge), `grant.js` (verbundene Apps), `openitem.js` (Klärfälle im Konto: listen, lösen mit Bestätigung), `invitation.js` (Einstieg `einladung.html`), `consent.js` (Einstieg `zustimmung.html`) |
| `css/` | `base.css`: funktionaler Platzhalter (Systemschrift, eine Spalte, hell/dunkel) bis zum Oberflächen-Meilenstein |

## Faustregeln

- Fachliche Funktion? → zuerst `Domain/<X>Service.php`, dann als Tool in `Mcp/Tools/<X>Tools.php`; die Web-API ruft denselben Service.
- Kennt die Funktion HTTP? → gehört in `Mcp/`, `Api/` oder `OAuth/`, nie in `Domain/`.
- Datenbankabfrage? → nur im Repository, immer mit Akteur und dessen `garage_id`s.
- Berechneter Wert (Fälligkeit, Prognose, Unterhalt)? → beim Lesen im Service, nie als Spalte.
- Neue Adresse an der Domain-Wurzel? → Änderung im DISPATCH-Repository (ADR-001), nicht hier.
