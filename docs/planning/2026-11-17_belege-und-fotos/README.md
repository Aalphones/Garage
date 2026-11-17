# Meilenstein 3 — Belege und Fotos

Ziel: Belege und Fotos kommen auf zwei Wegen in denselben Speicher und hängen danach an den Datensätzen der Akte. **Weg A (Chat):** Upload-Ticket für Programme mit Dateizugriff, Datei-Parameter für ChatGPT, Widget mit Ablagefläche für Claude. **Weg B (Eingang):** Hochladen im Browser (Kamera, Datei, Android „Teilen → GARAGE“), ein Agent arbeitet den Eingang ab. Menschen sehen Belege auf einer schlichten Seite; Auslieferung nur über signierte Links mit kurzer Laufzeit.

Quelle des Umfangs: [`../../PROJECT.md`](../../PROJECT.md) → Meilenstein 3, Fachkonzept [`../../design/konzept-garage.md`](../../design/konzept-garage.md) → „Fotos und Belege“, „MCP-Oberfläche“. Vorbild für allen neuen Code: Meilenstein 2 (Plan im Archiv [`../../archive/2026-11/2026-11-17_fahrzeugakte/`](../../archive/2026-11/2026-11-17_fahrzeugakte/README.md)), für die Dateiablage DISPATCH (`C:\Users\sasch\develop\Blog\app\src\Domain\MediaService.php`, `Util\ImageProcessor.php`, `Mcp\ImageBlocks.php`, ADR-011 dort). Entwurf der Oberfläche: [`artifacts/`](artifacts/README.md).

## Phasen

| # | Phase | Rating | Status |
|---|---|---|---|
| 1 | [Ablage-Kern](phase-1-ablage-kern.md) — Tabellen, Bildverarbeitung, signierte Links, Upload-Ticket, `request_upload`, `store_document` per Ticket, `get_document`, `list_documents` | heikel | complete |
| 2 | [Anhänge, Datei-Parameter, Akte](phase-2-anhaenge-akte.md) — `attach_document`, ChatGPT-Datei-Parameter, `import_document_from_url`, Belege in `get_event`/`get_vehicle`/Akte/Suche | standard | pending |
| 3 | [Eingang auf dem Server](phase-3-eingang-server.md) — Eingangs-Tabellen, `list_inbox`/`claim_inbox_item`/`release_inbox_item`, Web-API für Belege und Eingang, Prompt `eingang_abarbeiten` | heikel | pending |
| 4 | [Oberfläche Eingang und Belege](phase-4-oberflaeche.md) — Seiten nach Entwurf, Fotos im Browser verkleinern, PDF-Seiten per pdf.js | standard | pending |
| 5 | [Teilen-Ziel Android](phase-5-teilen-ziel.md) — Manifest, Service Worker, Übernahme geteilter Dateien | standard | pending |
| 6 | [Claude-Widget](phase-6-widget.md) — MCP App `ui://garage/beleg-ablegen`, wartende Belege, `confirm_document`, CORS am Upload | heikel | pending |
| 7 | [Abschluss auf Strato](phase-7-abschluss.md) — Mandanten-Liste, Testkonten sperren, Doku, Archiv, Verprobungs-Matrix für den Inhaber | mechanisch | pending |

Reihenfolge ist Pflicht: jede Phase baut auf den Klassen der vorigen. Jede Phase endet mit `deploy.cmd` und Prüfung per `curl` (Phase 4–6 zusätzlich im Browser) auf Strato — lokal gibt es kein MySQL (Stand M2). Nach einem Deploy kurz warten (OPcache), bevor geprüft wird.

## Entscheidungen (fallen mit diesem Plan)

