# Phase 3 — OAuth und Zustimmung

Rating: **heikel** (Sicherheit, Umbau von einem Inhaber auf viele Nutzer).

## Kontext — vorher lesen

- [`README.md`](README.md) → E6 (nur CIMD), Kontrakt (Präfixe `goa_`/`gor_`, Scopes, Routen Phase 3), Risiko „Discovery mit Pfad im Aussteller“.
- DISPATCH ADR-024, ADR-025, ADR-027 (`C:\Users\sasch\develop\Blog\docs\decisions\`) — warum CIMD für jede App, warum Refresh-Token.
- DISPATCH-Vorlagen (`C:\Users\sasch\develop\Blog\app\`): `src/OAuth/*` (alle zehn Dateien), `src/Util/HttpClient.php`, `src/Util/HttpResponse.php`, `src/Api/OAuthController.php`, `src/Domain/TokenRepository.php` (OAuth-Methoden), `migrations/014_oauth.sql`, `config.php` → Schlüssel `oauth`, `theme/redaktion/zustimmung.js`.
- Ergebnis Phasen 1–2: `Actor`, `ApiAuth::user`, `TokenRepository`, `web/js/api.js` (Anmeldung, `restore()`), `web/css/base.css`.
- Wurzel-`.htaccess` von DISPATCH leitet `/.well-known/<art>/garage…` schon an GARAGEs Brücke (STATE.md, 2026-11-16) — hier nichts an der Wurzel ändern (ADR-001).

## Abnahmekriterien

- [ ] `GET https://quantum-canvas.de/.well-known/oauth-protected-resource/garage/mcp` → `resource` = `https://quantum-canvas.de/garage/mcp`, `authorization_servers` = `["https://quantum-canvas.de/garage"]`, `scopes_supported` = drei `garage:`-Scopes, `resource_name` = `GARAGE`.
- [ ] `GET https://quantum-canvas.de/.well-known/oauth-authorization-server/garage` → `issuer` = `https://quantum-canvas.de/garage`, Endpunkte `…/garage/oauth/authorize|token|revoke`, `client_id_metadata_document_supported: true`.
- [ ] `authorize` mit gültiger CIMD-`client_id`, PKCE S256 → 302 auf `/garage/zustimmung.html?anfrage=<32 hex>`; kaputter Client oder Rückadresse → 302 auf `/garage/zustimmung.html?fehler=…`, nie an die Rückadresse.
- [ ] Zustimmungsseite ohne Sitzung zeigt erst die Anmeldung (dasselbe Formular wie `/garage/`), danach die Anfrage: App-Name, **Host der App** hervorgehoben, Rückadresse-Host, Häkchen je angefragtem Scope mit Erklärung, Hinweis „Bleibt verbunden, bis du trennst“ bei `offline_access`, Knöpfe „Erlauben“ und „Ablehnen“, angemeldet als `<Name>` mit „Anderes Konto“.
- [ ] „Erlauben“ setzt `oauth_codes.user_id` = angemeldeter Nutzer; der Token-Tausch legt `goa_`/`gor_` mit derselben `user_id` an. Code doppelt eingelöst → ganze Familie widerrufen (wie DISPATCH).
- [ ] `GET /garage/api/oauth/grants` listet nur die Verbindungen des angemeldeten Nutzers; `DELETE …/grants/{family}` einer fremden Familie → 404.
- [ ] Konto-Seite `/garage/` hat den Abschnitt „Verbundene Apps“ (App-Name, Host, Scopes, verbunden seit, zuletzt genutzt, Knopf „Trennen“).
- [ ] `resource` ungleich `https://quantum-canvas.de/garage/mcp` → `invalid_target`.

## Checkliste

### Datenbank
- [ ] `app/migrations/005_oauth.sql` aus DISPATCH `014_oauth.sql`: `oauth_clients.kind` ENUM('cimd'); `secret_hash` entfällt; `oauth_codes` zusätzlich `user_id` INT UNSIGNED NULL FK→users (gesetzt beim Übergang `pending → approved`).

### Kopieren und umbauen
- [ ] `app/src/Util/HttpClient.php`, `HttpResponse.php` unverändert bis auf Namespace (SSRF-Schutz bleibt Grenze für CIMD-Abrufe).
- [ ] `app/config.php`: Schlüssel `oauth` aus DISPATCH, `default_scopes` = `['garage:read','garage:write']`.
- [ ] `OAuth/ClientRegistry.php`: alles zu manuellen Clients streichen (`createManual`, `listManual`, `revokeManual`, `resolveManual`, Präfixe `dcl_`/`dcs_`); `resolve` kennt nur CIMD.
- [ ] `OAuth/Discovery.php`: `RESOURCE_PATH` `/garage/mcp`, `METADATA_PATH` `/.well-known/oauth-protected-resource/garage/mcp`, Endpunkte unter `/garage/oauth/`, `issuer` = `base_url` + `/garage`, `resource_name` `GARAGE`, `token_endpoint_auth_methods_supported` = `['none']`; `CHALLENGE_SCOPE` = `garage:read garage:write`. `base()` liefert weiter nur die Domain; den Aussteller baut eine neue Methode `issuer()`.
- [ ] `OAuth/AuthorizeController.php`: `CONSENT_PATH` = `/garage/zustimmung.html`, Scope-Prüfung gegen `garage:`-Scopes, `iss` = `Discovery::issuer()`.
- [ ] `OAuth/Consent.php`: `decide(...)` verlangt einen Akteur mit `via = 'web'` und schreibt dessen `userId` per `OAuthRepository::transition(..., userId)` in die Anfrage; `iss` = `issuer()`.
- [ ] `OAuth/TokenIssuer.php`: Präfixe `goa_`/`gor_`, `authenticateClient` nur `none` (öffentliche Clients mit PKCE); `issuePair` und `refresh` übernehmen `user_id` aus Code bzw. Refresh-Token; `authenticate()` liefert `{id, user_id, name, scopes}` und lehnt ab, wenn der Nutzer `gesperrt` ist; `grants(Actor)`/`disconnect(family, Actor)` nur eigene.
- [ ] `OAuth/OAuthRepository.php`, `OAuthError.php`, `Redirect.php`, `DiscoveryController.php`, `TokenController.php` (als `OAuth/TokenController`) mit Namespace und Pfaden.
- [ ] `TokenRepository`: `insertOAuth(..., userId)`, `findOAuthByHash` und `findActiveOAuthAccess` liefern `user_id`, `listGrants(userId)`, `findOAuthFamily(family, userId)`, `revokeOtherRefresh`, `revokeByClient`.
- [ ] `app/src/Api/OAuthController.php` aus DISPATCH, alle Methoden über `ApiAuth::user`.
- [ ] Routen in `app/index.php` (Tabelle README, Phase 3).

### Oberfläche
- [ ] `web/zustimmung.html` + `web/js/consent.js` nach DISPATCH `zustimmung.js`; Anmeldung über `web/js/api.js`; „Anderes Konto“ = `logout()` und zurück zum Formular.
- [ ] `web/js/grant.js` + Abschnitt „Verbundene Apps“ in `web/js/account.js`.

### Prüfen (auf Strato, nach `deploy.cmd`)
- [ ] Discovery-Antworten per `curl` gegen AK.
- [ ] Echte Verbindung aus claude.ai (Einstellungen → Connectors → eigene URL `https://quantum-canvas.de/garage/mcp`). Erwartet bis Phase 4: Anmeldung und Zustimmung laufen durch, der MCP-Aufruf selbst endet mit 404 — das belegt OAuth, nicht MCP. Schlägt schon die Discovery fehl: im Log nachsehen, welche `/.well-known/…`-Adresse abgefragt wurde, und in `FINDINGS.md` festhalten.
- [ ] Abweisungen: fremde Familie trennen → 404; Code zweimal tauschen → `invalid_grant` und Familie widerrufen; falsche `resource` → `invalid_target`.

### Doku
- [ ] `docs/models.md`, `docs/routes.md`, `docs/code-map.md` (`OAuth/`, `Util/HttpClient`).

## Report-Back
