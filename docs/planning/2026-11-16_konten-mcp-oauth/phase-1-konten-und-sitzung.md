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

- [ ] Migrationen `001_user.sql`, `002_garage.sql`, `003_token.sql`, `004_audit.sql` laufen über `POST /garage/api/deploy` durch; `schema_migrations` zeigt alle vier.
- [ ] `POST /garage/api/admin/invitations` mit Deploy-Token und `{"garage_name":"Familie Mick","role":"inhaber"}` → 201 `{"link":"https://…/garage/einladung.html#<token>","expires_at":"…"}`; ohne Token 401; mit `garage_id` einer bestehenden Garage und `role` `mitglied`/`lesend` ebenso.
- [ ] `POST /garage/api/invitations/accept` mit `{token, name, email, password}` → Konto + Mitgliedschaft + (bei `garage_name`) neue Garage, Antwort wie Login inkl. Cookie. Zweite Annahme desselben Tokens → 410 `invitation_used`. Abgelaufen → 410 `invitation_expired`. Passwort unter 12 Zeichen → 422 `invalid_value`. E-Mail schon vergeben → 409 `email_taken`.
- [ ] `POST /garage/api/auth/login` → 200 `{access_token, expires_in, user:{id,name,email}}` + Cookie `garage_refresh` (HttpOnly, SameSite=Strict, Secure, Pfad `/garage/api/auth`). Falsche E-Mail und falsches Passwort → beide 401 `invalid_credentials` mit gleicher Meldung. 11. Fehlversuch in 15 min → 429.
- [ ] `refresh` rotiert, Wiederverwendung eines alten Refresh-Tokens nach der Kulanz sperrt die ganze Familie (wie DISPATCH). `logout-all` widerruft nur die Sitzungen **dieses** Nutzers.
- [ ] `GET /garage/api/me` mit JWT → `{user:{id,name,email}, garages:[{id,name,role}]}`; ohne/mit kaputtem JWT → 401.
- [ ] Ein Nutzer mit `status = 'gesperrt'` kann sich nicht anmelden und nicht refreshen.

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
- [ ] `app/src/Db/Transaction.php` aus DISPATCH kopieren.
- [ ] `app/src/Domain/Actor.php` neu nach Kontrakt (readonly, Fabriken `deploy()` und `forUser(...)`; `forUser` lädt Rollen per `GarageRepository::rolesFor($userId)`).
- [ ] `app/src/Domain/AuditRepository.php` aus DISPATCH, Signatur `record(Actor $actor, string $action, string $target, ?int $garageId = null, ?array $before = null, ?array $after = null): void`; schreibt `user_id` aus dem Akteur.
- [ ] `app/src/Domain/UserRepository.php`: `findByEmail(string)`, `findById(int)`, `insert(email, name, passwordHash): int`, `touchLogin(int)`.
- [ ] `app/src/Domain/GarageRepository.php`: `insert(name): int`, `addMember(garageId, userId, role)`, `rolesFor(userId): array<int,string>`, `listForUser(userId): list<{id,name,role}>`, `exists(garageId): bool`.