| # | Entscheidung | ADR / Phase |
|---|---|---|
| E1 | **Ein Speicher für beide Wege:** Tabelle `documents`, Dateien unter `app/storage/documents/<garage_id>/<uid>/`. PDFs byte-genau als `original.pdf`; Bilder werden **immer neu kodiert** (`original.jpg`, längste Kante höchstens 4000 px, Qualität 90) plus Varianten `1568.jpg` (für Agenten, Claude verkleinert darüber ohnehin) und `400.jpg` (Vorschau); EXIF/GPS fallen weg, das Aufnahmedatum (`DateTimeOriginal`) wird vorher gelesen und als `captured_on` gespeichert. PNG/WebP/GIF werden zu JPEG (Belege brauchen keine Transparenz). Dubletten über SHA-256 der Eingangs-Bytes **je Garage**: zweites Hochladen liefert das vorhandene Dokument mit `duplicate: true`. | ADR-012 / 1 |
| E2 | **Erlaubte Typen nach Bytes** (`finfo`): JPEG, PNG, WebP, GIF, PDF; höchstens 30 MB je Datei, 50 Megapixel vor dem Dekodieren. HEIC wird abgewiesen (GD kann es nicht) — der Browser-Weg kodiert vorher selbst nach JPEG. | ADR-012 / 1 |
| E3 | **Auslieferung nur über signierte Links**: `GET /garage/files/{slug}`, Slug = `base64url(json{d,f,e})` + `.` + `base64url(hmac_sha256)` mit Schlüssel `hash_hmac('sha256','garage-files',GARAGE_JWT_SECRET)`; 600 Sekunden gültig; kein Login nötig, der Link ist die Berechtigung. Antwort mit `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox`, `Cache-Control: private, max-age=600`. | ADR-012 / 1 |
| E4 | **Upload-Ticket:** `request_upload` liefert eine einmalige Adresse `POST /garage/upload/{slug}` (Slug 32 Hex, in der Datenbank nur als SHA-256), 15 Minuten gültig, gebunden an Nutzer und Garage. Die Adresse nimmt den Body roh (`Content-Type` beliebig) **oder** multipart mit Feld `file`. Erst `store_document` mit `ticket_id` setzt Fahrzeug, Typ und Titel. | ADR-012 / 1 |
| E5 | **Belegbezug über Anhänge** statt `source_document_id` in jedem Schreib-Tool: `attach_document` verbindet ein Dokument mit Fahrzeug, Ereignis, Schaden, Rückruf, Reifensatz, Teil, Messwert, Kilometerstand oder Zulassung (Rolle `beleg`, `foto`, `anhang`, `titelbild`) und setzt bei Rolle `beleg` in der Zielzeile `source_document_id`, wenn die Spalte leer ist. Die M2-Schreibwege bleiben unverändert. `target_table` ist VARCHAR, erlaubte Werte stehen in `DocumentService::TARGETS` (M5 ergänzt Verträge ohne Migration). | ADR-013 / 2 |
| E6 | **„Original fehlt“ ist ein Zustand des Dokuments**, kein Klärfall: `store_document` ohne Datei (Claude im Chat) legt ein Dokument mit Status `wartet` an; das Widget (Phase 6), der Eingang oder die Beleg-Seite reichen die Datei nach. Ohne Widget fordert der Agent per `request_upload` an oder nennt dem Menschen die Beleg-Seite. | ADR-013 / 1, 6 |
| E7 | **Dokument-Status:** `wartet` (Datei fehlt), `unzugeordnet` (Datei da, kein Anhang), `zugeordnet` (mindestens ein Anhang), `verworfen` (Mensch, mit Grund, wiederherstellbar). Nichts wird hart gelöscht, auch nicht die Datei. | ADR-012 / 1, 3 |
| E8 | **Typen:** `doc_type` ENUM `foto`, `rechnung`, `hu_bericht`, `police`, `bescheid`, `fahrzeugschein`, `kaufvertrag`, `sonstiges`; bei Fotos `photo_tag` ENUM `aussen`, `innen`, `motorraum`, `tacho`, `reifen`, `schaden` (NULL erlaubt). | — / 1 |
| E9 | **Erkannter Text** steht in `documents.text_content` (MEDIUMTEXT), geschrieben vom Agenten über `store_document` oder `attach_document`; `get_document` liefert ihn, die Suche findet ihn. GARAGE erkennt selbst keinen Text. | — / 1, 2 |
| E10 | **ChatGPT-Datei-Parameter:** `store_document` trägt `_meta: {"openai/fileParams": ["file"]}`; `file` ist ein Objekt `{download_url, file_id, mime_type?, file_name?}` (Pflicht `download_url`, `file_id`). GARAGE holt die Datei über `Util\HttpClient` (SSRF-Schutz) ab. Genau eins von `ticket_id`, `file`, keins (→ `wartet`). | ADR-015 / 2 |
| E11 | **Eingang:** Tabellen `inbox_items` und `inbox_documents` (Seitenreihenfolge). Status `neu`, `in_arbeit`, `erfasst`, `klaerfall`, `verworfen`. `claim_inbox_item` reserviert mit Frist (Standard 180 Minuten, 10–720) und liefert bis zu 6 Bildblöcke (Kante ≤ 1568 px). **Ablauf der Frist wird beim Lesen ausgewertet**: `in_arbeit` mit `claimed_until < jetzt` gilt als `neu` (kein Cron) — die Prüfung vergleicht den Rohwert `claimed_until`, nie einen abgeleiteten Status. | ADR-016 / 3 |
| E12 | **PDF-Seiten für Agenten** entstehen im Browser mit **pdf.js, lokal unter `web/vendor/pdfjs/`** (Wahl des Inhabers 2026-11-17), einzige Ausnahme der Bibliotheksregel; die CSP bleibt `'self'`. Die Seite rendert jede PDF-Seite (höchstens 20) als JPEG, längste Kante 1568 px, und lädt sie mit dem Original hoch (`pages/<n>.jpg`). PDFs aus dem Chat haben keine Seitenbilder; `get_document` liefert dann Text und einen signierten Link. | ADR-014 / 4 |
| E13 | **Oberfläche nach Entwurf** (Wahl des Inhabers 2026-11-17: erst Entwurf): Seiten `eingang.html` und `belege.html`, funktionaler Platzhalter-Stil aus `base.css` (Gestaltung erst in M4), Navigation „Konto · Eingang · Belege“ auf allen drei Seiten. | — / 4 |
| E14 | **Teilen-Ziel Android** (Wahl des Inhabers): Web-App-Manifest mit `share_target` (POST multipart an `/garage/teilen`), Service Worker `web/sw.js` fängt den POST ab, legt die Dateien in den Cache `garage-geteilt` und leitet auf `/garage/eingang.html?geteilt=1` um. Kein Server-Endpunkt `/garage/teilen`. | — / 5 |
| E15 | **Claude-Widget** (Wahl des Inhabers): Resource `ui://garage/beleg-ablegen` (MIME `text/html;profile=mcp-app`, Datei `app/mcp/widget/beleg-ablegen.html`), `store_document` trägt `_meta.ui.resourceUri`, das Widget lädt per Ticket hoch (CORS `*` nur an `/garage/upload/{slug}`) und ruft `confirm_document` (sichtbar nur für die App, `_meta.ui.visibility: ["app"]`). Das Widget wird immer angemeldet; Clients ohne MCP-Apps ignorieren `_meta`. | ADR-015 / 6 |
| E16 | **Scope `garage:files`** brauchen `request_upload`, `store_document`, `import_document_from_url`, `confirm_document`; OAuth-Standard-Scopes werden `garage:read garage:write garage:files`. Lesende Beleg-Tools brauchen `garage:read`, `attach_document` braucht `garage:write`. | — / 1 |
| E17 | **Nicht in M3:** Text-Erkennung auf dem Server, Verträge/Ausgaben als Anhangsziel (M5), Galerie-Gestaltung und Titelbild in der Oberfläche (M4), iOS-Teilen (geht technisch nicht), Löschkonzept für fremde Nutzer (offene Frage in PROJECT.md). | — |

