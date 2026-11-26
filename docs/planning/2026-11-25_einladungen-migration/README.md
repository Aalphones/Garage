# Meilenstein 6 — Einladungen in der Oberfläche, Migration der beiden Akten

Stand: 2026-11-25 (freigegeben)

Inhaber laden Menschen selbst in ihre Garage ein, sehen und ändern Mitglieder und helfen bei vergessenem Passwort mit einem Rücksetz-Link — alles im Konto, ohne Deploy-Token. Danach baut ein Agent die Akten von Golf Sportsvan und Clio III per MCP in der Live-Garage auf und gleicht sie mit den alten Markdown-Akten ab.

Wünsche des Inhabers (2026-11-25): Rücksetz-Link erzeugt der Inhaber (kein Mailversand); Einladung per Link kopieren oder Teilen-Dialog; Migration durch einen Agenten per MCP, je Auto eine Phase; Grundlage sind die fertig ausgewerteten Akten `golf-sportsvan.md` und `clio-3.md` in `G:\Meine Ablage\U400_Unterlagen\U003_Auto\`, Belege werden mit hochgeladen; Versicherungsunterlagen aus `G:\Meine Ablage\U300_Verträge\U301_Versicherungen\KFZ\` kommen mit; Oberfläche freihändig nach dem Muster der Token-Liste.

| Phase | Inhalt | Rating | Modell | Status |
|---|---|---|---|---|
| 1 | Mitglieder und Einladungen: Migration 025, ADR-026, Dienst und Routen | heikel | opusplan | complete |
| 2 | Passwort-Rücksetzung: Dienst und Routen | heikel | opusplan | complete |
| 3 | Konto: Abschnitt „Mitglieder“ mit Einladen, Rolle, Entfernen, Rücksetz-Link | standard | sonnet | complete |
| 4 | Seiten `einladung.html` (bestehendes Konto) und `passwort.html` | standard | sonnet | complete |
| 5 | Migration Golf Sportsvan (Live-Garage, MCP) | heikel | opusplan | pending |
| 6 | Migration Clio III (Live-Garage, MCP) | standard | sonnet | pending |
| 7 | Doku-Abgleich und Abschluss | mechanisch | sonnet | pending |

Reihenfolge: 1 → 2 → 3 → 4 → 5 → 6 → 7. Abhängigkeiten: 2 braucht 1 (Migration 025); 3 braucht 1 und 2; 4 braucht 1 und 2; 5 und 6 brauchen nur die schon live laufenden Werkzeuge aus Meilenstein 1–5 und hängen nicht an 1–4; 6 braucht 5 (Erfahrungen aus `FINDINGS.md`); 7 braucht alles.

Entscheidungen: [ADR-026](../../decisions/026-mitglieder-einladungen-passwort.md) (entsteht in Phase 1).

## Kontrakt

Alle Routen nur mit Access-JWT eines Menschen (`ApiAuth::user`), nie mit `gmcp_`/`goa_` (AGENTS.md Regel 5). Fremde oder unbekannte Garage → 404 `not_found`; Mitglied ohne Inhaber-Rolle an einer Inhaber-Route → 403 `forbidden`.

| Methode | Pfad | Wer | Antwort |
|---|---|---|---|
| GET | `/garage/api/garages/{id}/members` | jedes Mitglied | `{members:[{user_id,name,email,role,since,is_self}], invitations:[{id,role,email,expires_at,created_at,created_by_name}], can_manage}` — `invitations` nur offene (nicht angenommen, nicht widerrufen, nicht abgelaufen) und nur für Inhaber, sonst `[]`; `can_manage` = Inhaber |
| POST | `/garage/api/garages/{id}/invitations` | Inhaber | 201 `{id,link,expires_at}`; Body `role` (`inhaber`/`mitglied`/`lesend`), optional `email` (Notiz), `days` (1–30, Standard 7); 422 `invalid_value` |
| DELETE | `/garage/api/garages/{id}/invitations/{invitationId}` | Inhaber | 204; schon angenommen, widerrufen oder fremd → 404 `not_found` |
| PATCH | `/garage/api/garages/{id}/members/{userId}` | Inhaber | 200 `{member}`; Body `role`; 409 `last_owner`, wenn der letzte Inhaber herabgestuft würde |
| DELETE | `/garage/api/garages/{id}/members/{userId}` | Inhaber, oder das Mitglied selbst („Garage verlassen“) | 204; 409 `last_owner` beim letzten Inhaber |
| POST | `/garage/api/invitations/join` | angemeldeter Mensch | 200 `{garage_id,garage_name,role}`; Body `token`; 409 `already_member`; 404/410 wie `preview` |
| POST | `/garage/api/garages/{id}/members/{userId}/password-reset` | Inhaber | 201 `{link,expires_at}` (24 h); 403 `forbidden`, wenn das Ziel-Konto in einer Garage Mitglied ist, in der der Anfragende nicht Inhaber ist |
| POST | `/garage/api/password-reset/preview` | Rücksetz-Token im Body | `{name,expires_at}`; 404 `reset_not_found`, 410 `reset_used`/`reset_expired` |
| POST | `/garage/api/password-reset/accept` | Rücksetz-Token im Body | wie Login samt Cookie; Body `token`, `password`; 422 `invalid_value`; 404/410 wie `preview` |

Tokens: Einladung `ginv_` (besteht), Rücksetzung `gpwr_` + 64 Hex, beide nur als SHA-256 gespeichert, Klartext nur in der einen Antwort. Links: `…/garage/einladung.html#<token>` (besteht), `…/garage/passwort.html#<token>`.

