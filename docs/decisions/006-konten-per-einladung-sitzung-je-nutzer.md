# 006 — Konten per Einladung, Sitzung je Nutzer

**Status:** Akzeptiert (2026-11-16)

## Kontext

GARAGE ist mehrnutzerfähig, soll aber nicht offen registrierbar sein: Fahrzeugdaten sind persönlich, und Selbstregistrierung brächte Spam, Missbrauchsschutz und E-Mail-Versand mit, die bis Meilenstein 6 nicht gebraucht werden. DISPATCH kennt genau einen Inhaber mit Zugang aus der `.env`; die erprobte Sitzungstechnik (kurzes Access-JWT, rotierendes Refresh-Cookie) lässt sich auf Konten in der Datenbank übertragen.

## Entscheidung

- **Konten nur per Einladung.** `POST /garage/api/admin/invitations` mit Deploy-Token erzeugt einen einmaligen Link (`ginv_`-Token, nur als SHA-256 gespeichert, 7 Tage). Die Einladung nennt entweder eine bestehende Garage mit Rolle oder einen Namen für eine neue Garage (dann zwingend als Inhaber). Die Annahme legt Konto, Mitgliedschaft und Garage in einer Transaktion an und startet die Sitzung. Die E-Mail der Einladung ist nur eine Notiz, kein Abgleich. Bis Meilenstein 6 (Oberfläche für Einladungen) ist das Deploy-Token der einzige Weg zu einer Einladung.
- **Sitzung wie DISPATCH, je Nutzer.** Access-JWT (HS256, 15 Minuten, `sub` = Nutzer-ID) im Arbeitsspeicher des Browsers; Refresh-Token (`gref_`, 30 Tage) als Cookie `garage_refresh` mit Rotation, 30 Sekunden Kulanz und Sperre der ganzen Familie bei Wiederverwendung. Anmeldung mit E-Mail und Passwort aus `users` (`password_hash`).
- **Login-Bremse** je IP (HMAC, nie die Adresse): ab dem 11. Fehlversuch in 15 Minuten 429. Ein unbekanntes Konto läuft durch einen Dummy-Hash, damit Meldung und Antwortzeit nichts verraten. Ein gesperrter Nutzer (`status = 'gesperrt'`) zählt als falsche Anmeldung und kann nicht refreshen.
- **Passwörter:** mindestens 12 Zeichen, höchstens 72 Bytes (bcrypt liest nicht mehr).
- **Akteur:** `Garage\Domain\Actor` trägt Nutzer, Weg (`web`, `mcp`, `oauth`, `deploy`), Scopes und Rollen je Garage. Lesen braucht eine Rolle, Schreiben Rolle `inhaber`/`mitglied` **und** Scope `garage:write` (siehe ADR-008).

## Konsequenzen

- Meilenstein 6 erweitert diese Entscheidung um Einladungen aus der Oberfläche, Mitgliederverwaltung, Beitreten mit bestehendem Konto und Passwort-Rücksetzung: [ADR-026](026-mitglieder-einladungen-passwort.md).
- Wer sein Passwort vergisst, hat bis Meilenstein 6 keinen Weg zurück ohne neue Einladung.
- `GARAGE_JWT_SECRET` (mindestens 64 Zeichen) ist Pflicht; ohne ihn antworten alle Anmelde-Routen mit 503.
- Ein Nutzer wird nie gelöscht, nur auf `gesperrt` gesetzt; dafür gibt es noch keine Oberfläche.
