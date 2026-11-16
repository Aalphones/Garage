# GARAGE

**Your agents. Your cars.**

GARAGE ist eine Fahrzeugakte für mehrere Nutzer. KI-Agenten wie ChatGPT und Claude verbinden sich per MCP und OAuth, erfassen Rechnungen, Kilometerstände und Verträge und lesen die Akte; Menschen sehen sie im Browser — mobil zuerst, mit berechneten Fälligkeiten, Kosten und Wertfaktoren.

Jede Angabe trägt ihre Herkunft (Beleg, Nutzer, Recherche, Agent). Widersprüche überschreiben nichts, sie werden Klärfälle. Läuft in PHP ohne Framework auf Shared Hosting mit MySQL.

## Quickstart

**Voraussetzungen:** PHP 8.5 (`curl, fileinfo, gd, mbstring, openssl, pdo_mysql`), MySQL 8 mit einer leeren Datenbank `garage_dev`.

```bash
cp dev.env.example app/.env.local      # Werte eintragen
php -S localhost:8081 dev-router.php   # im Repo-Ordner
curl http://localhost:8081/garage/api/health
curl -X POST -H "Authorization: Bearer <GARAGE_DEPLOY_TOKEN>" http://localhost:8081/garage/api/deploy   # Migrationen
```

**Deploy (Windows):** `deploy.env.example` nach `deploy.env` kopieren, ausfüllen, `deploy.cmd` doppelklicken. Das Skript lädt nach `www/garage/` und `www/garage-app/`, spielt Migrationen ein und prüft danach eigene Adressen und Nachbarn.

**Prüfen:** Es gibt keine automatisierten Tests; die CI prüft die PHP-Syntax.

Für Agenten und Mitentwickler: [AGENTS.md](AGENTS.md).