ADR-Nummern 012–016 sind frei (geprüft 2026-11-17: `docs/decisions/` endet bei 011, keine geparkten Pläne in `docs/planning/`).

## Kontrakt

Gilt für alle Phasen; Abweichung nur per Eintrag in `FINDINGS.md` und Plan-Änderung.

### Neue Klassen

| Klasse | Phase | Aufgabe |
|---|---|---|
| `Util\ImageProcessor` | 1 | aus DISPATCH kopiert und angepasst: `process(string $bytes, string $mime, string $dir): array{width,height,captured_on,files}` — schreibt `original.jpg`, `1568.jpg`, `400.jpg` |
| `Auth\FileLink` | 1 | `sign(int $documentId, string $file, int $ttl = 600): string` (Slug), `verify(string $slug): array{document_id, file}` (wirft `DomainError('not_found')` bei falscher Signatur oder Ablauf) |
| `Domain\DocumentRepository` (Kontrakt-Nachtrag Phase 1: `upload_tickets.is_duplicate`, damit `store_document` das Dublette-Kennzeichen kennt) | 1 | Lesen/Schreiben `documents`, `attachments`, `upload_tickets`, immer mit Akteur |
| `Domain\DocumentService` | 1, 2, 6 | `storeBytes`, `describe` (Metadaten setzen), `get`, `list`, `issueTicket`, `redeemTicket`, `attach` (2), `importFromUrl` (2), `confirm` (6), `filePath`, `TARGETS`, `TYPES`, `TAGS` |
| `Mcp\ImageBlocks` | 1 | aus DISPATCH angepasst: Kante ≤ 1568, höchstens 6 Blöcke, nutzt `1568.jpg` bzw. `pages/<n>.jpg` |
| `Mcp\Tools\DocumentTools` | 1, 2, 6 | die Beleg-Tools |
| `Api\UploadController` | 1, 6 | `POST /garage/upload/{slug}`, ab 6 auch `OPTIONS` |
| `Api\FileController` | 1 | `GET /garage/files/{slug}` |
| `Domain\InboxRepository`, `Domain\InboxService` | 3 | Eingang |
| `Mcp\Tools\InboxTools` | 3 | Eingangs-Tools |
| `Api\DocumentController`, `Api\InboxController` | 3 | Web-API für Menschen |

