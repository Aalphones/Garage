# 018 — Oberfläche: Seiten, Lese-API, Diagramme, Druck

**Status:** Akzeptiert (2026-11-18) — Meilenstein 4, Planung.

## Kontext

Die Akte war bisher nur über MCP lesbar; die Browser-API kannte Fahrzeugliste, Klärfälle, Belege und Eingang. Die Oberfläche braucht alle Bereiche der Akte, ohne Build-Schritt und mit CSP `'self'`. Das Access-Token liegt nur im Arbeitsspeicher; das Refresh-Cookie gilt nur für `/garage/api/auth`.

## Entscheidung

- **Mehrere Seiten mit gemeinsamer Schale** statt Einzelseiten-App: `garage.html`, `fahrzeug.html#<id>/<reiter>`, `klaerfaelle.html`, dazu die bestehenden `eingang.html`, `belege.html`, `index.html` (Konto). `web/js/shell.js` baut Seitenleiste (ab 992 px) bzw. untere Leiste (Garage · Eingang · Klärfälle · Konto). Jede Seite meldet sich wie bisher über das Refresh-Cookie an.
- **Lese-API spiegelt die MCP-Tools:** je Bereich eine Route unter `/garage/api/vehicles/{id}/…`, die denselben Service aufruft und dieselbe Ausgabe liefert. Kein zweiter Rechenweg für Fälligkeiten, Prognose oder Akte.
- **Fälligkeiten über alle Fahrzeuge** zählen nur Fahrzeuge mit Status `aktiv` (gilt auch für `get_due_items` ohne `vehicle_id`).
- **Diagramme als SVG im Browser** (`web/js/chart.js`) statt Server-SVG aus DISPATCH: ein Liniendiagramm reicht für Meilenstein 4, Farben folgen den Tokens hell und dunkel; die Druckansicht zeigt Tabellen statt Diagramme.
- **Druckansicht ohne Skript über signierten Link:** `POST …/print-link` liefert `/garage/akte/<slug>` (HMAC, 600 s, Nutzer und Fahrzeug in der Nutzlast); der Server rendert HTML aus denselben Abschnitten wie `get_dossier`.
- **Anzeige-Einstellungen** (Ansicht je Liste, Thema) je Gerät in `localStorage` unter `garage.*` — nie Daten oder Tokens.

## Verworfen

- **Einzelseiten-App mit Router:** mehr Umbau der bestehenden Seiten, kein Gewinn ohne Build-Schritt.
- **Druckseite per Cookie-Anmeldung:** bräuchte ein zweites Cookie mit weiterem Pfad und CSRF-Schutz.
- **Druck nur per Druck-CSS der App-Seite:** verletzt „Druckansicht funktioniert ohne Skript“.
- **Server-SVG-Renderer aus DISPATCH portieren:** Aufwand für ein Diagramm; bleibt Option für Meilenstein 5 (Kosten, Wert).

## Konsequenzen

- Jede neue Lese-Route hat eine Mandantenprüfung über den Service; fremde IDs → 404.
- Ein Druck-Link ist 10 Minuten lang ohne Anmeldung gültig — wer ihn weitergibt, gibt die Akte weiter. Deshalb kurze Laufzeit, `no-store`, `noindex`.
