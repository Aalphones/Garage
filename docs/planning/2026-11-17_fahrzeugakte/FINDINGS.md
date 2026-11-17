# Findings — Meilenstein 2

Format: `- [ ] → Phase N: <Erkenntnis>` — offen, bis die betroffene Phase sie eingearbeitet hat.

- [ ] → Phase 2, 4: Neue Listen-Tabellen gehören an zwei Stellen eingetragen: `RecordMerger::TABLES` (Schlüssel, abgleichbare Spalten) und `RecordRepository::ORDER` (Sortierung). Das Repository hat keinen `$orderBy`-Parameter (Abweichung von der Phasen-Datei, Sortierung bleibt Konstante).
- [ ] → Phase 5: Die Prüfung von `open_items.proposal_json` und `audit_log.vehicle_id` ist in Phase 1 nur im Code belegt (kein Lese-Zugang von außen). Sobald der Verlauf/die Klärfall-Liste Daten liefert, per `curl` gegenprüfen.
