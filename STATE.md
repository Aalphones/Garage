# STATE

**Aktiver Plan:** [`docs/planning/2026-11-16_konten-mcp-oauth/`](docs/planning/2026-11-16_konten-mcp-oauth/README.md) — Meilenstein 1, freigegeben 2026-11-16
**Nächster Schritt:** Phase 1 „Konten und Sitzung“ umsetzen.

**Erledigt 2026-11-16:** Erster Deploy auf Strato (Datenbank verbunden, Rauchtest grün), danach DISPATCH mit Wurzel-`.htaccess`: `/garage/` trägt die eigene CSP, `/.well-known/oauth-protected-resource/garage/mcp` erreicht GARAGEs Brücke (404 bis Meilenstein 1), `robots.txt` sperrt `/garage/` und `/garage-app/`.
