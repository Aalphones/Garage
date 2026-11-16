# Phase 2 — Konto-Seiten und feste Tokens

Rating: **standard** (Muster aus DISPATCH, Kontrakt steht).

## Kontext — vorher lesen

- [`README.md`](README.md) → E7 (funktionale Platzhalter, Seiten als Dateien direkt in `web/`), Kontrakt (Präfix `gmcp_`, Scopes, Routen Phase 2).
- `docs/conventions/frontend.md` (CSP nur `'self'`, kein Inline-Skript, Access-Token nur im Arbeitsspeicher, 360 px, hell/dunkel, a11y-Regeln), `docs/conventions/dod.md`.
- `web/.htaccess` (vorhandene Dateien werden direkt ausgeliefert, alles andere geht an die Brücke — deshalb keine Unterordner für Seiten).
- Ergebnis Phase 1: `Auth/ApiAuth.php`, `Domain/Actor.php`, `Domain/TokenRepository.php`, `Api/AuthController.php`, Routen `/garage/api/auth/*`, `/garage/api/me`, `/garage/api/invitations/*`.
- DISPATCH-Vorlagen (`C:\Users\sasch\develop\Blog\app\`): `src/Domain/TokenService.php`, `src/Api/TokenController.php` (nur `issue`, `list`, `revokeById`; der Kommandozeilenweg `handleDeploy` entfällt), `theme/redaktion/api.js`, `theme/redaktion/sitzung.js`, `theme/redaktion/token.js`, `theme/redaktion/konto.js`.

## Abnahmekriterien

- [ ] `GET /garage/api/tokens` (JWT) → `{tokens:[{id,name,scopes,expires_at,last_used_at,created_at}]}` nur des angemeldeten Nutzers, ohne widerrufene.
- [ ] `POST /garage/api/tokens` `{name, scopes, expires_days?}` → 201 mit Klartext `token` (`gmcp_` + 64 Hex) genau einmal; Name `^[a-z0-9][a-z0-9-]{0,39}$`, eindeutig **je Nutzer** unter den aktiven (sonst 409 `name_taken`); Standard-Scopes `garage:read garage:write`.
- [ ] `DELETE /garage/api/tokens/{id}` → 204; ein fremdes oder unbekanntes Token → 404 `not_found` (nie 403, damit fremde IDs nicht erkennbar sind).
- [ ] `/garage/` ohne Sitzung: Anmeldeformular (E-Mail, Passwort, Knopf „Anmelden“); Fehlermeldung aus der API sichtbar neben dem Formular, Fokus springt dorthin.
- [ ] `/garage/` mit Sitzung: Name, Liste der Garagen mit Rolle, Abschnitt „Feste Zugänge“ (Liste, Formular zum Anlegen mit Name, Häkchen je Scope mit Erklärung, Laufzeit in Tagen leer = unbegrenzt), Knöpfe „Widerrufen“ und „Abmelden“. Nach dem Anlegen zeigt ein Kasten den Klartext mit Knopf „Kopieren“ und dem Satz „Wird nur jetzt angezeigt.“
- [ ] Jede Einstellung (Scope-Häkchen, Laufzeit) hat eine kurze Erklärung direkt daneben (`<small>` oder `title`-freies `?`-Element mit sichtbarem Text beim Fokus) — z. B. `garage:write`: „Darf Fahrzeuge anlegen und Daten ergänzen. Löschen kann ein Agent nie.“
- [ ] `/garage/einladung.html#<token>`: zeigt Garage und Rolle aus `preview`, Formular Name, E-Mail, Passwort (min. 12 Zeichen, Hinweis sichtbar), nach Erfolg weiter auf `/garage/`; abgelaufene/benutzte Einladung → verständliche Meldung statt Formular.
- [ ] Neu laden von `/garage/` hält die Sitzung (Refresh-Cookie), „Abmelden“ beendet sie.
- [ ] 360 px ohne waagerechtes Scrollen, hell und dunkel (über `prefers-color-scheme`), keine Konsolen-Fehler, keine CSP-Verstöße.

## Checkliste

### Backend
- [ ] `app/src/Domain/TokenService.php` aus DISPATCH: `create(string $name, array|string $scopes, ?int $expiresDays, Actor $actor)` speichert `user_id = $actor->userId`; `list(Actor)`, `revoke(int $id, Actor)` nur eigene; `authenticate(string $plain): ?array{id, user_id, name, scopes}` (für Phase 4). Präfix `gmcp_`, Länge 69.
- [ ] `TokenRepository` um die passenden Methoden aus DISPATCH ergänzen, jede mit `user_id`-Bedingung: `insert`, `findActiveByHash` (liefert `user_id`), `activeNameExists(userId, name)`, `listMcp(userId)`, `revoke(id, userId): bool`, `touch(id)`.
- [ ] `app/src/Api/TokenController.php`: `create`, `list`, `revokeById` — alle über `ApiAuth::user`.
- [ ] Routen in `app/index.php`.

### Oberfläche (funktionale Platzhalter)
- [ ] `web/css/base.css`: Systemschrift, eine Spalte max. 40 rem, Formularelemente volle Breite, sichtbarer Fokusrahmen, `prefers-color-scheme: dark` mit zwei Farben — keine Gestaltung darüber hinaus.
- [ ] `web/js/api.js` (nach `api.js` + `sitzung.js`): Access-Token im Modul-Speicher, `request(method, path, body)` mit automatischem einmaligem `refresh` bei 401, `login`, `logout`, `restore()` (Refresh beim Laden).
- [ ] `web/js/token.js`: Liste, Anlegen, Widerrufen (nach `token.js`).
- [ ] `web/js/account.js`: Einstieg für `index.html` — `restore()`, dann Anmeldeformular oder Konto-Ansicht.
- [ ] `web/js/invitation.js`: Einstieg für `einladung.html`; Token aus `location.hash`, danach `history.replaceState` ohne Hash.
- [ ] `web/index.html` umbauen (Sprunglink, genau eine `h1` „GARAGE“, `<main>`, Skript als `type="module"`), `web/einladung.html` neu.

### Prüfen (auf Strato, nach `deploy.cmd`)
- [ ] AK im Browser am Handy-Format (Entwicklerwerkzeuge 360 px) und per `curl` für die API, inklusive: Nutzer B widerruft Token von Nutzer A → 404.

### Doku
- [ ] `docs/routes.md`, `docs/code-map.md` (`web/js/`, `web/css/`).

## Report-Back
