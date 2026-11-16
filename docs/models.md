# Datenmodell — GARAGE

Quelle der Wahrheit sind die Migrationen in `app/migrations/`. Geplantes Modell (44 Tabellen in sechs Bereichen): [`design/konzept-garage.md`](design/konzept-garage.md) → „Datenmodell".

## Bestehende Tabellen

| Tabelle | Spalten | Angelegt von |
|---|---|---|
| `schema_migrations` | `filename` VARCHAR(190) PK, `applied_at` DATETIME (UTC) | `Db\Migrator` selbst |
| `users` | `id` PK, `email` UNIQUE (klein geschrieben), `name`, `password_hash`, `status` ENUM(`aktiv`,`gesperrt`), `created_at`, `last_login_at` | `001_user.sql` |
| `garages` | `id` PK, `name`, `created_at` | `002_garage.sql` |
| `garage_members` | PK (`garage_id` FK, `user_id` FK), `role` ENUM(`inhaber`,`mitglied`,`lesend`), `created_at`; Index auf `user_id` | `002_garage.sql` |
| `invitations` | `id` PK, `token_hash` CHAR(64) UNIQUE, `garage_id` FK NULL **oder** `garage_name` NULL (genau eins), `role`, `email` NULL (nur Notiz), `expires_at`, `accepted_at`, `accepted_by` FK, `created_at` | `002_garage.sql` |
| `tokens` | `id` PK, `user_id` FK, `token_hash` CHAR(64) UNIQUE, `kind` ENUM(`mcp`,`oauth_access`,`oauth_refresh`,`user_refresh`), `name`, `client_id` NULL, `scope`, `family` CHAR(32) NULL, `expires_at` NULL, `revoked`, `last_used_at`, `created_at`; Index `(user_id, kind, revoked)` | `003_token.sql` |
| `login_attempts` | `id` PK, `ip_hash` CHAR(64) (HMAC, nie die IP), `attempted_at`; Index `(ip_hash, attempted_at)` | `003_token.sql` |
| `audit_log` | `id` PK, `garage_id` NULL, `user_id` NULL, `actor`, `action`, `target`, `before_json`, `after_json`, `created_at`; Index `(garage_id, created_at)` | `004_audit.sql` |

Tokens, Einladungen und Passwörter liegen nur als Hash in der Datenbank; Klartext gibt es einmal bei der Ausgabe.

## Regeln für jede neue Tabelle

- Jede Tabelle mit Fakten trägt `garage_id`, `source`, `source_document_id` oder `source_url`, `confidence` und den Akteur.
- Zeitstempel `DATETIME` in UTC; gelöscht wird per Status mit Grund, nie hart.
- `utf8mb4`, InnoDB, Fremdschlüssel ausgeschrieben.
