# Entwurf — Kosten, Verträge, Wert (Meilenstein 5)

| Datei | Bildschirme | Phase |
|---|---|---|
| `entwurf-kosten-wert.html` | Fahrzeug-Reiter Kosten (Zeitraum, Hinweis fehlende Blöcke, Kennzahlen, Säulen je Monat nach Block, Kosten pro km, Blöcke aufklappbar, Tankbuch Tabelle/Karten, sonstige Ausgaben), Wert (Kennzahlen, Verlauf mit Korridor-Band, Inserate Preis gegen km, Wertfaktoren, Ankerwerte, Inserate-Tabelle; Clio: zu wenig Inserate), Papiere (Notfallkarte, Stichtage, Verträge mit Beitragsverlauf und Vorgängen, fehlende Versicherung, Steuer-Prüfung, Plaketten, Zubehör, Schlüssel und Papiere); Übersicht mit „Kosten und Wert“ und „Stichtage“; Garage mit Unterhalt (laufend / mit Wertverlust, Säulen je Auto) und Verträgen der Garage, Karten mit Unterhalt und Wert; Konto mit Kalender-Abo; Blätter Tankung eintragen (Kippschalter „Voll getankt“) und Ausgabe eintragen (gestaltete Auswahl); Löschen mit Rückgängig; Rollen und Zustände wie Meilenstein 4 | 8 (Kosten, Blätter), 9 (Wert), 10 (Papiere), 11 (Notfallkarte), 12 (Garage, Übersicht, Konto) |
| `_entwurf-oberflaeche/hintergrund.jpg` | Hintergrundbild, unverändert aus Meilenstein 4 | — |

**Ansehen:** `entwurf-kosten-wert.html` im Browser öffnen (braucht den Bildordner daneben). Die Entwurfsleiste wechselt Thema, Rolle und Zustand; „Werkzeug zeigen“ blendet zu jeder Aktion Route bzw. MCP-Werkzeug ein; „Springen“ führt zu jedem neuen Bildschirm. URL-Parameter: `?theme=light`, `?state=leer|laden|fehler`, `?role=lesend`, `?tools=1`, `?open=fuel|expense` (mit `&dd=1` aufgeklappte Kategorie).

**Grundlage:** Kopie des freigegebenen Entwurfs aus Meilenstein 4 (`docs/archive/2026-11/2026-11-18_oberflaeche/artifacts/entwurf-oberflaeche.html`); Schale, Bauteile und Tokens unverändert, neu sind die Abschnitte ab `/* ===== Meilenstein 5` im Stil und im Skript.

**Diagrammfarben:** vier Blöcke als feste Reihe `--s1` Fixkosten (Blau), `--s2` Betrieb (Magenta), `--s3` Werkstatt (Violett), `--s4` Wertverlust (Aqua); dunkel `#3987e5 #d55181 #9085e9 #199e70`, hell `#2a78d6 #e87ba4 #6250d6 #1baf7a`. Mit dem dataviz-Validator geprüft: dunkel alle Prüfungen bestanden, hell Kontrast von Magenta und Aqua unter 3:1 → die Blöcke-Liste unter dem Diagramm ist die Tabellenansicht. Rot und Gelb bleiben den Zuständen. Säulen haben 2 px Fuge, eine Achse je Diagramm (Kosten pro km als eigenes Diagramm). In der Garage stehen die Autos in derselben Reihe (`--s1` erstes, `--s2` zweites Auto).

**Echt vs. Beispiel:** Echt aus der Bestandsaufnahme des Konzepts: Golf-Kaufpreis 13.000 € bei 17.000 km (12/2025), Umschreibung 08.01.2026, drei Werkstattbelege 2026 = 1.288,70 €, Kfz-Steuer Golf 134 € (passt zum Bescheid), ADAC Plus 94 € → voraussichtlich 114 €, Clio-CO₂ fehlt, km/Jahr Golf ≈ 13.000 / Clio ≈ 9.800, Crit'Air am Golf. Alles andere (Versicherung des Clio, Tankungen, Ausgaben, Inserate, Zubehör, Telefonnummern, Kennzeichen) ist Beispiel und im Bildschirm markiert. Nicht Teil der App: Entwurfsleiste, „Beispiel“-Marken, Werkzeug-Einblendungen, die Simulation ohne Server; der Zeitraum-Umschalter zeigt im Entwurf immer dieselben Zahlen.

**Geprüft 2026-11-24** per Headless-Edge: 360 px alle neuen Ansichten mit gemessenem Überlauf 0 (Garage, Kosten Golf/Clio, Wert, Papiere Golf/Clio, Übersicht, Konto); 390 px hell und dunkel (Kosten, Wert, Papiere, Garage, Blätter Tankung und Ausgabe mit offener Auswahl, Konto, Clio-Wert); 860 px Clio-Kosten; 1366 px Garage, Kosten, Wert, Papiere; Rolle lesend und Zustand leer.

**Neu erzeugen:** keine Erzeugung — die HTML-Datei ist die Quelle.
