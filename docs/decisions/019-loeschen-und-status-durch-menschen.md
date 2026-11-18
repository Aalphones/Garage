# 019 — Löschen, Wiederherstellen und Fahrzeugstatus durch Menschen

**Status:** Akzeptiert (2026-11-18) — Meilenstein 4, Planung.

## Kontext

Agenten können falsch angelegte Datensätze nicht entfernen (Projektregel 5); bis Meilenstein 4 ging Korrektur nur über Klärfälle. Projektregel 4: nichts wird hart gelöscht.

## Entscheidung

- **Löschen ist ein Status:** `status = 'geloescht'` mit Grund (`status_reason`, bei `notes` nur im Audit-Log), Audit-Eintrag mit vorher/nachher. Alle Lesepfade filtern schon auf `aktiv` bzw. `<> geloescht`.
- **Datensätze** (Ereignis, Messwert, Reifensatz, Teil, Schaden, Rückruf, Notiz): löschen und wiederherstellen dürfen Inhaber und Mitglieder; die Oberfläche löscht sofort und bietet 6 s „Rückgängig“. Ein Ereignis nimmt seinen Kilometerstand mit. Wiederherstellen ohne Zeitgrenze, aber nicht, wenn inzwischen ein gleicher Datensatz (gleicher Schlüssel) aktiv ist.
- **Fahrzeuge:** Status (aktiv, verkauft, stillgelegt), Löschen (Grund Pflicht) und Wiederherstellen nur Inhaber. Gelöschte Fahrzeuge sind 30 Tage über den Filter „Gelöscht“ wiederherstellbar (Zeitpunkt = `updated_at`), danach nur noch in der Datenbank.
- **Nur Menschen:** eigene Routen mit JWT, kein MCP-Tool.

## Verworfen

- **Bestätigungsdialog statt Rückgängig** bei Datensätzen: kostet bei jedem Löschen einen Klick, schützt nicht besser.
- **Verzögertes Löschen im Browser** (erst nach 6 s senden): geht verloren, wenn die Seite vorher geschlossen wird.
- **Eigene Spalte `deleted_at`:** `updated_at` reicht, solange gelöschte Fahrzeuge nicht bearbeitet werden.

## Konsequenzen

- Nach 30 Tagen bleibt ein gelöschtes Fahrzeug in der Datenbank, ist aber in keiner Oberfläche mehr erreichbar; ein Löschkonzept für Fremde (DSGVO) fehlt weiter (PROJECT.md → Offene Fragen).
- Prognose und Fälligkeiten ändern sich, wenn ein Ereignis mit Kilometerstand gelöscht wird.
