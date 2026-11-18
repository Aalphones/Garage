# Phase 6 — Claude-Widget

**Rating:** heikel (neue Protokoll-Erweiterung, Upload aus fremdem Ursprung). Modell: `opusplan`.

## Kontext — vor dem ersten Edit lesen

- Plan-README: [`README.md`](README.md) — E6, E15, Kontrakt (`store_document` ohne Datei, `confirm_document`, `OPTIONS /garage/upload/{slug}`).
- Entwurf: [`artifacts/entwurf-belege.html#widget`](artifacts/entwurf-belege.html) — nur der Kasten „Beleg ablegen · <Fahrzeug>“ ist das Widget; Zustände `wartet`, `laedt`, `fertig`, `fehler` über die Links unter dem Kasten.
- MCP-Apps-Spezifikation 2026-01-26: https://github.com/modelcontextprotocol/ext-apps/blob/main/specification/2026-01-26/apps.mdx (gelesen 2026-11-17). Kern: Resource-MIME `text/html;profile=mcp-app`; am Tool `_meta.ui.resourceUri` (Adresse `ui://…`) und `_meta.ui.visibility` (`["model","app"]` Standard, `["app"]` = nur App); an der Resource (in `resources/read`-Inhalt) `_meta.ui.csp.connectDomains`, `resourceDomains`, `prefersBorder`; Nachrichten per `postMessage` als JSON-RPC 2.0: App → Host Anfrage `ui/initialize`, danach Benachrichtigung `ui/notifications/initialized`; Host → App `ui/notifications/tool-input` und `ui/notifications/tool-result`; App → Host Anfrage `tools/call`. Host prüft Sichtbarkeit; Server SOLL die Client-Fähigkeit `io.modelcontextprotocol/ui` prüfen (kein MUSS — GARAGE meldet das Widget immer an, E15).
- Report-Back Phasen 1–2; GARAGE: `app/src/Mcp/Tool.php` (`meta` seit Phase 2), `app/src/Mcp/Resources.php` (`list`, `read`, `content`), `app/src/Api/UploadController.php`, `DocumentService::issueTicket`/`storeBytes` (`intoDocumentId`), `app/src/Mcp/Tools/DocumentTools.php`.
- Vault-Fehlerklassen: `~/.claude/knowledge/protokolle/mcp.md` → „Server nach altem Schema“ — Resource und `_meta` müssen in beiden Protokoll-Generationen gleich ausgeliefert werden (`resources/read` geht durch dieselbe `McpController`-Methode, nichts an `Protocol` ändern).

## Abnahmekriterien

- [x] `tools/list`: `store_document` trägt `_meta.ui.resourceUri = "ui://garage/beleg-ablegen"` (zusätzlich zu `openai/fileParams`); `confirm_document` trägt `_meta.ui.visibility = ["app"]`.
- [x] `resources/list` enthält `ui://garage/beleg-ablegen` (Name „Beleg ablegen“, MIME `text/html;profile=mcp-app`); `resources/read` liefert den HTML-Text mit `_meta.ui.csp.connectDomains = ["https://quantum-canvas.de"]` (aus `base_url`), `resourceDomains = []`, `prefersBorder = true`. Lesen braucht `garage:read` wie alle Resources.
- [x] `OPTIONS /garage/upload/<slug>` → 204 mit `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Methods: POST, OPTIONS`, `Access-Control-Allow-Headers: Content-Type`, `Access-Control-Max-Age: 600`; `POST` antwortet ebenfalls mit `Access-Control-Allow-Origin: *` (auch bei Fehlern). Keine andere Route bekommt CORS.
- [x] Widget lokal geprüft mit einer Test-Hülle im Scratchpad (Iframe + `postMessage`-Antworten auf `ui/initialize` und `tools/call`): Zustände „wartet“ → Datei wählen → „lädt n %“ → `confirm_document` → „Abgelegt“; abgelaufenes Ticket (410) → „Upload abgelaufen …“ + „Neue Adresse holen“ (ruft `request_upload {"document_id": …}`) → wieder „wartet“.
- [x] `confirm_document` auf ein Dokument mit Datei → `{document_id, status, attached_to}`; auf `wartet` → `invalid_value` „Datei noch nicht angekommen“; fremdes Dokument → `not_found`.
- [ ] Inhaber in Claude Web: Bild in den Chat, „leg das beim Golf ab“ → Agent ruft `store_document` ohne Datei, Widget erscheint, Datei hineinziehen → Dokument nicht mehr `wartet`. Ergebnis (auch Fehlschlag) in die Verprobungs-Matrix (Phase 7).

