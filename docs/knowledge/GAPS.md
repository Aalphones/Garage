# Lücken — GARAGE

Offene Recherche- und Klärungsfragen mit Datum.

- 2026-11-16 — Meilenstein-Grafik M0–M7 des Konzepts fehlt im Markdown (nur eingebettete Grafik).
- 2026-11-17 — Findet claude.ai (und ChatGPT) den Aussteller mit Pfad `https://quantum-canvas.de/garage`? Der Server liefert beide Discovery-Dokumente unter den Pfad-Adressen nach RFC 8414/9728 (per curl belegt), die echte Verbindung steht aus. Schlägt sie fehl: Discovery-Abrufe werden nicht protokolliert; Fallbacks sind `/.well-known/openid-configuration/garage` (antwortet 404) und `/.well-known/oauth-protected-resource` ohne Pfad (gehört DISPATCH). Details: `docs/archive/2026-11/2026-11-16_konten-mcp-oauth/FINDINGS.md`.
- 2026-11-17 — Die Zustimmungsseite (Anmeldung, Host der App, Scope-Auswahl) ist nur per API belegt, nicht im Browser mit einer echten App.
- 2026-11-16 — Ob ChatGPT auf dem Handy Datei-Parameter an MCP-Tools zuverlässig übergibt (im Konzept 🟡); klärt die Verprobung in Meilenstein 3.
