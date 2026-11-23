# Findings — Meilenstein 4

Erkenntnisse während der Umsetzung, die eine spätere Phase betreffen. Format: `- [ ] → Phase N: <Erkenntnis>`; abgehakt, sobald die Zielphase sie aufgenommen hat.

- [ ] → Phase 8: Die Druckansicht (`Api/PrintController`) sendet `Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; …` — das Druck-CSS muss als eigene Datei unter `/garage/` kommen (kein Inline-Stil, kein Skript). Seite und 404-Seite baut `PrintController::page`; dort die Vorlage einsetzen.
- [ ] → Phase 8: Ein wiederhergestelltes Ereignis bringt seinen Kilometerstand mit dem alten `reading_status` zurück, ohne ihn gegen inzwischen erfasste Stände zu prüfen. Passt er nicht mehr, rechnet die Prognose mit einem Widerspruch, bis ein neuer Stand einen Klärfall auslöst. Für die Rückgängig-Leiste (6 s) unkritisch; bei späterem Wiederherstellen sichtbar.
