# Routen — GARAGE

Quelle: `app/index.php`. Alle Pfade absolut; die Brücke liegt unter `/garage/` (ADR-001).

| Methode | Pfad | Controller | Schutz | Antwort |
|---|---|---|---|---|
| GET | `/garage/api/health` | `Api\HealthController::show` | keiner | `{"status":"ok"}` |
| POST | `/garage/api/deploy` | `Api\DeployController::handle` | Deploy-Token (`Authorization: Bearer` oder `X-Garage-Token`) | `{"migrations":[…]}`; 401 ohne Token, 500 `deploy_failed` mit Schrittnamen |
| POST | `/garage/api/admin/invitations` | `Api\InvitationController::create` | Deploy-Token | 201 `{"link","expires_at"}`; Body `garage_name` (neue Garage, nur Rolle `inhaber`) **oder** `garage_id`, dazu `role`, optional `email`, `days` (1–30, Standard 7) |
| POST | `/garage/api/invitations/preview` | `Api\InvitationController::preview` | Einladungs-Token im Body | `{"garage_name","role","expires_at"}`; 404 `invitation_not_found`, 410 `invitation_used`/`invitation_expired` |
| POST | `/garage/api/invitations/accept` | `Api\InvitationController::accept` | Einladungs-Token im Body | wie Login samt Cookie; 409 `email_taken`, 422 `invalid_value` (Passwort unter 12 Zeichen), 410 wie oben |
| POST | `/garage/api/auth/login` | `Api\AuthController::login` | — | 200 `{access_token, expires_in, user:{id,name,email}}` + Cookie `garage_refresh` (Pfad `/garage/api/auth`); 401 `invalid_credentials`, 429 ab dem 11. Fehlversuch/15 min |
| POST | `/garage/api/auth/refresh` | `Api\AuthController::refresh` | Cookie, `X-Garage: 1` und passender `Origin` | wie Login; 401 `session_expired`/`session_revoked` |
| POST | `/garage/api/auth/logout` | `Api\AuthController::logout` | Cookie, `X-Garage: 1` | 204 |
| POST | `/garage/api/auth/logout-all` | `Api\AuthController::logoutAll` | JWT | 204; widerruft nur die Sitzungen des angemeldeten Nutzers |
| GET | `/garage/api/me` | `Api\MeController::show` | JWT | `{user:{id,name,email}, garages:[{id,name,role}]}`; 401 ohne gültiges JWT |

Ohne `GARAGE_JWT_SECRET` (mindestens 64 Zeichen) antworten Anmeldung und Annahme mit 503 `not_configured`.

Fehler immer als JSON `{"error": "…", "message": "…"}`; unbekannter Pfad 404 `not_found`, falsche Methode 405 mit `Allow`.

## Vorgesehen (Meilenstein 1)

- `POST /garage/mcp` — MCP-Endpunkt
- `/garage/oauth/{authorize,token,revoke}` — OAuth-Server, Aussteller `https://quantum-canvas.de/garage`
- `GET /.well-known/oauth-protected-resource/garage/mcp`, `/.well-known/oauth-authorization-server/garage` — Discovery, von DISPATCHs Wurzel-`.htaccess` an die Brücke geleitet
- `/garage/api/tokens` — feste Tokens im Konto (Phase 2)
