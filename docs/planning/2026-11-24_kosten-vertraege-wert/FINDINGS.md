# Findings — Meilenstein 5

Getaggte Erkenntnisse aus der Umsetzung. Format: `- [ ] → Phase N: <Erkenntnis>` (erledigt: `[x]`).

- [ ] → Phase 5: Feste Anteile (`contract_allocations`) schreibt `record_contract_term` als Satz mit `valid_from` der Periode, `valid_to` bleibt leer. Gültig an einem Tag ist der Satz mit dem jüngsten `valid_from` ≤ Tag; ein darin fehlendes Fahrzeug hat 0 %. So rechnet `ContractService::shareFor` — die Kostenrechnung sollte dieselbe Regel nutzen. `gleich` zählt dort nur die heute aktiven Autos; die Zeiträume (ADR-022) rechnet Phase 5.
- [ ] → Phase 5: `get_papers` → `share.eur_per_year` rechnet den Beitrag der heute gültigen Periode (sonst der jüngsten) auf 365 Tage um; die Kostenrechnung soll das nicht übernehmen, sondern tagesgenau umlegen.
- [ ] → Phase 10: Einen Vertrag löschen lässt seine Perioden und Vorgänge stehen; sie verschwinden mit ihm aus `get_papers` und kommen mit ihm zurück. Ein Vorgang an einem gelöschten Vertrag gilt beim Löschen/Wiederherstellen als unbekannt (404) — die Oberfläche bietet dort keinen Knopf.
- [ ] → Phase 13: Audit-Aktionen der Verträge heißen nach der Tabelle (`contracts.create|update|dispute`, `contract_terms.…`, `contract_allocations.…`, `contract_events.…`) wie beim übrigen Abgleich.

