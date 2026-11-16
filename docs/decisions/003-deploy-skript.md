# 003 — Deploy-Skript

**Status:** Akzeptiert (2026-11-16)

## Kontext

Strato bietet kein SSH und keine Kommandozeile. DISPATCH und CardMaker laden per `deploy.cmd` und portablem WinSCP hoch; die Nachbarn im selben Webroot dürfen dabei nicht leiden.

## Entscheidung

- `deploy.cmd` (Doppelklick) liest `deploy.env`, prüft die PHP-Syntax, schreibt `app/.env` und gleicht genau zwei Ordner ab: `app/` → `www/garage-app/` (ohne `storage/` und `.env.local`) und `web/` → `www/garage/`. Löschen beim Abgleich gilt nur innerhalb dieser Ordner; die Wurzel wird nie beschrieben.
- Danach ruft es `POST /garage/api/deploy` mit dem Deploy-Token; der Endpunkt öffnet die Datenbank und spielt neue Migrationen ein.
- Rauchtest: `/garage/` und `/garage/api/health` → 200, `/garage-app/.env` und `/garage-app/config.php` → 403, jede Adresse aus `NEIGHBOR_URLS` → 200.
- Das Deploy-Token besteht nur aus Buchstaben und Ziffern und wird aus `Authorization: Bearer` oder `X-Garage-Token` gelesen, falls Strato den ersten Header verschluckt.
- DISPATCHs Deploy prüft umgekehrt `/garage/` und `/garage/api/health` als Nachbarn und stellt sicher, dass die Blog-CSP dort nicht ankommt.

## Konsequenzen

- Reihenfolge beim ersten Mal: erst GARAGE deployen, dann DISPATCH — sonst meldet DISPATCHs Rauchtest `/garage/` als Fehlschlag.
- Fehlerantworten des Deploy-Endpunkts enthalten keinen Ausnahme-Text; Details stehen im Server-Log unter `garage-app/storage/logs/`.
