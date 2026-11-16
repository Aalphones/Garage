# Code-Map — GARAGE

Feature → Ordner. Bewusst grob, keine Zeilennummern. Steht: Gerüst (Bootstrap, Http, Env, Log, Migrator, Brücke, Dev-Router, Deploy) und Konten (Einladung, Anmeldung, Akteur). Übrige Ordner entstehen mit ihren Meilensteinen.

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
| `web/` | `garage/` | Brücke `index.php`, `.htaccess` (Rewrite, eigene CSP), Oberfläche (`index.html`, später `js/`, `css/`) |
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
| `src/Auth/` | `DeployToken`, `Jwt` (Access-JWT), `LoginThrottle`, `UserSession` (Login/Refresh/Logout), `ApiAuth` (JWT → `Actor`, nur Deploy-Token), `Scopes`; feste und OAuth-Tokens folgen |
| `src/Api/` | `HealthController`, `DeployController`, `AuthController`, `InvitationController`, `MeController`, `ApiErrors` (Fehlercode → Status) |
| `src/Domain/` | `DomainError`, `Actor`; Konten und Garagen: `UserRepository`, `GarageRepository`, `InvitationService`/`InvitationRepository`, `TokenRepository` (Sitzungen), `AuditRepository` |
| `src/Util/` | `Env`, `Log` |
| `src/Mcp/`, `src/OAuth/`, `mcp/` | ab Meilenstein 1, aus DISPATCH kopiert (ADR-005) |
| `migrations/` | nummerierte SQL-Dateien (Spalten: [`models.md`](models.md)) |
| `storage/` | `logs/`, `documents/` — nur auf dem Server und lokal, nicht im Git |

## Faustregeln

- Fachliche Funktion? → zuerst `Domain/<X>Service.php`, dann als Tool in `Mcp/Tools/<X>Tools.php`; die Web-API ruft denselben Service.
- Kennt die Funktion HTTP? → gehört in `Mcp/`, `Api/` oder `OAuth/`, nie in `Domain/`.
- Datenbankabfrage? → nur im Repository, immer mit Akteur und dessen `garage_id`s.
- Berechneter Wert (Fälligkeit, Prognose, Unterhalt)? → beim Lesen im Service, nie als Spalte.
- Neue Adresse an der Domain-Wurzel? → Änderung im DISPATCH-Repository (ADR-001), nicht hier.
