# 002 — Code im Webroot, gesperrt

**Status:** Akzeptiert (2026-11-16)

## Kontext

Der SFTP-Zugang sieht nur `www/`. Ein Ordner über dem Webroot steht nicht zur Verfügung; Code, `.env`, Migrationen und hochgeladene Belege müssen also im ausgelieferten Bereich liegen.

## Entscheidung

- `app/` geht nach `www/garage-app/`. Dessen `.htaccess` sperrt jeden Aufruf (`Require all denied`).
- `web/` geht nach `www/garage/`. Die Brücke `index.php` bindet `../garage-app/index.php` per Dateizugriff ein; die Sperre betrifft nur HTTP-Aufrufe.
- Zugangsdaten stehen nur in `www/garage-app/.env`, geschrieben von `deploy.cmd` aus der lokalen `deploy.env`.
- Belege und Fotos liegen unter `garage-app/storage/` und werden nur über die App ausgeliefert (später per signiertem Link mit kurzer Laufzeit).

## Konsequenzen

- Nach jedem Deploy muss `/garage-app/.env` mit 403 antworten; der Rauchtest prüft das zusammen mit `/garage-app/config.php`.
- Fällt die Sperre aus, liegen Zugangsdaten und private Belege offen. Deshalb bricht der Rauchtest bei einem anderen Status als 403 als Fehler ab.
