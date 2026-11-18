# 015 — Weg A: Datei-Parameter und Widget

**Status:** Teil „Datei-Parameter“ akzeptiert (2026-11-17, Meilenstein 3, Phase 2); Teil „Widget“ akzeptiert (2026-11-18, Phase 6).

## Kontext

Dateien dürfen nicht durch das Sprachmodell laufen (ADR-012). Programme mit Dateizugriff nutzen das Upload-Ticket; ChatGPT hat dafür Datei-Parameter, Claude bekommt ein Widget.

## Entscheidung — Datei-Parameter (ChatGPT)

- `store_document` trägt `_meta: {"openai/fileParams": ["file"]}`; `file` ist ein Objekt `{download_url, file_id, mime_type?, file_name?}`, Pflicht sind `download_url` und `file_id`. Quelle: OpenAI Apps SDK, Referenz (gelesen 2026-11-17).
- GARAGE holt die Datei über `Util\HttpClient` ab (nur öffentliche Adressen, Größenbegrenzung 30 MB, Weiterleitungen nach Konfiguration); `channel = chatgpt`. Eine abgewiesene Adresse ist `invalid_value` mit der Meldung des Clients. Typ und Größe prüft die Ablage nach den Bytes (ADR-012), nicht der Dateityp des Clients.
- Genau eins von `ticket_id`, `file` oder keins (→ `wartet`); `ticket_id` und `file` zusammen sind `invalid_argument`.
- `import_document_from_url` (Scope `garage:files`) nutzt denselben Weg für eine beliebige öffentliche Adresse, `channel = url`.
- `Tool` trägt dafür ein optionales `meta`, das `tools/list` als `_meta` ausgibt.

## Entscheidung — Widget (Claude)

Claude hat keinen Datei-Parameter und keinen Dateizugriff im Browser. `store_document` ohne Datei legt ein Dokument im Status „wartet“ an und trägt `_meta.ui.resourceUri = ui://garage/beleg-ablegen` (MCP Apps, Spezifikation 2026-01-26). Der Host zeigt das Widget, der Mensch zieht die Datei hinein, das Widget lädt sie per `XMLHttpRequest` roh an die Upload-Adresse aus dem Ergebnis (`structuredContent.upload`) und ruft danach `confirm_document` (nur für die App sichtbar).

- GARAGE meldet das Widget immer an, ohne die Client-Fähigkeit `io.modelcontextprotocol/ui` zu prüfen; ein Client ohne Widget zeigt nur den Text, die Anweisungen nennen dann den Eingang.
- Die Resource liefert `csp.connectDomains` = eigener Ursprung (dorthin geht der Upload), `resourceDomains` leer; das Widget ist eine Datei mit eingebettetem Stil und Skript.
- Upload aus fremdem Ursprung braucht CORS: nur `OPTIONS` und `POST /garage/upload/{slug}` antworten mit `Access-Control-Allow-Origin: *` (die Adresse ist selbst die Berechtigung, es gibt weder Cookie noch Anmeldung).
- Abgelaufenes Ticket (410): das Widget holt mit `request_upload {document_id}` eine neue Adresse für dasselbe Dokument.
- Ohne Antwort auf `ui/initialize` binnen 5 s zeigt das Widget „Das Widget wird hier nicht unterstützt. Leg die Datei in den Eingang.“

## Verworfen

- **Datei als Base64 im Argument:** siehe ADR-012.
- **Base64 über `tools/call` aus dem Widget:** Größe und Grenzen des Hosts für Tool-Aufrufe sind unbekannt, und die Bytes würden durch den Host statt direkt zu GARAGE laufen.

## Konsequenzen

- Ob die ChatGPT-Adresse öffentlich abrufbar ist, zeigt erst die Verprobung mit ChatGPT (Inhaber); Rückfall ist der Eingang (Phase 3).
