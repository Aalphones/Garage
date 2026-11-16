# 005 — Kern aus DISPATCH kopiert, nicht geteilt

**Status:** Akzeptiert (2026-11-16)

## Kontext

DISPATCH ([Repository](https://github.com/Aalphones/Dispatch)) hat auf demselben Server erprobte Bausteine: HTTP-Schicht, Migrator, MCP-Protokollschicht und Registry, Tokens und Scopes, OAuth 2.1 mit CIMD und Zustimmungsseite, HTTP-Client mit SSRF-Schutz, Medienablage, Diagramme als Server-SVG. Ohne Composer gibt es keinen bequemen Weg, Code zwischen zwei Repositories zu teilen.

## Entscheidung

- GARAGE kopiert die Bausteine bei Bedarf aus DISPATCH, Namespace `Garage\` statt `Dispatch\`, Umgebungsvariablen `GARAGE_*` statt `DISPATCH_*`, Header `X-Garage-Token`.
- Übernommen beim Aufsetzen: `Http\Request`, `Http\Response`, `Http\Router`, `Db\Connection`, `Db\Migrator`, `Util\Env`, `Util\Log`, `Auth\DeployToken`, `Domain\DomainError`.
- MCP und OAuth folgen mit dem ersten Meilenstein; dort werden sie auf mehrere Konten umgebaut (Token und Zustimmung tragen `user_id`).
- Ein gemeinsamer Kern-Ordner, per Skript in beide Repositories kopiert, wird nach Meilenstein 3 geprüft.

## Konsequenzen

- Fehlerkorrekturen an kopierten Bausteinen müssen in beiden Repositories nachgezogen werden, bis der gemeinsame Kern steht.
