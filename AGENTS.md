# AGENTS.md — GARAGE

🚧 Aktive Arbeit → STATE.md

> Wird jede Session geladen. Kurz halten — Details leben in `docs/`.

**GARAGE — Your agents. Your cars.** Fahrzeugakte für mehrere Nutzer in PHP: Agenten (ChatGPT, Claude) erfassen und lesen per MCP mit OAuth, Menschen sehen die Akte im Browser. Läuft unter `https://quantum-canvas.de/garage`, neben DISPATCH, CardMaker und CamScanner.

Kontext, Scope, Meilensteine: [`docs/PROJECT.md`](docs/PROJECT.md). Fachkonzept: [`docs/design/konzept-garage.md`](docs/design/konzept-garage.md). Vokabular: [`docs/glossary.md`](docs/glossary.md).

---

## Code finden — erst hier, dann suchen

[`docs/code-map.md`](docs/code-map.md) zeigt Namensschema, Repo → Server und Faustregeln. Erst dort nachsehen, dann gezielt öffnen. Routen: [`docs/routes.md`](docs/routes.md), Tabellen: [`docs/models.md`](docs/models.md).

---

## Stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.5, kein Framework, kein Composer |
| Datenbank | MySQL (Strato, eigene Datenbank), PDO |
| Oberfläche | HTML-Hülle, Vanilla-JS-ES-Module gegen `/garage/api` |
| Agenten | MCP-Server mit OAuth 2.1 + CIMD, aus DISPATCH übernommen ([ADR-005](docs/decisions/005-kern-aus-dispatch-kopiert.md)) |
| Hosting | Strato Shared Hosting, SFTP auf `www/` |
| Deploy | `deploy.cmd` + WinSCP ([ADR-003](docs/decisions/003-deploy-skript.md)) |
| CI | GitHub Actions, PHP-Syntaxprüfung |

---

## Lokal starten

PHP 8.5 (Erweiterungen `curl, fileinfo, gd, mbstring, openssl, pdo_mysql`) und MySQL mit Datenbank `garage_dev`. `dev.env.example` nach `app/.env.local` kopieren und ausfüllen, dann `php -S localhost:8081 dev-router.php` im Repo-Ordner. Prüfen: `curl http://localhost:8081/garage/api/health`. Migrationen lokal: `curl -X POST -H "Authorization: Bearer <GARAGE_DEPLOY_TOKEN>" http://localhost:8081/garage/api/deploy`.

---

## Conventions Index

| Topic | File |
|---|---|
| PHP | [`docs/conventions/php.md`](docs/conventions/php.md) |
| Frontend | [`docs/conventions/frontend.md`](docs/conventions/frontend.md) |
| Linting | [`docs/conventions/linting.md`](docs/conventions/linting.md) |
| Commits | [`docs/conventions/commits.md`](docs/conventions/commits.md) |
| Definition of Done | [`docs/conventions/dod.md`](docs/conventions/dod.md) |

---

## Critical Rules

1. **Nur `/garage/` und `/garage-app/` gehören GARAGE.** Wurzel, `/dispatch*`, `/card/`, `/api/`, `/backend/`, `/scanner/` sind Nachbarn; `deploy.cmd` gleicht nie die Wurzel ab. Die Wurzel-`.htaccess` gehört DISPATCH — Änderungen daran im DISPATCH-Repository ([ADR-001](docs/decisions/001-pfade-auf-gemeinsamer-domain.md)).
2. **`garage-app/` ist gesperrt und enthält `.env` und Belege.** Nach jedem Deploy muss `/garage-app/.env` mit 403 antworten ([ADR-002](docs/decisions/002-code-im-webroot-gesperrt.md)).
3. **Keine Abfrage ohne Akteur.** Jede Faktentabelle trägt `garage_id`, jedes Repository hängt die Bedingung selbst an.
4. **Herkunft ist Pflicht, Widersprüche werden Klärfälle.** Nichts überschreibt einen bestätigten Wert, nichts wird hart gelöscht.
5. **Agenten-Tokens können nicht löschen, übergeben, Mitglieder verwalten oder Klärfälle lösen.**
6. **`docs/PROJECT.md` und die ADRs schlagen das Fachkonzept**, wo beide sich widersprechen.

---

## Wo du mehr findest

- **Architektur-Entscheidungen:** [`docs/decisions/`](docs/decisions/)
- **Aktive Planung:** [`docs/planning/`](docs/planning/) · **Archiv:** [`docs/archive/`](docs/archive/)
- **Projektwissen und Lücken:** [`docs/knowledge/`](docs/knowledge/) · **Projekt-Memory:** [`docs/memory/memory-index.md`](docs/memory/memory-index.md)
