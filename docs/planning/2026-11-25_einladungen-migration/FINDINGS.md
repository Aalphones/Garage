# Findings — Meilenstein 6

Format: `- [ ] → Phase N: <Erkenntnis>` (erledigt: `[x]`, mit Commit oder Klärfall-Nummer).

- [x] → Phase 2: Wer die Inhaber-Rolle verliert, dessen offene Einladungen werden jetzt widerrufen (ADR-026). Ein vorher erzeugter Rücksetz-Link darf ebenso wenig weiterwirken: beim Annehmen (und in `preview`) erneut prüfen, dass `created_by` noch Inhaber aller Garagen des Ziel-Kontos ist.
- [x] → Phase 2: Bausteine vorhanden: `Domain\GarageAccess` (404/403, Sperre der Inhaber-Zeilen), `Api\BodyField`, Router-Platzhalter `{userId}`. Die Tabelle `password_resets` liegt in `025_members.sql`.
- [x] → Phase 3: Nach Rolle ändern oder Entfernen die ganze Mitgliederliste neu laden (offene Einladungen der Person verschwinden mit) und das im Bestätigungstext sagen. Wer sich selbst herabstuft oder die Garage verlässt, verliert die Verwaltung sofort.
- [x] → Phase 3: Rücksetz-Link: 403 `forbidden` heißt hier meist „das Konto ist auch in einer fremden Garage“, nicht „du bist kein Inhaber“ — die Meldung des Servers anzeigen. Ein Inhaber darf laut Kontrakt auch für sich selbst einen Link erzeugen.
- [x] → Phase 4: `passwort.html`: `preview` und `accept` können 429 `too_many_attempts` liefern (gemeinsame Bremse mit dem Login). 410 `reset_used` deckt auch „nicht mehr gültig“ ab — die Meldung des Servers anzeigen.
