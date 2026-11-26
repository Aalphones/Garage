# 026 — Mitglieder, Einladungen aus der Oberfläche, Passwort-Rücksetzung

**Status:** Akzeptiert (2026-11-26) — Meilenstein 6; erweitert [ADR-006](006-konten-per-einladung-sitzung-je-nutzer.md). Die Passwort-Rücksetzung wird in Phase 2 des Meilensteins gebaut.

## Kontext

Bisher entsteht eine Einladung nur mit dem Deploy-Token, Mitgliedschaften lassen sich nicht ändern, und ein vergessenes Passwort führt nur über eine neue Einladung. Inhaber sollen das selbst erledigen. GARAGE verschickt keine E-Mails.

## Optionen

- **Passwort vergessen:** (a) Rücksetz-Link per E-Mail an das Mitglied — braucht Mailversand und Zustellbarkeit; (b) der Inhaber erzeugt den Link und gibt ihn selbst weiter; (c) der Inhaber setzt ein neues Passwort — dann kennt er es.
- **Offene Einladungen eines Inhabers, der die Rolle verliert:** (a) gültig lassen, verbleibende Inhaber widerrufen von Hand; (b) mitwiderrufen.

## Entscheidung

- **Inhaber verwalten ihre Garage im Konto:** einladen (`inhaber`/`mitglied`/`lesend`, 1–30 Tage), Einladungen widerrufen, Rollen ändern, Mitglieder entfernen. Jedes Mitglied sieht die Mitgliederliste und kann die Garage verlassen; offene Einladungen sehen nur Inhaber. Alle Routen verlangen die Anmeldung eines Menschen, nie ein Agenten-Token.
- **Neue Garagen** per Einladung bleiben beim Deploy-Token.
- **Beitreten mit bestehendem Konto:** derselbe Einladungs-Link, angemeldet angenommen; kein zweites Konto. Wer schon Mitglied ist, verbraucht die Einladung nicht.
- **Mindestens ein Inhaber:** Herabstufen und Entfernen sperren in ihrer Transaktion zuerst die Inhaber-Zeilen (`SELECT … FOR UPDATE`) und prüfen den Handelnden danach erneut.
- **Verliert jemand die Inhaber-Rolle** (herabgestuft, entfernt, selbst gegangen), werden die offenen Einladungen dieser Garage, die diese Person erzeugt hat, in derselben Transaktion widerrufen (Option b). Sonst käme sie mit einem aufgehobenen Link wieder herein, auch als Inhaber.
- **Entfernen löscht die Zeile** in `garage_members` und schreibt einen Audit-Eintrag: eine Mitgliedschaft ist kein Fakt mit Herkunft. Rollen werden bei jeder Anfrage neu gelesen, der Zugriff endet sofort.
- **Passwort-Rücksetzung (Option b):** ein Inhaber erzeugt für ein Mitglied einen einmaligen Link (`gpwr_`, nur als SHA-256 gespeichert, 24 Stunden). Nur für Konten, deren Garagen alle der Anfragende als Inhaber verwaltet — sonst könnte der Inhaber einer Garage ein Konto übernehmen, das auch eine fremde sieht. Das neue Passwort beendet alle Browser-Sitzungen; feste Zugänge und verbundene Apps bleiben, sie sind im Konto einzeln widerrufbar.

## Konsequenzen

- Wer einen Link in die Hände bekommt, kann ihn benutzen: Einladung und Rücksetz-Link sind so vertraulich wie der Weg, auf dem sie verschickt werden. Die E-Mail einer Einladung ist nur eine Notiz, kein Abgleich.
- Einladungen aus der Zeit vor Migration 025 und solche per Deploy-Token haben keinen Ersteller und werden nie automatisch widerrufen.
- Der einzige Inhaber einer Garage, der sein Passwort vergisst, hat keinen Rücksetz-Weg über einen anderen Menschen.
- Wer in einer Garage Mitglied ist, die ein anderer verwaltet, bekommt von keinem einzelnen Inhaber einen Rücksetz-Link, solange keiner Inhaber aller seiner Garagen ist.
