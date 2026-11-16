# Phase 2 — Konto-Seiten und feste Tokens

Rating: **standard** (Muster aus DISPATCH, Kontrakt steht).

## Kontext — vorher lesen

- [`README.md`](README.md) → E7 (funktionale Platzhalter, Seiten als Dateien direkt in `web/`), Kontrakt (Präfix `gmcp_`, Scopes, Routen Phase 2).
- `docs/conventions/frontend.md` (CSP nur `'self'`, kein Inline-Skript, Access-Token nur im Arbeitsspeicher, 360 px, hell/dunkel, a11y-Regeln), `docs/conventions/dod.md`.
- `web/.htaccess` (vorhandene Dateien werden direkt ausgeliefert, alles andere geht an die Brücke — deshalb keine Unterordner für Seiten).
- Ergebnis Phase 1: `Auth/ApiAuth.php`, `Domain/Actor.php`, `Domain/TokenRepository.php`, `Api/AuthController.php`, Routen `/garage/api/auth/*`, `/garage/api/me`, `/garage/api/invitations/*`.
- DISPATCH-Vorlagen (`C:\Users\sasch\develop\Blog\app\`): `src/Domain/TokenService.php`, `src/Api/TokenController.php` (nur `issue`, `list`, `revokeById`; der Kommandozeilenweg `handleDeploy` entfällt), `theme/redaktion/api.js`, `theme/redaktion/sitzung.js`, `theme/redaktion/token.js`, `theme/redaktion/konto.js`.

## Abnahmekriterien

- [x] `GET /garage/api/tokens` (JWT) → `{tokens:[{id,name,scopes,expires_at,last_used_at,created_at}]}` nur des angemeldeten Nutzers, ohne widerrufene.
- [x] `POST /garage/api/tokens` `{name, scopes, expires_days?}` → 201 mit Klartext `token` (`gmcp_` + 64 Hex) genau einmal; Name `^[a-z0-9][a-z0-9-]{0,39}$`, eindeutig **je Nutzer** unter den aktiven (sonst 409 `name_taken`); Standard-Scopes `garage:read garage:write`.
- [x] `DELETE /garage/api/tokens/{id}` → 204; ein fremdes oder unbekanntes Token → 404 `not_found` (nie 403, damit fremde IDs nicht erkennbar sind).
- [x] `/garage/` ohne Sitzung: Anmeldeformular (E-Mail, Passwort, Knopf „Anmelden“); Fehlermeldung aus der API sichtbar neben dem Formular, Fokus springt dorthin.
- [x] `/garage/` mit Sitzung: Name, Liste der Garagen mit Rolle, Abschnitt „Feste Zugänge“ (Liste, Formular zum Anlegen mit Name, Häkchen je Scope mit Erklärung, Laufzeit in Tagen leer = unbegrenzt), Knöpfe „Widerrufen“ und „Abmelden“. Nach dem Anlegen zeigt ein Kasten den Klartext mit Knopf „Kopieren“ und dem Satz „Wird nur jetzt angezeigt.“
- [x] Jede Einstellung (Scope-Häkchen, Laufzeit) hat eine kurze Erklärung direkt daneben (`<small>` oder `title`-freies `?`-Element mit sichtbarem Text beim Fokus) — z. B. `garage:write`: „Darf Fahrzeuge anlegen und Daten ergänzen. Löschen kann ein Agent nie.“
- [x] `/garage/einladung.html#<token>`: zeigt Garage und Rolle aus `preview`, Formular Name, E-Mail, Passwort (min. 12 Zeichen, Hinweis sichtbar), nach Erfolg weiter auf `/garage/`; abgelaufene/benutzte Einladung → verständliche Meldung statt Formular.
- [x] Neu laden von `/garage/` hält die Sitzung (Refresh-Cookie), „Abmelden“ beendet sie.
- [x] 360 px ohne waagerechtes Scrollen, hell und dunkel (über `prefers-color-scheme`), keine Konsolen-Fehler, keine CSP-Verstöße.

## Checkliste

### Backend
- [x] `app/src/Domain/TokenService.php` aus DISPATCH: `create(string $name, array|string $scopes, ?int $expiresDays, Actor $actor)` speichert `user_id = $actor->userId`; `list(Actor)`, `revoke(int $id, Actor)` nur eigene; `authenticate(string $plain): ?array{id, user_id, name, scopes}` (für Phase 4). Präfix `gmcp_`, Länge 69.
- [x] `TokenRepository` um die passenden Methoden aus DISPATCH ergänzen, jede mit `user_id`-Bedingung: `insert`, `findActiveByHash` (liefert `user_id`), `activeNameExists(userId, name)`, `listMcp(userId)`, `revoke(id, userId): bool`, `touch(id)`.
- [x] `app/src/Api/TokenController.php`: `create`, `list`, `revokeById` — alle über `ApiAuth::user`.
- [x] Routen in `app/index.php`.

### Oberfläche (funktionale Platzhalter)
- [x] `web/css/base.css`: Systemschrift, eine Spalte max. 40 rem, Formularelemente volle Breite, sichtbarer Fokusrahmen, `prefers-color-scheme: dark` mit zwei Farben — keine Gestaltung darüber hinaus.
- [x] `web/js/api.js` (nach `api.js` + `sitzung.js`): Access-Token im Modul-Speicher, `request(method, path, body)` mit automatischem einmaligem `refresh` bei 401, `login`, `logout`, `restore()` (Refresh beim Laden).
- [x] `web/js/token.js`: Liste, Anlegen, Widerrufen (nach `token.js`).
- [x] `web/js/account.js`: Einstieg für `index.html` — `restore()`, dann Anmeldeformular oder Konto-Ansicht.
- [x] `web/js/invitation.js`: Einstieg für `einladung.html`; Token aus `location.hash`, danach `history.replaceState` ohne Hash.
- [x] `web/index.html` umbauen (Sprunglink, genau eine `h1` „GARAGE“, `<main>`, Skript als `type="module"`), `web/einladung.html` neu.

### Prüfen (auf Strato, nach `deploy.cmd`)
- [x] AK im Browser am Handy-Format (Entwicklerwerkzeuge 360 px) und per `curl` für die API, inklusive: Nutzer B widerruft Token von Nutzer A → 404.

### Doku
- [x] `docs/routes.md`, `docs/code-map.md` (`web/js/`, `web/css/`).

## Report-Back

Status: **complete** (2026-11-16), deployt, Rauchtest von `deploy.cmd` grün (inklusive `/garage-app/.env` → 403 und Nachbarn).

### Geprüft auf Strato

API per `curl` (Konten A und B, je mit eigener neuer Garage):

| Fall | Erwartet | Gemessen |
|---|---|---|
| `GET tokens` ohne Anmeldung / mit `gmcp_` als Bearer | 401 | 401 / 401 |
| `POST tokens` `{name}` | 201, Standard-Scopes, `gmcp_` + 64 Hex (Länge 69) | 201, `garage:read garage:write`, Länge 69 |
| Liste danach | 1 Eintrag, ohne Klartext und Hash | wie erwartet |
| gleicher Name nochmal (A) / gleicher Name bei B | 409 `name_taken` / 201 | 409 / 201 |
| ungültiger Name, unbekannter Scope, `expires_days` 0, `expires_days` als Text | 422 | 422 je Fall |
| B listet | nur das eigene Token | 1 Eintrag (das von B) |
| B widerruft Token von A / unbekannte ID / ohne Anmeldung | 404 / 404 / 401 | 404 / 404 / 401; Token von A danach unverändert |
| A widerruft eigenes / nochmal / Liste | 204 / 404 / leer | 204 / 404 / leer |
| `PUT tokens` | 405 | 405 |
| Refresh mit Cookie / Logout / Refresh danach | 200 / 204 / 401 | 200 / 204 / 401 |

Oberfläche in Chrome (headless, per Fernsteuerung gegen die Strato-Adresse, 360 × 740, hell und dunkel): Anmeldeseite, falsches Passwort (Meldung sichtbar, Fokus darauf), Anmeldung, Konto mit Garage und Rolle, Token anlegen (Klartext-Kasten mit „Wird nur jetzt angezeigt.“, nicht in der Liste, Fokus im Kasten), doppelter Name (Meldung, Fokus), Neu laden hält die Sitzung und der Klartext ist weg, Widerrufen, Abmelden, danach Neu laden ohne Sitzung. Einladungsseite: Zusammenfassung aus `preview`, Hash verschwindet aus der Adresszeile, zu kurzes Passwort → Meldung der API, Annahme → Weiterleitung auf `/garage/` mit Konto, benutzte Einladung → Meldung statt Formular, Link ohne Token und unbekannter Token → Meldung statt Formular. Kein waagerechtes Scrollen an keiner Seite. Screenshots lagen im Scratchpad der Session.

### Abweichungen vom Plan

- Zusätzlich `web/js/dom.js` (Bauhelfer, Fehlermeldung mit Fokus) — im Plan nicht genannt, vermeidet doppelten Code in den drei Modulen.
- `<link rel="icon" href="data:,">` in beiden Seiten: ohne ihn fragt der Browser `/favicon.ico` an der Domain-Wurzel (DISPATCH) und bekommt 404.
- Keine Abstimmung zwischen Browser-Tabs (DISPATCH nutzt `BroadcastChannel`): für den Platzhalter überflüssig; zwei Tabs refreshen unabhängig, das 30-s-Kulanzfenster der Rotation fängt gleichzeitige Refreshes ab.
- Widerrufen fragt per `window.confirm` nach.

### Offen / 🟡

- Die Konsole zeigt „Failed to load resource“ für jede erwartete Fehlerantwort (401 beim ersten Besuch ohne Sitzung, 409, 422, 410). Das sind Meldungen des Browsers über Statuscodes, keine Skriptfehler; CSP-Verstöße und Ausnahmen: keine.
- „Kopieren“ (Zwischenablage) im headless-Browser nicht ausgelöst — am Handy mit echtem Gerät zu bestätigen; bei Fehlschlag steht ein Hinweis zum manuellen Kopieren da.
- `TokenService::authenticate` hat noch keinen Aufrufer und ist ungeprüft; Phase 4 prüft es mit dem ersten MCP-Aufruf.
- Testkonten auf Strato: `p2-a-190639@…`, `p2-b-190639@…`, `p2-browser-*@…` (alle `@garage-test.example`); bleiben nach M1 gesperrt stehen (siehe README).
