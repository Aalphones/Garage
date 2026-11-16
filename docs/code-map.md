# Code-Map — GARAGE

Feature → Ordner. Bewusst grob, keine Zeilennummern. Steht: Gerüst (Bootstrap, Http, Env, Log, Migrator, Brücke, Dev-Router, Deploy), Konten (Einladung, Anmeldung, Akteur), OAuth-Server, MCP-Endpunkt mit Fahrzeugen. Übrige Ordner entstehen mit ihren Meilensteinen.

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
| `src/Auth/` | `DeployToken`, `Jwt` (Access-JWT), `LoginThrottle`, `UserSession` (Login/Refresh/Logout), `ApiAuth` (JWT → `Actor`, nur Deploy-Token), `Scopes` |
| `src/Api/` | `HealthController`, `DeployController`, `AuthController`, `InvitationController`, `MeController`, `TokenController`, `OAuthController` (Zustimmung, verbundene Apps), `ApiErrors` (Fehlercode → Status) |
| `src/Domain/` | `DomainError`, `Actor`; Konten und Garagen: `UserRepository`, `GarageRepository`, `InvitationService`/`InvitationRepository`, `TokenService` (feste `gmcp_`-Tokens), `TokenRepository` (Sitzungen und feste Tokens), `AuditRepository`; Fahrzeuge: `VehicleService` (Validierung, Konfliktregel), `VehicleRepository` (Fahrzeuge und Fakten, immer mit Akteur), `OpenItemRepository` (Klärfälle anlegen) |
| `src/OAuth/` | OAuth-Server aus DISPATCH (ADR-005), nur CIMD-Clients: `Discovery`/`DiscoveryController` (Aussteller mit Pfad `/garage`), `AuthorizeController`, `Consent` (nur Mensch im Browser), `TokenIssuer` (Code-Tausch, Rotation, `authenticate` für den MCP-Endpunkt), `TokenController`, `ClientRegistry` (CIMD-Abruf), `OAuthRepository` |
| `src/Util/` | `Env`, `Log`, `HttpClient`/`HttpResponse` (einziger Weg nach außen, SSRF-Schutz) |
| `src/Mcp/`, `mcp/` | MCP-Schicht aus DISPATCH (ADR-005): `McpController` (Endpunkt `/garage/mcp`, Anmeldung, Methoden), `Protocol` (beide Generationen), `Registry`/`Tool`/`Schema`/`ToolResult`, `Resources`/`Prompts` (leer), `Tools/VehicleTools` (vier Tools); `Auth/McpAuth` (Token → Nutzer); `mcp/instructions.md` (Server-Anweisungen) |
| `migrations/` | nummerierte SQL-Dateien (Spalten: [`models.md`](models.md)) |
| `storage/` | `logs/`, `documents/` — nur auf dem Server und lokal, nicht im Git |

## Ordner in `web/`

| Ordner | Aufgabe |
|---|---|
| `js/` | ES-Module: `api.js` (Access-Token im Speicher, Refresh bei 401, `login`/`logout`/`restore`), `dom.js` (Bauhelfer, Fehlermeldung mit Fokus), `account.js` (Einstieg `index.html`), `login.js` (Anmeldeformular, geteilt), `token.js` (feste Zugänge), `grant.js` (verbundene Apps), `invitation.js` (Einstieg `einladung.html`), `consent.js` (Einstieg `zustimmung.html`) |
| `css/` | `base.css`: funktionaler Platzhalter (Systemschrift, eine Spalte, hell/dunkel) bis zum Oberflächen-Meilenstein |

## Faustregeln

- Fachliche Funktion? → zuerst `Domain/<X>Service.php`, dann als Tool in `Mcp/Tools/<X>Tools.php`; die Web-API ruft denselben Service.
- Kennt die Funktion HTTP? → gehört in `Mcp/`, `Api/` oder `OAuth/`, nie in `Domain/`.
- Datenbankabfrage? → nur im Repository, immer mit Akteur und dessen `garage_id`s.
- Berechneter Wert (Fälligkeit, Prognose, Unterhalt)? → beim Lesen im Service, nie als Spalte.
- Neue Adresse an der Domain-Wurzel? → Änderung im DISPATCH-Repository (ADR-001), nicht hier.
