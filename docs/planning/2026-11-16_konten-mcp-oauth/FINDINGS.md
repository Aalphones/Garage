# Findings — Meilenstein 1

Format: `- [ ] → Phase N: <Erkenntnis>` — offen, bis die betroffene Phase sie eingearbeitet hat.

- [ ] → Phase 3: Die Zustimmungsseite `zustimmung.html` kann `web/js/api.js` (`restore`, `login`, `request`, `ApiError`) und `dom.js` (`showError` mit Fokus) wiederverwenden; Seiten bleiben Dateien direkt in `web/`, Skripte unter `web/js/`, Stil `web/css/base.css`. Kein Inline-Skript, kein `style=`-Attribut (CSP `style-src 'self'`) — Sichtbarkeit nur über `hidden` und Klassen.
- [ ] → Phase 4: `TokenService::authenticate(plain)` (liefert `id, user_id, name, scopes`, setzt `last_used_at`) ist gebaut, aber noch nie aufgerufen; mit dem ersten MCP-Aufruf auf Strato prüfen, danach `Actor::forUser(user_id, 'mcp', 'mcp:<name>', scopes, $pdo)` bilden und den Nutzerstatus `aktiv` prüfen (ein gesperrter Nutzer darf mit seinem festen Token nicht weiterarbeiten — `authenticate` prüft das selbst nicht).
