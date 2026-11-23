# Findings — Meilenstein 4

Erkenntnisse während der Umsetzung, die eine spätere Phase betreffen. Format: `- [ ] → Phase N: <Erkenntnis>`; abgehakt, sobald die Zielphase sie aufgenommen hat.

- [x] → Phase 8: Die Druckansicht (`Api/PrintController`) sendet `Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; …` — das Druck-CSS muss als eigene Datei unter `/garage/` kommen (kein Inline-Stil, kein Skript). Seite und 404-Seite baut `PrintController::page`; dort die Vorlage einsetzen.
- [x] → Phase 8 (nicht berührt: die Oberfläche stellt Datensätze nur über die Rückgängig-Leiste wieder her, Fahrzeug-Wiederherstellen ändert keine Ereignisse): Ein wiederhergestelltes Ereignis bringt seinen Kilometerstand mit dem alten `reading_status` zurück, ohne ihn gegen inzwischen erfasste Stände zu prüfen. Passt er nicht mehr, rechnet die Prognose mit einem Widerspruch, bis ein neuer Stand einen Klärfall auslöst. Für die Rückgängig-Leiste (6 s) unkritisch; bei späterem Wiederherstellen sichtbar.
