# Commit Conventions — GARAGE

## Logische Einheit pro Commit

Ein Commit pro abgeschlossener, prüfbarer Änderung — Code und betroffene Doku zusammen. Unabhängige Fixes, die nebenbei auffallen, bekommen einen eigenen Commit. Migrationen sind immer ihr eigener Commit. Refactoring und Verhaltensänderung nie im selben Commit.

## Format (Conventional Commits)

```
<type>(<scope>): <subject>

<body — das Warum>
```

- Types: `feat`, `fix`, `refactor`, `perf`, `docs`, `chore`, `build`, `style`, `ci`
- Scopes: `mcp`, `oauth`, `api`, `auth`, `domain`, `ui`, `db`, `deploy`, `docs`, `setup`
- Subject: Imperativ, kleingeschrieben, kein Punkt, höchstens 72 Zeichen
- Body: das Warum; der Diff zeigt das Was

## Pushen

Direkt auf `main`, explizit mit Branch-Namen: `git push origin main`.

## Critical Rules

1. **Nie `deploy.env`, `app/.env`, `app/.env.local` oder Tokens committen** — sie enthalten Klartext-Zugangsdaten.
2. **Keine echten Belege, Fotos oder Fahrzeugdaten committen** — sie gehören in die Datenbank und nach `storage/`, nicht ins Repository.
3. **Kein Commit, der die PHP-Syntaxprüfung bricht.**
