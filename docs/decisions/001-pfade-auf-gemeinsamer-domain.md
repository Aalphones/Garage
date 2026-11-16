# 001 — Pfade auf der gemeinsamen Domain

**Status:** Akzeptiert (2026-11-16)

## Kontext

GARAGE läuft unter `https://quantum-canvas.de/garage`. Im selben Webroot `www/` liegen schon DISPATCH (Wurzel, `/dispatch/`, `/dispatch-app/`), CardMaker (`/card/`, `/api/`, `/backend/`) und CamScanner (`/scanner/`). Strato stellt in diesem Paket kein Zertifikat für Subdomains aus. Die Wurzel-`.htaccess` gehört DISPATCH (dort ADR-003) und vererbt ihre Regeln in alle Unterordner.

## Entscheidung

```
www/
  garage/       ← Brücke (index.php) und Oberfläche (HTML, JS, CSS) — einziger Einstieg
  garage-app/   ← Programmcode, Migrationen, .env, storage/ — gesperrt (ADR-002)
```

- MCP-Endpunkt `…/garage/mcp`, Web-API `…/garage/api/*`, OAuth-Server `…/garage/oauth/*`.
- OAuth-Aussteller (`issuer`) ist `https://quantum-canvas.de/garage`, nicht die Wurzel — die gehört DISPATCHs Aussteller. Die Discovery-Pfade mit Pfad-Suffix (RFC 9728, RFC 8414) liegen an der Wurzel: `/.well-known/oauth-protected-resource/garage/mcp`, `/.well-known/oauth-authorization-server/garage`, `/.well-known/openid-configuration/garage`. DISPATCHs Wurzel-`.htaccess` leitet alles unter `/.well-known/<art>/garage…` auf `garage/index.php` um, vor ihrer eigenen OAuth-Regel.
- `/garage/` und `/garage-app/` stehen in DISPATCHs reservierten Namen und in den Ausnahmen der Wurzel-`.htaccess`; Blog-CSP, `ErrorDocument` und Cache-Zeiten greifen hier nicht. Eigene Header setzt `web/.htaccess`.
- GARAGE lädt nie etwas in die Wurzel und ändert die Wurzel-`.htaccess` nie selbst. Änderungen daran laufen über das DISPATCH-Repository.

## Konsequenzen

- Kommt ein Pfad an der Wurzel dazu (etwa ein Web-App-Manifest), braucht es eine Änderung im DISPATCH-Repository und dessen Deploy.
- Sobald Fremde Konten bekommen, ist eine eigene Domain zu prüfen (Konzept → „Offene Entscheidungen").
