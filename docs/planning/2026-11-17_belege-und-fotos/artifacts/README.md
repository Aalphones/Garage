# Entwurf — Eingang und Belege

| Datei | Bildschirme | Phase |
|---|---|---|
| `entwurf-belege.html` | Eingang (Start, Neuer Eintrag, Leer), Geteilt (Android), Belege (Liste, Galerie), Beleg-Detail, Original fehlt, Claude-Widget | 4 (Seiten), 5 (Geteilt), 6 (Widget) |

**Ansehen:** Datei direkt im Browser öffnen (offline, keine Abhängigkeiten). Leiste oben wechselt die Bildschirme; „Werkzeug zeigen“ blendet zu jeder Aktion das MCP-Tool bzw. die API-Route ein; „Thema“ erzwingt hell/dunkel; rechts die aktive Breitenstufe.

**Gestaltung:** funktionaler Platzhalter aus `web/css/base.css` (Schwarz/Weiß, Systemschrift, eine Spalte bis 40 rem) — die Projektregel verbietet eigene Gestaltung vor Meilenstein 4. Neue Klassen sind im Stil-Block mit „NEU in Phase 4“ markiert und werden 1:1 übernommen.

**Platzhalter:** alle Daten mit „Beispiel“-Marke (Fahrzeuge Golf/Clio, Rechnungen, Zeiten); graue Rasterflächen statt echter Vorschaubilder; Fortschritt und Upload sind simuliert. Nicht Teil der App: Leiste „Entwurf M3“, „Wofür“-Kästen, „Beispiel“-Marken, Chat-Rahmen um das Widget.

Geprüft 2026-11-17 per Headless-Chrome: 390 px (hell und dunkel) für Eingang, Neuer Eintrag, Belege, Original fehlt, Widget; 1366 px Galerie (dunkel). Kein waagerechtes Scrollen bei 390 px.
