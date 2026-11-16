# Routen — GARAGE

Quelle: `app/index.php`. Alle Pfade absolut; die Brücke liegt unter `/garage/` (ADR-001).

| Methode | Pfad | Controller | Schutz | Antwort |
|---|---|---|---|---|
| GET | `/garage/api/health` | `Api\HealthController::show` | keiner | `{"status":"ok"}` |
| POST | `/garage/api/deploy` | `Api\DeployController::handle` | Deploy-Token (`Authorization: Bearer` oder `X-Garage-Token`) | `{"migrations":[…]}`; 401 ohne Token, 500 `deploy_failed` mit Schrittnamen |

Fehler immer als JSON `{"error": "…", "message": "…"}`; unbekannter Pfad 404 `not_found`, falsche Methode 405 mit `Allow`.

## Vorgesehen (Meilenstein 1)

- `POST /garage/mcp` — MCP-Endpunkt
- `/garage/oauth/{authorize,token,revoke}` — OAuth-Server, Aussteller `https://quantum-canvas.de/garage`
- `GET /.well-known/oauth-protected-resource/garage/mcp`, `/.well-known/oauth-authorization-server/garage` — Discovery, von DISPATCHs Wurzel-`.htaccess` an die Brücke geleitet
- `/garage/api/auth/*`, `/garage/api/<x>` — Anmeldung und Web-API
