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
| GET | `/garage/api/tokens` | `Api\TokenController::list` | JWT | `{tokens:[{id,name,scopes,expires_at,last_used_at,created_at}]}` — nur feste Tokens des Nutzers, ohne widerrufene, nie Klartext oder Hash |
| POST | `/garage/api/tokens` | `Api\TokenController::create` | JWT | 201 `{token,id,name,scopes,expires_at}` — `token` (`gmcp_` + 64 Hex) nur in dieser Antwort; Body `name` (`^[a-z0-9][a-z0-9-]{0,39}$`), `scopes` (Standard `garage:read garage:write`), `expires_days` (1–3650 oder `null`); 409 `name_taken` (je Nutzer unter den aktiven), 422 `invalid_value` |
| DELETE | `/garage/api/tokens/{id}` | `Api\TokenController::revoke` | JWT | 204; fremdes, unbekanntes oder schon widerrufenes Token → 404 `not_found` (nie 403) |

| GET | `/.well-known/oauth-protected-resource/garage/mcp` | `OAuth\DiscoveryController::protectedResource` | keiner | `resource` `https://quantum-canvas.de/garage/mcp`, `authorization_servers` `[…/garage]`, drei `garage:`-Scopes, `resource_name` `GARAGE`; 5 min zwischenspeicherbar |
| GET | `/.well-known/oauth-authorization-server/garage` | `OAuth\DiscoveryController::authorizationServer` | keiner | Aussteller `https://quantum-canvas.de/garage`, Endpunkte unter `/garage/oauth/`, `client_id_metadata_document_supported`, nur `none` als Client-Anmeldung |
| GET | `/garage/oauth/authorize` | `OAuth\AuthorizeController::handle` | keiner | 302 auf `/garage/zustimmung.html?anfrage=<32 hex>`; kaputter Client oder Rückadresse → 302 auf `…?fehler=unbekannte_app\|app_nicht_erreichbar\|falsche_rueckadresse`, nie an die Rückadresse; Fehler in PKCE, `resource`, Scope → 302 mit `error` an die geprüfte Rückadresse |
| POST | `/garage/oauth/token` | `OAuth\TokenController::token` | Client (öffentlich, PKCE) | `application/x-www-form-urlencoded`; `authorization_code` und `refresh_token` → `{access_token (goa_), token_type, expires_in, scope, refresh_token (gor_)}`; Fehler `{error, error_description}` (`invalid_grant`, `invalid_target`, `invalid_scope`, …); Code doppelt → ganze Familie widerrufen; gesperrter Nutzer → `invalid_grant` |
| POST | `/garage/oauth/revoke` | `OAuth\TokenController::revoke` | Besitz des Tokens | 200 immer; widerruft die Familie |
| GET | `/garage/api/oauth/requests/{slug}` | `Api\OAuthController::request` | JWT | `{request_id, client:{name,host,kind}, redirect_host, loopback, scopes, offline, expires_in}`; 404 `not_found`, 410 `request_expired` |
| POST | `/garage/api/oauth/consent` | `Api\OAuthController::consent` | JWT | Body `request_id`, `decision` (`allow`\|`deny`), `scopes`; `{redirect}` an die App; 410 `request_expired` (auch beim zweiten Mal), 422 `invalid_value` (Scope nicht angefragt oder keiner gewählt); schreibt `oauth_codes.user_id` |
| GET | `/garage/api/oauth/grants` | `Api\OAuthController::grants` | JWT | `{grants:[{family,client_name,client_host,scopes,connected_at,last_used_at}]}` — nur eigene |
| DELETE | `/garage/api/oauth/grants/{slug}` | `Api\OAuthController::disconnect` | JWT | 204; fremde oder unbekannte Familie → 404 `not_found` |

Ein `gmcp_`-Token als Bearer an diesen Routen ergibt 401: sie nehmen nur Access-JWTs.

Ohne `GARAGE_JWT_SECRET` (mindestens 64 Zeichen) antworten Anmeldung und Annahme mit 503 `not_configured`.

Fehler immer als JSON `{"error": "…", "message": "…"}`; unbekannter Pfad 404 `not_found`, falsche Methode 405 mit `Allow`.

## MCP-Endpunkt

| Methode | Pfad | Controller | Schutz | Antwort |
|---|---|---|---|---|
| POST | `/garage/mcp` | `McpMcpController::handle` | `gmcp_`- oder `goa_`-Token als Bearer; Nutzer muss `aktiv` sein | JSON-RPC 2.0, eine Nachricht je Anfrage (kein Batch); ohne `id` → 202; ohne Token 401 mit `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource/garage/mcp", scope="…"` (bei ungültigem Token zusätzlich `error="invalid_token"`); fremder `Origin` → 403; Token ohne Scope für ein Tool → 403, Fehler `-32003`, `insufficient_scope` |

Methoden: `initialize` (ältere Clients), `server/discover` (2026-07-28), `ping`, `tools/list` (nur Tools der Scopes des Tokens), `tools/call`, `resources/list`/`templates/list`/`read` und `prompts/list`/`get` (Kataloge leer, brauchen `garage:read`).

| Tool | Scope | Eingabe | Ausgabe (Kern) |
|---|---|---|---|
| `list_vehicles` | read | — | `{garages:[{id,name,role,vehicles:[{id,label,status,marke,modell,fin,open_items}]}]}`, auch leere Garagen |
| `get_vehicle` | read | `vehicle_id` | `{vehicle:{id,garage_id,label,status}, facts:{<feld>:{value,source,source_url,confidence,actor,recorded_at}}, disputed:[{field,value,source,confidence,open_item_id}]}`; Ganzzahlfelder als Zahl |
| `create_vehicle` | write | `label`, optional `garage_id`, optional `facts` | `{vehicle_id,garage_id,label,facts:[…]}`; mehrere beschreibbare Garagen ohne `garage_id` → `invalid_value` |
| `set_vehicle_facts` | write | `vehicle_id`, `facts:[{field,value,source,source_url?,confidence}]` | `{vehicle_id,facts:[{field,value,result,open_item_id?}]}`, `result` = `angelegt`|`aktualisiert`|`unveraendert`|`klaerfall` |

Tool-Fehler kommen als Ergebnis mit `isError: true` und `{error,message,details}`: `not_found` (fremdes oder unbekanntes Fahrzeug/Garage), `forbidden` (Rolle `lesend`), `invalid_value` (mit Feldname und Beispiel), `invalid_argument` (Pflichtargument fehlt oder falscher Typ).
