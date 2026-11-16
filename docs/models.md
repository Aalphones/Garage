# Datenmodell — GARAGE

Quelle der Wahrheit sind die Migrationen in `app/migrations/`. Geplantes Modell (44 Tabellen in sechs Bereichen): [`design/konzept-garage.md`](design/konzept-garage.md) → „Datenmodell".

## Bestehende Tabellen

| Tabelle | Spalten | Angelegt von |
|---|---|---|
| `schema_migrations` | `filename` VARCHAR(190) PK, `applied_at` DATETIME (UTC) | `Db\Migrator` selbst |

## Regeln für jede neue Tabelle

- Jede Tabelle mit Fakten trägt `garage_id`, `source`, `source_document_id` oder `source_url`, `confidence` und den Akteur.
- Zeitstempel `DATETIME` in UTC; gelöscht wird per Status mit Grund, nie hart.
- `utf8mb4`, InnoDB, Fremdschlüssel ausgeschrieben.
