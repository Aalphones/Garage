# ADR-014: pdf.js lokal als einzige Ausnahme der Bibliotheksregel

**Status:** accepted · 2026-11-17 (Entscheidung des Inhabers)

## Kontext

Agenten lesen PDFs über MCP nicht sicher; sie brauchen Bilder der Seiten. Der Server kann PDFs nicht rendern (kein Ghostscript/Imagick auf dem Shared Hosting). Die Oberfläche erlaubt sonst keine Bibliotheken, die CSP lässt nur `'self'` zu.

## Optionen

1. **pdf.js lokal unter `web/vendor/pdfjs/`:** der Browser rendert jede Seite als JPEG und lädt sie mit dem Original hoch.
2. Nur Original und erkannter Text: Agenten sehen bei Scans nichts.
3. Nur Fotos zulassen: Werkstatt-PDFs gingen nicht.

## Entscheidung

Option 1, Fassung 4.10.38 (ohne WebAssembly-Pflicht, `isEvalSupported: false`). Dateien byte-genau gebündelt, Prüfsummen in `web/vendor/pdfjs/VERSION.md`. Die CSP bleibt unverändert; der Worker fällt unter `default-src 'self'`.

## Konsequenzen

- Einzige Ausnahme in `docs/conventions/frontend.md` → Library Policy.
- Ein Update ist eine bewusste Handlung mit neuer Prüfsumme und neuem Test mit einem echten PDF ohne CSP-Meldung.
- PDFs aus dem Chat haben keine Seitenbilder; `get_document` liefert dann Text und einen signierten Link.
