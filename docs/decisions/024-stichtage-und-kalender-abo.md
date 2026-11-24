# 024 — Stichtage und Kalender-Abo

**Status:** Akzeptiert (2026-11-24) — Meilenstein 5, Phase 1.

## Kontext

Kündigungsfristen, Sonderkündigungsrechte, Plaketten und Garantien verfallen unbemerkt. Die Akte soll sie nennen und in den Kalender der Nutzer bringen, ohne dass dort eine Anmeldung nötig ist.

## Entscheidung

- **Stichtage werden gerechnet,** nicht gespeichert: Kündigung = nächste Hauptfälligkeit − `notice_months` Monate − 1 Tag; Sonderkündigung = `occurred_on` einer offenen Beitragsrechnung, deren Betrag über der letzten Periode liegt, + 1 Monat; Steuer und Club zur Hauptfälligkeit; Plakette `valid_until`; Zubehör `warranty_until`.
- **Ein Kalender-Abo je Garage** unter `/garage/kalender/<garage_id>-<sig>.ics`; `sig` = erste 32 Zeichen von base64url(HMAC-SHA256(Schlüssel wie `PrintLink`, `"calendar|<garage_id>|<calendar_version>"`)).
- **Erneuern** zählt `garages.calendar_version` hoch; der alte Link antwortet 404.
- **Inhalt:** Fälligkeiten mit `effective_date` und alle Stichtage der aktiven Fahrzeuge, ganztägig.

## Verworfen

- **Token in der Datenbank:** müsste zum Kopieren lesbar bleiben.
- **Abo je Nutzer:** Termine gehören der Garage.

## Konsequenzen

- Wer den Link hat, sieht Kennzeichen und Termine ohne Anmeldung; Erneuern ist die einzige Sperre (nur der Inhaber).
- Fremde Kalender holen Abos selten (Stunden bis ein Tag); neue Termine erscheinen verzögert.