### Neue MCP-Tools

Herkunft wie M2. Fehler wie M2: `not_found` (auch fremde IDs), `forbidden` (Rolle `lesend`), `invalid_value`, `invalid_argument`; neu `too_large`, `type_rejected`, `too_many_pixels`, `ticket_expired`, `ticket_used`.

| Tool | Scope | Phase | Eingabe (Kern) | Ausgabe (Kern) |
|---|---|---|---|---|
| `request_upload` | files | 1, 6 | `vehicle_id?`, `garage_id?`, ab 6 `document_id?` (Ticket für ein Dokument `wartet`) | `{ticket_id, upload_url, expires_at, max_bytes, accepted}` |
| `store_document` | files | 1, 2, 6 | genau eins von `ticket_id`, `file` (2) oder keins (→ `wartet`); `vehicle_id?`, `doc_type`, `title?`, `photo_tag?`, `captured_on?`, `text?` | `{document_id, status, duplicate, files:{preview, original}, upload?:{ticket_id, upload_url, expires_at}}` (`upload` nur bei `wartet`) |
| `import_document_from_url` | files | 2 | `url`, sonst wie `store_document` | wie `store_document` |
| `get_document` | read | 1 | `document_id`, `page?` (1–20), `half?` (`oben`\|`unten`) | Text-Block `{document, attachments, text_content, download_url}` + Bildblöcke (Foto: `1568.jpg`; PDF mit Seitenbildern: Seite `page` bzw. bis 6 Seiten ab 1) |
| `list_documents` | read | 1 | `vehicle_id?`, `status?` (`wartet`\|`unzugeordnet`\|`zugeordnet`\|`offen` = beide ersten\|`alle`, Standard `alle` ohne `verworfen`), `doc_type?` | `{documents:[{id, vehicle_id, doc_type, title, captured_on, status, page_count, attached_to:[…], created_at}]}` |
| `attach_document` | write | 2 | `document_id`, `target` (`fahrzeug`\|`ereignis`\|`schaden`\|`rueckruf`\|`reifensatz`\|`teil`\|`messwert`\|`kilometerstand`\|`zulassung`), `target_id`, `role`, `text?` | `{attachment_id, result (angelegt\|unveraendert), source_document_set}` |
| `list_inbox` | read | 3 | `status?` (Standard `neu`), `garage_id?` | `{items:[{id, garage_id, vehicle_id, note, status, claimed_by, claimed_until, page_count, created_at, result}]}` |
| `claim_inbox_item` | write | 3 | `id?` (ohne: ältester `neu`), `minutes?` (10–720, Standard 180) | Text `{item, documents:[…], next_steps}` + bis 6 Bildblöcke |
| `release_inbox_item` | write | 3 | `id`, `outcome` (`erfasst`\|`zurueck`\|`klaerfall`), `result` (1–500 Zeichen; bei `zurueck` optional) | `{id, status}` |
| `confirm_document` | files | 6 | `document_id` | `{document_id, status, attached_to}`; nur App-sichtbar |

