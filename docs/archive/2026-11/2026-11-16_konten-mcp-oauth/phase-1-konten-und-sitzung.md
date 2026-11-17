# Phase 1 — Konten und Sitzung

Rating: **heikel** (Anmeldung, Mandantentrennung im Akteur).

## Kontext — vorher lesen

- [`README.md`](README.md) → Entscheidungen E1, E2, E4, E8 und der ganze Kontrakt (Token-Präfixe, Rollen, Akteur, Routen).
- `AGENTS.md`, `docs/conventions/php.md`, `docs/conventions/dod.md`, `docs/models.md` (Regeln für neue Tabellen), ADR-002, ADR-004, ADR-005.
- Vorhanden in GARAGE: `app/index.php`, `app/src/Http/*` (vollständig, inkl. `Response::cookie`), `app/src/Db/Migrator.php`, `app/src/Auth/DeployToken.php`, `deploy.cmd` (Abschnitt, der `app\.env` schreibt, und `needValue`-Prüfungen).
- DISPATCH-Vorlagen (`C:\Users\sasch\develop\Blog\app\`): `src/Db/Transaction.php`, `src/Auth/Jwt.php`, `src/Auth/LoginThrottle.php`, `src/Auth/OwnerSession.php`, `src/Auth/ApiAuth.php`, `src/Domain/AuditRepository.php`, `src/Domain/TokenRepository.php` (nur Session-Teil: `insertOwnerRefresh`, `findByHash`, `revokeOne`, `revokeFamily`, `revokeAllOwnerRefresh`), `src/Api/AuthController.php`, `migrations/010_token.sql`, `migrations/012_login_attempt.sql`.
- Fehlerklassen: Strato-Topic `~/.claude/knowledge/topics/strato-shared-hosting.md` — Werte in `.env` in einfache Anführungszeichen; DDL nie in eine Transaktion.

## Kopier-Regel (gilt für jede kopierte Datei)

Namespace `Dispatch\` → `Garage\`, `DISPATCH_*` → `GARAGE_*`, Pfade `/dispatch/` → `/garage/`, Präfixe nach Kontrakt. Kopf-Kommentar der Klasse um „aus DISPATCH (ADR-005)“ ergänzen. Nichts mitkopieren, was diese Phase nicht braucht.

## Abnahmekriterien

- [x] Migrationen `001_user.sql`, `002_garage.sql`, `003_token.sql`, `004_audit.sql` laufen über `POST /garage/api/deploy` durch; `schema_migrations` zeigt alle vier.
- [x] `POST /garage/api/admin/invitations` mit Deploy-Token und `{"garage_name":"Familie Mick","role":"inhaber"}` → 201 `{"link":"https://…/garage/einladung.html#<token>","expires_at":"…"}`; ohne Token 401; mit `garage_id` einer bestehenden Garage und `role` `mitglied`/`lesend` ebenso.
- [x] (bis auf „Abgelaufen“: nicht per curl auslösbar, siehe Report-Back) `POST /garage/api/invitations/accept` mit `{token, name, email, password}` → Konto + Mitgliedschaft + (bei `garage_name`) neue Garage, Antwort wie Login inkl. Cookie. Zweite Annahme desselben Tokens → 410 `invitation_used`. Abgelaufen → 410 `invitation_expired`. Passwort unter 12 Zeichen → 422 `invalid_value`. E-Mail schon vergeben → 409 `email_taken`.
- [x] `POST /garage/api/auth/login` → 200 `{access_token, expires_in, user:{id,name,email}}` + Cookie `garage_refresh` (HttpOnly, SameSite=Strict, Secure, Pfad `/garage/api/auth`). Falsche E-Mail und falsches Passwort → beide 401 `invalid_credentials` mit gleicher Meldung. 11. Fehlversuch in 15 min → 429.
- [x] `refresh` rotiert, Wiederverwendung eines alten Refresh-Tokens nach der Kulanz sperrt die ganze Familie (wie DISPATCH). `logout-all` widerruft nur die Sitzungen **dieses** Nutzers.
- [x] `GET /garage/api/me` mit JWT → `{user:{id,name,email}, garages:[{id,name,role}]}`; ohne/mit kaputtem JWT → 401.
- [ ] Ein Nutzer mit `status = 'gesperrt'` kann sich nicht anmelden und nicht refreshen. (Im Code umgesetzt, auf Strato nicht geprüft: kein Weg, einen Nutzer zu sperren — siehe Report-Back.)

## Datenmodell (Migrationen in `app/migrations/`)

Alle Tabellen `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, Zeitstempel `DATETIME` UTC.

`001_user.sql` — `users`: `id` INT UNSIGNED PK AI, `email` VARCHAR(190) NOT NULL UNIQUE (immer klein geschrieben gespeichert), `name` VARCHAR(120) NOT NULL, `password_hash` VARCHAR(255) NOT NULL, `status` ENUM('aktiv','gesperrt') NOT NULL DEFAULT 'aktiv', `created_at`, `last_login_at` NULL.

`002_garage.sql`:
- `garages`: `id` PK, `name` VARCHAR(120) NOT NULL, `created_at`.
- `garage_members`: `garage_id` FK→garages, `user_id` FK→users, `role` ENUM('inhaber','mitglied','lesend') NOT NULL, `created_at`; PK (`garage_id`,`user_id`), INDEX (`user_id`).
- `invitations`: `id` PK, `token_hash` CHAR(64) NOT NULL UNIQUE, `garage_id` INT UNSIGNED NULL FK→garages, `garage_name` VARCHAR(120) NULL (genau eins von beiden gesetzt — prüft der Service), `role` ENUM wie oben, `email` VARCHAR(190) NULL (nur Notiz, kein Abgleich), `expires_at` NOT NULL, `accepted_at` NULL, `accepted_by` INT UNSIGNED NULL FK→users, `created_at`.

`003_token.sql` — `tokens` wie DISPATCH `010_token.sql`, aber `kind` ENUM('mcp','oauth_access','oauth_refresh','user_refresh'), zusätzlich `user_id` INT UNSIGNED NOT NULL FK→users, INDEX (`user_id`, `kind`, `revoked`). Dazu `login_attempts` wie DISPATCH `012_login_attempt.sql`.

`004_audit.sql` — `audit_log`: `id` BIGINT PK, `garage_id` INT UNSIGNED NULL, `user_id` INT UNSIGNED NULL, `actor` VARCHAR(160) NOT NULL, `action` VARCHAR(60) NOT NULL, `target` VARCHAR(190) NOT NULL, `before_json` JSON NULL, `after_json` JSON NULL, `created_at`; INDEX (`garage_id`, `created_at`).

## Checkliste

### Basis
- [x] `app/src/Db/Transaction.php` aus DISPATCH kopieren.
- [x] `app/src/Domain/Actor.php` neu nach Kontrakt (readonly, Fabriken `deploy()` und `forUser(...)`; `forUser` lädt Rollen per `GarageRepository::rolesFor($userId)`).
- [x] `app/src/Domain/AuditRepository.php` aus DISPATCH, Signatur `record(Actor $actor, string $action, string $target, ?int $garageId = null, ?array $before = null, ?array $after = null): void`; schreibt `user_id` aus dem Akteur.
- [x] `app/src/Domain/UserRepository.php`: `findByEmail(string)`, `findById(int)`, `insert(email, name, passwordHash): int`, `touchLogin(int)`.
- [x] `app/src/Domain/GarageRepository.php`: `insert(name): int`, `addMember(garageId, userId, role)`, `rolesFor(userId): array<int,string>`, `listForUser(userId): list<{id,name,role}>`, `exists(garageId): bool`.

### Sitzung
- [x] `app/src/Auth/Jwt.php` aus DISPATCH; `newAccessClaims(int $userId)` setzt `sub` = (string) `$userId`; `decode` liefert die Claims, `claimsAreValid` verlangt `sub` aus Ziffern. Secret `GARAGE_JWT_SECRET`.
- [x] `app/src/Auth/LoginThrottle.php` aus DISPATCH (10 Fehlversuche, 900 s).
- [x] `app/src/Domain/TokenRepository.php`: nur die Session-Methoden aus DISPATCH, umbenannt `insertUserRefresh(userId, hash, family, lifetime)`, `findByHash(hash, kind)` (liefert zusätzlich `user_id`), `revokeOne`, `revokeFamily`, `revokeAllUserRefresh(int $userId)`. Phasen 2 und 3 ergänzen weitere Methoden.
- [x] `app/src/Auth/UserSession.php` aus `OwnerSession.php`: `login(email, password, ip)` — E-Mail kleinschreiben, Nutzer laden; ist keiner da, trotzdem `password_verify` gegen einen festen Dummy-Hash laufen lassen (gleiche Antwortzeit); `status = 'gesperrt'` zählt als falsch. `refresh` prüft zusätzlich, dass der Nutzer noch `aktiv` ist. Antwort `{access_token, expires_in, user:{id,name,email}}`. Cookie `garage_refresh`, Präfix `gref_`.
- [x] `app/src/Auth/ApiAuth.php`: `user(Request): Actor` (JWT → `Actor::forUser($sub, 'web', 'user:'.$sub, Scopes::ALL, $pdo)`, Nutzer muss `aktiv` sein), `deployOnly(Request): Actor`. Präfixe `gmcp_`, `goa_` ergeben 401.
- [x] `app/src/Auth/Scopes.php` aus DISPATCH mit `garage:read`, `garage:write`, `garage:files`.

### Einladung
- [x] `app/src/Domain/InvitationService.php`: `create(?int garageId, ?string garageName, string role, ?string email, int days = 7, Actor): array{link, expires_at}` (Token `ginv_` + 64 Hex, Link `<base_url>/garage/einladung.html#<token>`); `preview(token): {garage_name, role, expires_at}`; `accept(token, name, email, password, ip): array` — in einer Transaktion: Einladung sperren (`SELECT … FOR UPDATE`), Nutzer anlegen (`password_hash(..., PASSWORD_DEFAULT)`), ggf. Garage anlegen, Mitgliedschaft, `accepted_at`/`accepted_by`, Audit `invitation.accept`; danach Sitzung über `UserSession` starten.
- [x] `app/src/Domain/InvitationRepository.php` für die SQL dazu.

### Web-API
- [x] `app/src/Api/AuthController.php` aus DISPATCH (`login`, `refresh`, `logout`, `logoutAll`), Body `{email, password}`; die Konfigurationsprüfung verlangt `GARAGE_JWT_SECRET` mit mindestens 64 Zeichen.
- [x] `app/src/Api/InvitationController.php`: `create` (Deploy-Token), `preview`, `accept`.
- [x] `app/src/Api/MeController.php`: `show`.
- [x] Routen in `app/index.php` nach Kontrakt (Phase 1).

### Konfiguration
- [x] `GARAGE_JWT_SECRET` (nur Buchstaben und Ziffern, 96 Zeichen) in `deploy.env.example`, `dev.env.example`; `deploy.cmd`: `call :needValue GARAGE_JWT_SECRET`, Zeichen-Prüfung wie beim Deploy-Token, Zeile `>>"app\.env" echo GARAGE_JWT_SECRET='!GARAGE_JWT_SECRET!'`.
- [x] Wert lokal erzeugen und an `deploy.env` anhängen, ohne ihn auszugeben (PowerShell 5.1): `$b = New-Object byte[] 48; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); ($b | % { $_.ToString('x2') }) -join ''` → 96 Hex-Zeichen.

### Prüfen (auf Strato, nach `deploy.cmd`)
- [x] Jedes AK oben per `curl` durchspielen, inklusive der abzuweisenden Fälle; Liste der Fälle mit Statuscode in `## Report-Back`.
- [x] Testgarage „Test B“ mit zweitem Nutzer anlegen (wird in Phase 4 für die Mandanten-Prüfung gebraucht).

### Doku
- [x] `docs/models.md`: neue Tabellen.
- [x] `docs/routes.md`: neue Routen, „Vorgesehen“ kürzen.
- [x] `docs/code-map.md`: `Auth/` und `Domain/`-Zeilen aktualisieren.
- [x] ADR-006 „Konten per Einladung, Sitzung je Nutzer“ (E1, E2) in `docs/decisions/`.

## Report-Back

Stand 2026-11-16: umgesetzt, deployt (`deploy.cmd`: Migrationen 001–004 angewandt, Rauchtest grün) und per curl auf Strato geprüft.

### Fälle auf Strato (Statuscode · Ergebnis)

| Fall | Erwartet | Ist |
|---|---|---|
| Einladung anlegen ohne / mit falschem Deploy-Token | 401 | 401 / 401 |
| Einladung neue Garage (inhaber) | 201 | 201, Link `…/garage/einladung.html#<token>`, 7 Tage |
| Einladung neue Garage als `mitglied`, mit `garage_name` und `garage_id`, mit unbekannter `garage_id`, mit Rolle `chef` | 422 | 422 je Fall |
| Einladung in bestehende Garage als `lesend` | 201 | 201 |
| Vorschau gültig / unbekanntes Token / nach Annahme | 200 / 404 / 410 | 200 / 404 `invitation_not_found` / 410 |
| Annahme mit Passwort unter 12 Zeichen / ungültiger E-Mail | 422 | 422 / 422 |
| Annahme gültig | 200 + Cookie | 200; Cookie `garage_refresh` mit `Secure; HttpOnly; SameSite=Strict; path=/garage/api/auth`; Refresh-Token nicht im Body |
| Annahme zum zweiten Mal | 410 `invitation_used` | 410 `invitation_used` |
| Annahme mit vergebener E-Mail | 409 `email_taken` | 409 `email_taken` |
| Login gültig, auch mit GROSSGESCHRIEBENER E-Mail | 200 | 200 / 200 |
| Login falsches Passwort / unbekannte E-Mail | 401, gleiche Meldung | 401 / 401, Antworten byte-gleich |
| Login: Fehlversuche | 11. → 429 | 11. Fehlversuch (inkl. der zwei zuvor) → 429, `Retry-After: 900`; auch das richtige Passwort wird dann abgewiesen |
| Refresh ohne Marker / mit fremdem Origin | 403 | 403 / 403 |
| Refresh rotiert; alter Token innerhalb 30 s | 200 / 200 (ohne neuen Cookie) | 200 / 200 |
| Alter Token nach Kulanz | 401 `session_revoked`, Familie gesperrt | 401 `session_revoked`; auch der neue Token danach 401 |
| Logout ohne Marker / mit Marker / Refresh danach | 403 / 204 / 401 | 403 / 204 / 401 |
| `logout-all` ohne JWT / als A; danach Refresh von A / von B | 401 / 204 / 401 / 200 | 401 / 204 / 401 / 200 |
| `me` mit JWT / ohne / kaputt / Signatur oder Payload verändert / `alg: none` / `gmcp_`-Token | 200 / 401 für alle übrigen | 200 / 401 / 401 / 401 / 401 / 401 |
| `me` Nutzer B / Nutzer mit Rolle `lesend` | nur eigene Garage / Rolle `lesend` | Garage 2 `inhaber` / Garage 1 `lesend` |

### Offen

- 🟡 **Abgelaufene Einladung → 410 `invitation_expired`** und **gesperrter Nutzer kann sich nicht anmelden/refreshen** sind nicht auf Strato durchgespielt: beide brauchen einen Schreibzugriff auf die Datenbank (Ablaufdatum zurücksetzen, `status` setzen), und die Datenbank ist von außen nicht erreichbar. Im Code vorhanden (`InvitationRepository::findByHash` vergleicht `expires_at <= UTC_TIMESTAMP()`, `UserSession` prüft `status`). Klärung im Abschluss (Phase 5) oder früher mit einem Verwaltungsweg für Sperren (Meilenstein 6).
- Testdaten auf Strato: Garage 1 „Test A“ (Nutzer `test-a-143228@garage-test.example` inhaber, `test-lesend-143228@…` lesend), Garage 2 „Test B“ (Nutzer `test-b-143228@…`), Garage „Test Signatur“ (Nutzer `test-sig-…`). Das Passwort der Testkonten steht nur im Prüfskript der Session, nicht im Repo. Test A/Test B werden in Phase 4 für die Mandanten-Prüfung gebraucht (dort neue Einladungen oder Konten anlegen, falls das Passwort fehlt). Die Login-Bremse hat die prüfende IP bis ca. 14:50 UTC gesperrt.

### Abweichungen vom Plan

- `Actor::anonymous()` ergänzt (für das Audit fehlgeschlagener Anmeldungen ohne Nutzer); `InvitationService::accept` ohne `ip`-Parameter (wurde nicht gebraucht).
- Eine Einladung mit neuer Garage ist nur als `inhaber` erlaubt; `days` auf 1–30 begrenzt; Passwort höchstens 72 Bytes (bcrypt).
- `deploy.cmd` hatte im Arbeitsordner LF statt CRLF (Git normalisiert beim Commit auf CRLF); vor dem Lauf auf CRLF gesetzt.
