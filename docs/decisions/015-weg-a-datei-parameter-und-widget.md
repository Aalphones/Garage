# 015 — Weg A: Datei-Parameter und Widget

**Status:** Teil „Datei-Parameter“ akzeptiert (2026-11-17, Meilenstein 3, Phase 2); Teil „Widget“ ergänzt Phase 6.

## Kontext

Dateien dürfen nicht durch das Sprachmodell laufen (ADR-012). Programme mit Dateizugriff nutzen das Upload-Ticket; ChatGPT hat dafür Datei-Parameter, Claude bekommt ein Widget.

## Entscheidung — Datei-Parameter (ChatGPT)

- `store_document` trägt `_meta: {"openai/fileParams": ["file"]}`; `file` ist ein Objekt `{download_url, file_id, mime_type?, file_name?}`, Pflicht sind `download_url` und `file_id`. Quelle: OpenAI Apps SDK, Referenz (gelesen 2026-11-17).
- GARAGE holt die Datei über `Util\HttpClient` ab (nur öffentliche Adressen, Größenbegrenzung 30 MB, Weiterleitungen nach Konfiguration); `channel = chatgpt`. Eine abgewiesene Adresse ist `invalid_value` mit der Meldung des Clients. Typ und Größe prüft die Ablage nach den Bytes (ADR-012), nicht der Dateityp des Clients.
- Genau eins von `ticket_id`, `file` oder keins (→ `wartet`); `ticket_id` und `file` zusammen sind `invalid_argument`.
- `import_document_from_url` (Scope `garage:files`) nutzt denselben Weg für eine beliebige öffentliche Adresse, `channel = url`.
- `Tool` trägt dafür ein optionales `meta`, das `tools/list` als `_meta` ausgibt.

## Entscheidung — Widget (Claude)

Folgt in Phase 6.

## Verworfen

- **Datei als Base64 im Argument:** siehe ADR-012.

## Konsequenzen

- Ob die ChatGPT-Adresse öffentlich abrufbar ist, zeigt erst die Verprobung mit ChatGPT (Inhaber); Rückfall ist der Eingang (Phase 3).