## Checkliste

- [x] `request_upload` um `document_id?` erweitern: Ticket wird an ein Dokument `wartet` derselben Garage gebunden (anderer Status → `invalid_value`); `redeemTicket` füllt dann genau dieses Dokument (`intoDocumentId`).
- [x] `store_document` ohne Datei: Ergebnis enthält `upload` (Ticket für dieses Dokument) und `widget: true`; `ToolResult::json` bekommt zusätzlich `structuredContent` (gleiches Objekt) — `ToolResult` um optionales `structuredContent` erweitern, `toArray()` gibt es mit aus (beide Generationen dulden das Feld).
- [x] `DocumentService::confirm(int $documentId, Actor $actor): array` und Tool `confirm_document` (Scope `FILES`, Annotation `W`, `meta: ['ui' => ['visibility' => ['app']]]`).
- [x] `store_document`-`meta` → `['openai/fileParams' => ['file'], 'ui' => ['resourceUri' => 'ui://garage/beleg-ablegen']]`.
- [x] `Resources`: Eintrag in `list()`; `read('ui://garage/beleg-ablegen')` → `['uri' => …, 'mimeType' => 'text/html;profile=mcp-app', 'text' => file_get_contents(app/mcp/widget/beleg-ablegen.html), '_meta' => ['ui' => ['csp' => ['connectDomains' => [base_url], 'resourceDomains' => []], 'prefersBorder' => true]]]`. `base_url` aus `$services['config']`.
- [x] `app/mcp/widget/beleg-ablegen.html` — eine Datei, Stil und Skript eingebettet (das Widget läuft im Rahmen des Hosts, nicht unter der GARAGE-CSP), keine Bibliothek, keine externen Ressourcen. Ablauf:
  1. `postMessage` an `window.parent`: `{"jsonrpc":"2.0","id":1,"method":"ui/initialize","params":{"protocolVersion":"2026-01-26","appInfo":{"name":"garage-beleg","version":"1"},"appCapabilities":{}}}`; nach Antwort `{"jsonrpc":"2.0","method":"ui/notifications/initialized"}`.
  2. Auf `ui/notifications/tool-result` warten: Ergebnis aus `structuredContent`, sonst JSON aus `content[0].text`. Ist `status` ≠ `wartet` → Zustand „Abgelegt“ ohne Ablagefläche.
  3. Kopf „Beleg ablegen · <vehicle_label>“, Zusammenfassung der Felder (`doc_type`, `title`, `captured_on`), „Hängt an …“ falls vorhanden.
  4. Ablagefläche (Ziehen und `<input type=file accept="image/*,application/pdf">`), Fotos vorher wie Phase 4 auf 2560 px verkleinern (Code aus `web/js/capture.js` kopieren, nicht importieren), `XMLHttpRequest` POST roh an `upload.upload_url` mit Fortschritt.
  5. Erfolg → `tools/call` `{"name":"confirm_document","arguments":{"document_id":…}}` → Zustand „Abgelegt“. 410 → Zustand „abgelaufen“; „Neue Adresse holen“ → `tools/call request_upload {"document_id": …}`.
  6. Hinweis „Klappt es hier nicht? Leg die Datei in den Eingang“ mit Link `<base_url>/garage/eingang.html` (`target="_blank"`, `rel="noopener"`).
  Fehler und Zeitüberschreitungen (keine Antwort auf `ui/initialize` nach 5 s) → Text „Das Widget wird hier nicht unterstützt. Leg die Datei in den Eingang.“
