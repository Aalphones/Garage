# Phase 2 — Passwort-Rücksetzung (Backend)

Rating: heikel (Konto-Übernahme möglich, wenn die Rechte-Regel falsch sitzt) · Modell: opusplan

## Kontext

- `README.md` dieses Plans → Kontrakt (drei Rücksetz-Routen), „Von mir gesetzt“.
- ADR-026 aus Phase 1, ADR-006 (Passwortregeln: mindestens 12 Zeichen, höchstens 72 Bytes).
- Bestand: `app/src/Domain/InvitationService.php` (Muster Token erzeugen, Hash speichern, `preview`/`accept`, `assertValidAccount` für die Passwortregeln), `app/src/Auth/UserSession.php` (`start`, `logoutAll`), `UserRepository.php`, `GarageRepository.php` (`rolesFor`), `app/src/Api/InvitationController.php` (Cookie-Antwort wie Login), `ApiErrors.php`, `LoginThrottle.php`.
- Fehlerklassen geprüft: keine einschlägig.

## Akzeptanzkriterien

1. `POST …/members/{userId}/password-reset` liefert einen `gpwr_`-Link (24 h); ein älterer offener Link desselben Kontos wird dabei ungültig (`used_at` gesetzt).
2. Die Route antwortet 403 `forbidden`, sobald das Ziel-Konto Mitglied einer Garage ist, in der der Anfragende nicht Inhaber ist (Prüfung über `rolesFor` beider Konten).
3. `preview` nennt nur Name und Ablauf, nie E-Mail.
4. `accept` prüft das Passwort wie die Einladung, setzt `password_hash`, markiert den Link benutzt, ruft `logoutAll` für das Konto und startet eine neue Sitzung (Antwort und Cookie wie Login) — alles in einer Transaktion bis auf den Cookie.
5. Ein gesperrtes Konto (`status = 'gesperrt'`) bekommt keinen Link (422 `invalid_value`) und kann keinen einlösen.
6. `preview` und `accept` laufen durch die Login-Bremse je IP (`LoginThrottle`), damit niemand Tokens durchprobiert.

## Checkliste

- [x] `app/src/Domain/PasswordResetRepository.php` (`insert`, `findByHash` mit Sperre, `markUsed`, `invalidateOpen(int $userId)`).
- [x] `app/src/Domain/PasswordResetService.php` (`create`, `preview`, `accept`); Passwortprüfung aus `InvitationService::assertValidAccount` in eine gemeinsame statische Prüfung ziehen (z. B. `Validate::password`), beide Dienste rufen sie.
- [x] `UserRepository::updatePassword(int $userId, string $hash)`.
- [x] `app/src/Api/PasswordResetController.php`, Routen in `app/index.php`.
- [x] `ApiErrors`: `reset_not_found` → 404, `reset_used`/`reset_expired` → 410.
- [x] Audit: `password.reset_create`, `password.reset_accept`.
- [x] `docs/routes.md` nachziehen.
- [ ] `php -l` bzw. CI-Ergebnis in `## Report-Back`.

## Report-Back

- Rechte-Regel strenger als die Akzeptanzkriterien: `preview`/`accept` prüfen sie erneut (Finding aus Phase 1), dazu: Ersteller nicht gesperrt, Ziel-Konto in mindestens einer Garage. Diese Fälle und ein gesperrtes Konto antworten beim Einlösen mit 410 `reset_used`.
- Die Login-Bremse zählt `reset_not_found`, `reset_used` und `reset_expired`; ein zu kurzes Passwort zählt nicht.
- Mitgeändert: `logoutAll` läuft in einer laufenden Transaktion mit und beendet auch Refresh-Tokens im 30-Sekunden-Kulanzfenster.
- Bleibt offen: ein schon ausgestelltes Access-JWT gilt bis zu seinem Ablauf weiter (15 Minuten, ADR-006).
- Keine Route gegen eine Datenbank ausgeführt. Lokal kein PHP; Syntax prüft die CI.