### Neue Routen

| Methode | Pfad | Schutz | Phase | Antwort |
|---|---|---|---|---|
| POST | `/garage/upload/{slug}` | Ticket im Pfad | 1 | 201 `{ticket_id, document_id, status, duplicate}`; 404 `ticket_not_found`, 410 `ticket_expired`/`ticket_used`, 413 `too_large`, 415 `type_rejected` |
| OPTIONS | `/garage/upload/{slug}` | — | 6 | 204 mit CORS-Kopfzeilen |
| GET | `/garage/files/{slug}` | Signatur | 1 | Datei; 404 bei falscher/abgelaufener Signatur |
| GET | `/garage/api/documents` | JWT | 3 | `{documents:[…]}` wie `list_documents`, je Eintrag `preview_url` (signiert) |
| GET | `/garage/api/documents/{id}` | JWT | 3 | Dokument mit `pages:[url]`, `original_url`, `attachments`, `text_content`, `can_edit` |
| POST | `/garage/api/documents` | JWT, Rolle `inhaber`/`mitglied` | 3 | multipart `file`, `pages[]?` (JPEG je PDF-Seite), `vehicle_id?`, `doc_type?` → 201 Dokument |
| POST | `/garage/api/documents/{id}/file` | JWT, Rolle wie oben | 3 | multipart `file`, `pages[]?` — Original für ein Dokument `wartet` nachreichen |
| PATCH | `/garage/api/documents/{id}` | JWT, Rolle wie oben | 3 | Body `doc_type?`, `vehicle_id?`, `title?`, `photo_tag?` |
| POST | `/garage/api/documents/{id}/discard` · `/restore` | JWT, Rolle wie oben | 3 | `discard` Body `reason` (1–200) |
| GET | `/garage/api/inbox` | JWT | 3 | `{items:[…]}` aller Garagen, neueste zuerst, ohne `verworfen` älter als 30 Tage |
| POST | `/garage/api/inbox` | JWT, Rolle wie oben | 3 | Body `{document_ids:[…], vehicle_id?, note?}` → 201 `{id}` |
| POST | `/garage/api/inbox/{id}/discard` · `/restore` | JWT, Rolle wie oben | 3 | nur aus `neu` bzw. zurück nach `neu` |

### Migrationen

`017_document.sql` (1) · `018_inbox.sql` (3) · `019_testkonten_m3_sperren.sql` (7).

## Finale Abnahmekriterien