### Sitzung
- [ ] `app/src/Auth/Jwt.php` aus DISPATCH; `newAccessClaims(int $userId)` setzt `sub` = (string) `$userId`; `decode` liefert die Claims, `claimsAreValid` verlangt `sub` aus Ziffern. Secret `GARAGE_JWT_SECRET`.
- [ ] `app/src/Auth/LoginThrottle.php` aus DISPATCH (10 Fehlversuche, 900 s).
- [ ] `app/src/Domain/TokenRepository.php`: nur die Session-Methoden aus DISPATCH, umbenannt `insertUserRefresh(userId, hash, family, lifetime)`, `findByHash(hash, kind)` (liefert zusätzlich `user_id`), `revokeOne`, `revokeFamily`, `revokeAllUserRefresh(int $userId)`. Phasen 2 und 3 ergänzen weitere Methoden.
- [ ] `app/src/Auth/UserSession.php` aus `OwnerSession.php`: `login(email, password, ip)` — E-Mail kleinschreiben, Nutzer laden; ist keiner da, trotzdem `password_verify` gegen einen festen Dummy-Hash laufen lassen (gleiche Antwortzeit); `status = 'gesperrt'` zählt als falsch. `refresh` prüft zusätzlich, dass der Nutzer noch `aktiv` ist. Antwort `{access_token, expires_in, user:{id,name,email}}`. Cookie `garage_refresh`, Präfix `gref_`.
- [ ] `app/src/Auth/ApiAuth.php`: `user(Request): Actor` (JWT → `Actor::forUser($sub, 'web', 'user:'.$sub, Scopes::ALL, $pdo)`, Nutzer muss `aktiv` sein), `deployOnly(Request): Actor`. Präfixe `gmcp_`, `goa_` ergeben 401.
- [ ] `app/src/Auth/Scopes.php` aus DISPATCH mit `garage:read`, `garage:write`, `garage:files`.

### Einladung
- [ ] `app/src/Domain/InvitationService.php`: `create(?int garageId, ?string garageName, string role, ?string email, int days = 7, Actor): array{link, expires_at}` (Token `ginv_` + 64 Hex, Link `<base_url>/garage/einladung.html#<token>`); `preview(token): {garage_name, role, expires_at}`; `accept(token, name, email, password, ip): array` — in einer Transaktion: Einladung sperren (`SELECT … FOR UPDATE`), Nutzer anlegen (`password_hash(..., PASSWORD_DEFAULT)`), ggf. Garage anlegen, Mitgliedschaft, `accepted_at`/`accepted_by`, Audit `invitation.accept`; danach Sitzung über `UserSession` starten.
- [ ] `app/src/Domain/InvitationRepository.php` für die SQL dazu.

### Web-API
- [ ] `app/src/Api/AuthController.php` aus DISPATCH (`login`, `refresh`, `logout`, `logoutAll`), Body `{email, password}`; die Konfigurationsprüfung verlangt `GARAGE_JWT_SECRET` mit mindestens 64 Zeichen.
- [ ] `app/src/Api/InvitationController.php`: `create` (Deploy-Token), `preview`, `accept`.
- [ ] `app/src/Api/MeController.php`: `show`.
- [ ] Routen in `app/index.php` nach Kontrakt (Phase 1).

### Konfiguration
- [ ] `GARAGE_JWT_SECRET` (nur Buchstaben und Ziffern, 96 Zeichen) in `deploy.env.example`, `dev.env.example`; `deploy.cmd`: `call :needValue GARAGE_JWT_SECRET`, Zeichen-Prüfung wie beim Deploy-Token, Zeile `>>"app\.env" echo GARAGE_JWT_SECRET='!GARAGE_JWT_SECRET!'`.
- [ ] Wert lokal erzeugen und an `deploy.env` anhängen, ohne ihn auszugeben (PowerShell 5.1): `$b = New-Object byte[] 48; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); ($b | % { $_.ToString('x2') }) -join ''` → 96 Hex-Zeichen.

### Prüfen (auf Strato, nach `deploy.cmd`)
- [ ] Jedes AK oben per `curl` durchspielen, inklusive der abzuweisenden Fälle; Liste der Fälle mit Statuscode in `## Report-Back`.
- [ ] Testgarage „Test B“ mit zweitem Nutzer anlegen (wird in Phase 4 für die Mandanten-Prüfung gebraucht).

### Doku
- [ ] `docs/models.md`: neue Tabellen.
- [ ] `docs/routes.md`: neue Routen, „Vorgesehen“ kürzen.
- [ ] `docs/code-map.md`: `Auth/` und `Domain/`-Zeilen aktualisieren.
- [ ] ADR-006 „Konten per Einladung, Sitzung je Nutzer“ (E1, E2) in `docs/decisions/`.

## Report-Back