- [x] `Api\UploadController`: `options()` und CORS-Kopfzeilen; Route `OPTIONS /garage/upload/{slug}` in `app/index.php` (Router auf `OPTIONS` prüfen, sonst ergänzen).
- [x] `app/mcp/instructions.md`: in Claude ohne Dateizugriff `store_document` ohne Datei aufrufen und den Menschen bitten, die Datei ins Widget zu ziehen; scheitert das, Eingang nennen.
- [x] ADR-015 Teil „Widget“ ergänzen (E15; verworfen: Base64 über `tools/call` aus dem Widget — Größe und Host-Grenzen unbekannt).
- [x] `docs/routes.md` (Resource, Tools, OPTIONS), `docs/code-map.md` (`mcp/widget/`).

## Report-Back

**Status:** umgesetzt und deployt (2026-11-18); offen nur der Test in Claude Web beim Inhaber (Kästchen oben, `docs/knowledge/GAPS.md`).

**Ergebnis:** `request_upload` mit `document_id`, `store_document` ohne Datei liefert `widget: true`, Kopfangaben und `upload` auch als `structuredContent` (`ToolResult::structured`), neues Tool `confirm_document` (nur App), Resource `ui://garage/beleg-ablegen` mit `_meta.ui` (CSP, Rahmen), Widget `app/mcp/widget/beleg-ablegen.html`, CORS für `OPTIONS`/`POST /garage/upload/{slug}`, Anweisungen, ADR-015 (Teil Widget), `routes.md`, `code-map.md`.

**Geprüft:**
- Lokal mit Test-Hülle (Chrome headless, Widget und Upload-Stub auf verschiedenen Ursprüngen): `ui/initialize` → `initialized` → Zustand „wartet“ mit Kopf „Beleg ablegen · Golf“; 3000×2000-PNG wird als JPEG verkleinert hochgeladen, danach `confirm_document` → „Abgelegt“; 410 → „Upload abgelaufen …“ + „Neue Adresse holen“ (`request_upload {"document_id":5}`) → „wartet“ → Upload → „Abgelegt“.
- Strato (16 Prüfungen gegen die Testkonten aus Phase 3): `tools/list` (`_meta.ui.resourceUri`, `visibility ["app"]`), `resources/list`, `resources/read` (MIME, `connectDomains ["https://quantum-canvas.de"]`, `resourceDomains []`, `prefersBorder`), Lesen auch mit Rolle `lesend`, `structuredContent` gleich dem Text, `confirm_document` auf `wartet` → `invalid_value`, fremd → `not_found`, Preflight 204 mit allen vier Kopfzeilen, Upload füllt das wartende Dokument, danach `confirm_document` → `unzugeordnet`; `request_upload` auf Dokument mit Datei → `invalid_value`; benutztes Ticket → 410 mit CORS; `/garage/api/health` ohne CORS.

**Abweichungen:** `store_document`-Ergebnis trägt zusätzlich `vehicle_label`, `doc_type`, `title`, `captured_on` und `max_bytes`/`accepted` im `upload`-Block, damit das Widget ohne zweiten Aufruf auskommt. `request_upload` mit `document_id` und `issueTicket` gab es schon aus Phase 3, nur das Tool-Argument fehlte.

**Risiko 🟡:** Ob Claude Web `ui/notifications/tool-result` mit `structuredContent` sendet und `connectDomains` für `XMLHttpRequest` anwendet, ist nur gegen die Spezifikation und die Test-Hülle belegt. Test-Hülle liegt im Scratchpad, nicht im Repo (ADR-004).