## Finale Akzeptanzkriterien

1. Ein Inhaber lädt im Konto jemanden in seine Garage ein, ohne Deploy-Token, und kann den Link kopieren oder über den Teilen-Dialog des Handys verschicken.
2. Wer schon ein Konto hat, tritt über einen Einladungs-Link einer weiteren Garage bei, ohne zweites Konto.
3. Ein Inhaber sieht Mitglieder und offene Einladungen seiner Garage, ändert Rollen, entfernt Mitglieder, widerruft Einladungen; der letzte Inhaber kann weder herabgestuft noch entfernt werden.
4. Ein Inhaber erzeugt für ein Mitglied einen Rücksetz-Link; damit setzt das Mitglied ein neues Passwort, alle seine Browser-Sitzungen enden.
5. Golf Sportsvan und Clio III stehen in der Live-Garage mit Stammdaten, Scheckheft, Wartungsregeln, Messwerten, Reifen, Teilen, Verträgen, Notizen, Belegen und Klärfällen; jede Abweichung zwischen `get_dossier` und alter Markdown-Akte ist erfasst oder ein Klärfall.

## Von mir gesetzt

- Mitgliederliste sehen alle Mitglieder, offene Einladungen nur Inhaber — Mitglieder sollen wissen, wer mitliest; Einladungs-Links sind Inhabersache.
- Neue Garagen per Einladung bleiben beim Deploy-Token — die Oberfläche lädt nur in eine bestehende Garage ein.
- Entfernen löscht die Zeile in `garage_members` und schreibt einen Audit-Eintrag — eine Mitgliedschaft ist kein Fakt mit Herkunft; die Rollen werden bei jeder Anfrage neu gelesen (`Actor::forUser` → `rolesFor`), der Zugriff endet sofort.
- Verliert jemand die Inhaber-Rolle (herabgestuft, entfernt, selbst gegangen), werden die offenen Einladungen dieser Garage, die diese Person erzeugt hat, in derselben Transaktion widerrufen (Entscheidung des Inhabers, 2026-11-26) — sonst käme sie mit einem aufgehobenen Link wieder herein.
- Rücksetz-Link gilt 24 h und nur für Konten, deren Garagen alle vom Anfragenden verwaltet werden — sonst könnte der Inhaber von Garage B ein Konto übernehmen, das auch Garage A sieht.
- Die Rücksetzung beendet Browser-Sitzungen (`logoutAll`), lässt feste Zugänge und verbundene Apps stehen — die sind im Konto einzeln widerrufbar, und ein vergessenes Passwort heißt nicht, dass Claude Code abgeklemmt werden soll.
- Die Migration nimmt die Werte aus den Markdown-Akten, prüft sie am Beleg und setzt die Herkunft danach: am PDF belegt → `bestaetigt`, nur in der Akte → `wahrscheinlich`, unbelegte Regel → `unsicher`.

## Summary

## Files touched

## Commits

## Deviations from plan

## Follow-ups
