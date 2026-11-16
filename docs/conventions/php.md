# PHP Conventions — GARAGE

> **Source-of-truth references:**
> - [PSR-12](https://www.php-fig.org/psr/psr-12/), [PSR-4](https://www.php-fig.org/psr/psr-4/)
> - Fachkonzept: [`../design/konzept-garage.md`](../design/konzept-garage.md) → „Datenmodell", „Regeln im Code, nicht im Prompt"
> - Schwesterprojekt auf demselben Stack: [DISPATCH](https://github.com/Aalphones/Dispatch), Quelle der kopierten Bausteine (ADR-005)
>
> Projekt-Regeln unten haben Vorrang vor allgemeinen Coding-Style-Regeln.

## Stack

| Layer | Choice |
|---|---|
| PHP | 8.5, `declare(strict_types=1)` in jeder Datei |
| Framework | keins; eigener PSR-4-Autoloader `Garage\` → `app/src/` |
| DB | MySQL, PDO, `utf8mb4`, InnoDB, ausschließlich Prepared Statements |
| Abhängigkeiten | keine — kein Composer, kein `vendor/` |
| Tests | keine (ADR-004) |

## Library Policy

**Forbidden:**
- Composer-Pakete jeder Art — Shared Hosting ohne Composer.
- Kryptografie-Eigenbau jenseits der PHP-Bordmittel.

**Allowed:**
- `random_bytes`, `hash`, `hash_hmac`, `hash_equals`, `password_hash`/`password_verify` — Tokens, JWT (HS256 fest), signierte Links.
- `openssl` — Prüfung von ID-Tokens gegen die Schlüssel von Google und Apple.
- GD für Bildvarianten, `curl` nur über den HTTP-Client mit SSRF-Schutz.

## Regeln

- PSR-12, typisierte Properties, Parameter und Return-Types überall.
- Services kennen kein HTTP: kein `$_SERVER`, `$_GET`, `header()` in `Domain/`. MCP, Web-API und CLI nutzen dieselben Services.
- **Mandantentrennung im Repository:** Jede Methode bekommt den Akteur und hängt die Bedingung auf dessen `garage_id`s selbst an. Eine Abfrage ohne Akteur gibt es nicht.
- **Herkunft ist Pflicht:** Jede Faktenzeile schreibt `source`, Beleg oder URL, `confidence` und den Akteur.
- **Widerspruch überschreibt nicht:** Ein Wert gegen einen bestätigten Wert landet daneben und öffnet einen Klärfall.
- Kilometer laufen nur vorwärts; ein kleinerer Stand nach einem größeren wird gespeichert und öffnet einen Klärfall.
- Nichts wird hart gelöscht: Löschen ist ein Status mit Grund, sichtbar im Audit-Log.
- Berechnete Werte (Fälligkeiten, Kilometerprognose, Unterhalt) entstehen beim Lesen und werden nie gespeichert.
- Zeitstempel als `DATETIME` in UTC, geschrieben mit `UTC_TIMESTAMP()` und in der Abfrage verglichen, nie in PHP.
- Mehrere Tabellen ändern → Transaktion. Ausnahme Migrationen: DDL nie in eine PDO-Transaktion.
- Kein `@` zur Fehlerunterdrückung.
- Fehler der API als JSON `{"error": "…", "message": "…"}`; fachliche Fehler im MCP als Tool-Ergebnis mit `isError: true` und einem Beispiel, wie es richtig geht.
- `.env`-Werte stehen in einfachen Anführungszeichen.
- Kommentare nur, wo das Warum nicht offensichtlich ist (Strato-Eigenheit, Sicherheitsgrund, Invariante).

## Project Layout

Siehe [`../code-map.md`](../code-map.md).

## Critical Rules

1. **Keine Abfrage ohne Akteur** — eine vergessene `garage_id`-Bedingung zeigt einem Nutzer die Autos eines anderen.
2. **Prepared Statements ausnahmslos** — auch für interne Abfragen.
3. **Kein Token kann löschen, Mitglieder verwalten, Fahrzeuge übergeben oder Klärfälle lösen** — das hängt an keinem Scope, nur an der Anmeldung eines Menschen.
4. **Dateien laufen nie durch das Sprachmodell** — Upload über Ticket, Datei-Parameter oder Widget; Typ anhand der Bytes prüfen.
5. **Ausgehende Anfragen nur über den HTTP-Client mit SSRF-Schutz** — CIMD-Abruf und Import von URLs laden fremde Adressen.
6. **Der JWT-Algorithmus steht serverseitig fest** — ein `alg` aus dem Token wird nie übernommen.
