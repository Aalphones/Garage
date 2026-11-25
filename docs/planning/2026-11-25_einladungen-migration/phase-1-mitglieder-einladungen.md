# Phase 1 — Mitglieder und Einladungen (Backend)

Rating: heikel (Rechte, Cross-Modul-Kontrakt) · Modell: opusplan

## Kontext

- `README.md` dieses Plans → Kontrakt, „Von mir gesetzt“.
- `AGENTS.md` (Regeln 3 und 5), `docs/conventions/php.md`, `docs/decisions/006-konten-per-einladung-sitzung-je-nutzer.md`, `docs/decisions/008-agentenrechte-scope-und-rolle.md`.
- Bestand: `app/src/Domain/InvitationService.php` (`create`, `preview`, `accept`, `assertUsable`), `InvitationRepository.php`, `GarageRepository.php` (`addMember`, `rolesFor`, `find`), `Actor.php` (`isOwner`, `canRead`, Rollen-Konstanten), `AuditRepository.php`, `app/src/Api/InvitationController.php`, `TokenController.php` (Muster JWT-Route), `ApiErrors.php` (Fehlercode → Status), `app/index.php` (Router), `app/migrations/002_garage.sql`.
- Fehlerklassen geprüft: keine einschlägig.

## Akzeptanzkriterien

1. Migration `app/migrations/025_members.sql` ergänzt `invitations` um `created_by INT UNSIGNED NULL` (FK `users`) und `revoked_at DATETIME NULL` und legt `password_resets` an: `id` PK, `token_hash CHAR(64) UNIQUE`, `user_id` FK, `created_by` FK, `expires_at`, `used_at NULL`, `created_at`.
2. Die fünf Mitglieder-/Einladungsrouten des Kontrakts (GET members, POST/DELETE invitations, PATCH/DELETE members) und `POST /garage/api/invitations/join` antworten wie im Kontrakt, Fehlercodes inklusive.
3. Kein Weg setzt die Zahl der Inhaber einer Garage auf 0 (`last_owner`), geprüft in derselben Transaktion mit `SELECT … FOR UPDATE` auf die Inhaber-Zeilen.
4. `preview`/`accept`/`join` lehnen widerrufene Einladungen ab wie angenommene (410 `invitation_used`).
5. Jede Änderung schreibt einen Audit-Eintrag (`member.role`, `member.remove`, `invitation.revoke`, `invitation.join`, `invitation.create` mit `created_by`).
6. ADR-026 liegt in `docs/decisions/026-mitglieder-einladungen-passwort.md` (Kontext, Optionen, Entscheidung, Konsequenzen; deckt auch Phase 2) und ADR-006 verweist im Abschnitt „Konsequenzen“ darauf.

## Checkliste

- [ ] `025_members.sql` schreiben (Kollation und Engine wie `002_garage.sql`).
- [ ] `InvitationRepository`: `insert` um `createdBy` erweitern; `findByHash` liefert `is_revoked`; neu `listOpen(int $garageId)` (mit Name des Einladenden per JOIN `users`), `revoke(int $id, int $garageId): bool`.
- [ ] `InvitationService`: `createForGarage(int $garageId, string $role, ?string $email, int $days, Actor $actor)` (Inhaber-Prüfung, dann bestehendes `create` mit `garageId`), `revoke`, `join(string $token, Actor $actor)` (409 `already_member`, sonst `addMember` + Einladung als angenommen markieren), `assertUsable` um `is_revoked` ergänzen. Der Admin-Weg `create` bleibt unverändert nutzbar.
- [ ] `GarageRepository`: `members(int $garageId)`, `memberRole(int $garageId, int $userId)`, `ownerIdsForUpdate(int $garageId)`, `changeRole`, `removeMember`.
- [ ] Neuer `app/src/Domain/MemberService.php`: `list`, `changeRole`, `remove` mit den Regeln aus dem Kontrakt; fremde Garage → `not_found`, kein Inhaber → `forbidden`; Selbst-Entfernen erlaubt.
- [ ] Neuer `app/src/Api/MemberController.php` (Muster `TokenController`), Routen in `app/index.php`, `invitations/join` in `InvitationController`.
- [ ] `ApiErrors`: `last_owner` → 409, `already_member` → 409.
- [ ] ADR-026 schreiben, ADR-006 verlinken.
- [ ] `docs/routes.md` (neue Zeilen), `docs/models.md` (`invitations`, `password_resets`).
- [ ] `php -l` auf alle geänderten Dateien, falls PHP lokal fehlt: CI-Syntaxprüfung nach dem Push abwarten und Ergebnis in `## Report-Back` notieren.

## Report-Back