- [ ] Ein Programm mit Dateizugriff (curl mit festem Token) holt per `request_upload` ein Ticket, lädt ein Foto und ein PDF hoch und beschreibt sie per `store_document`; ein zweites Hochladen derselben Datei ergibt `duplicate: true` und kein zweites Dokument. (Phase 1)
- [ ] Das gespeicherte Foto hat keine EXIF-/GPS-Daten mehr, `captured_on` steht am Dokument; das PDF ist byte-gleich (SHA-256 vor und nach). (Phase 1)
- [ ] Ein signierter Link liefert die Datei, nach 10 Minuten oder mit geänderter Signatur 404; `/garage-app/storage/…` ist von außen 403. (Phase 1)
- [ ] Ein Agent hängt einen Beleg an ein Ereignis; `get_event`, `get_dossier` (Abschnitt „Belege“) und `search` (Text aus dem Beleg) zeigen ihn. (Phase 2)
- [ ] `store_document` mit ChatGPT-Dateiobjekt (`download_url`) holt die Datei ab; private Adressen werden abgewiesen. (Phase 2)
- [ ] Ein Mensch legt im Browser drei Fotos und ein PDF in den Eingang; ein Agent reserviert den Eintrag per `claim_inbox_item`, bekommt die Seiten als Bilder und schließt ihn per `release_inbox_item`; eine abgelaufene Reservierung erscheint wieder als `neu`. (Phase 3, 4)
- [ ] Die Seiten Eingang und Belege entsprechen dem Entwurf (Prüfpunkte in Phase 4), 360 px ohne waagerechtes Scrollen, hell und dunkel. (Phase 4)
- [ ] Auf einem Android-Handy mit installierter Web-App landet „Teilen → GARAGE“ aus einer anderen App im Formular „Neuer Eintrag“. (Phase 5, Inhaber)
- [ ] In Claude Web erscheint nach `store_document` ohne Datei das Widget; eine hineingezogene Datei wird abgelegt und das Dokument wechselt von `wartet` auf `zugeordnet`/`unzugeordnet`. (Phase 6, Inhaber)
- [ ] Nutzer B sieht über keinen Weg (MCP, API, signierter Link mit fremder ID, Ticket) Belege oder Eingang von Nutzer A; Rolle `lesend` und Token ohne `garage:files` legen nichts ab. (Phase 7)
- [ ] `/garage-app/.env` antwortet nach jedem Deploy mit 403; Rauchtest grün. (Phase 7)

Braucht Inhaber (nicht stellvertretend abgehakt): Verprobungs-Matrix aus Phase 7 (Claude Web, Claude Android, ChatGPT Web, ChatGPT Android, Claude Code; Weg A und B) — sie entscheidet, welcher Weg Standard wird.

## Risiken und Konfidenz

- 🟡 **Claude-Widget im echten Client** (am unsichersten): ob Claude Web die Ressource lädt, die CSP `connectDomains` beachtet und ein Datei-Feld im Sandbox-Rahmen öffnet, ist nur dokumentiert, nicht gesehen. Check: Phase 6 endet mit einem Testlauf in Claude Web (Inhaber); scheitert er, bleibt `wartet` + Eingang als Rückfall (E6), der Rest von M3 hängt nicht daran.
- 🟡 **pdf.js unter strenger CSP:** neuere pdf.js-Fassungen laden für einzelne Bildformate WebAssembly, das `'wasm-unsafe-eval'` bräuchte. Check in Phase 4: Testseite mit einem echten Werkstatt-PDF rendern, Browser-Konsole ohne CSP-Fehler; sonst `isEvalSupported: false` und eine Fassung ohne WASM-Pflicht wählen (Eintrag in FINDINGS).
- 🟡 **Speicher bei großen Fotos auf Strato:** 50 Megapixel brauchten in DISPATCH 246 MB bei 5 Varianten; hier 3 Dateien. Check in Phase 1: ein 48-MP-Foto hochladen, `memory_get_peak_usage` im Log (ImageProcessor liefert ihn), muss unter 400 MB bleiben.
- 🟡 **Upload-Grenzen bei Strato** (`upload_max_filesize`, `post_max_size`): DISPATCH maß 128 MB. Check in Phase 1: 29-MB-PDF über `/garage/upload/{slug}` → 201.
- 🟡 **ChatGPT-Download-URL-Host:** ob die Adresse öffentlich auflöst und ohne Kopfzeilen abrufbar ist, ist nicht dokumentiert. Check: Verprobung ChatGPT Web (Inhaber); bei Fehlschlag Eingang als Rückfall.
- Fehlerklassen geprüft: MCP — „Server nach altem Schema“ (abgedeckt: die Protokoll-Schicht aus M1 spricht beide Generationen, die neuen Tools ändern daran nichts); Strato, PHP, MySQL haben im Vault keinen Fehlerklassen-Abschnitt.

## Summary

## Files touched

## Commits

## Deviations from plan

## Follow-ups
