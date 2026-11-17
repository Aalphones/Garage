# 013 — Belegbezug über Anhänge

**Status:** Akzeptiert (2026-11-17) — Meilenstein 3, Phase 2.

## Kontext

Ein Beleg (Rechnung, Foto) gehört zu einem Datensatz der Akte: Ereignis, Schaden, Teil, Kilometerstand und weitere. Die Schreibwege aus Meilenstein 2 kennen keine Dokumente, und ein Dokument kann an mehreren Datensätzen hängen (eine Rechnung belegt Ereignis und Messwert).

## Entscheidung

- **Ein eigenes Tool `attach_document`** verbindet ein Dokument mit Fahrzeug, Ereignis, Schaden, Rückruf, Reifensatz, Teil, Messwert, Kilometerstand oder Zulassung. Rolle `beleg`, `foto`, `anhang` oder `titelbild`. Die erlaubten Ziele stehen in `DocumentService::TARGETS` (Name im Tool → Tabelle); `attachments.target_table` ist ein Text, Meilenstein 5 ergänzt Verträge ohne Migration.
- **Bei Rolle `beleg`** setzt GARAGE in der Zielzeile `source_document_id`, wenn die Spalte leer ist; ein schon gesetzter Wert bleibt (`source_document_set: false`). Ein Anhang ist idempotent (`unveraendert`).
- **Das Dokument muss zum Fahrzeug des Ziels passen**; ein Dokument ohne Fahrzeug bekommt beim ersten Anhang dessen Fahrzeug. Ein Ziel einer anderen Garage oder eines anderen Fahrzeugs ist `not_found`. Dokumente im Status `wartet` oder `verworfen` lassen sich nicht anhängen.
- **Ein Titelbild** gibt es nur für Fotos am Fahrzeug; ein neues löst das alte ab (dessen Anhang wird Rolle `foto`).
- **Erkannter Text** (`text` an `attach_document`) ersetzt `documents.text_content`; die Suche findet ihn (Quelle `document`).

## Verworfen

- **`source_document_id` in jedes Schreib-Tool:** neun Schreibwege ändern sich, jeder Aufruf müsste das Dokument schon kennen (der Beleg wird oft erst nach der Erfassung abgelegt), und der Datensatz-Abgleich (ADR-009) bekäme einen weiteren Vergleichswert.

## Konsequenzen

- `source_document_id` am Datensatz ist nur die erste Quelle; alle Belege stehen in `attachments`. `get_event` und `get_vehicle` lesen sie von dort.
- Ein Dokument im Status `zugeordnet` hat mindestens einen Anhang; es gibt keinen Weg, Anhänge zu lösen (Agenten-Tokens können nicht löschen, Regel 5 in `AGENTS.md`).
