# Phase 8b — Kleine Eingaben in der Oberfläche

Rating: standard · Modell: sonnet · braucht Phase 4b und 5

## Kontext

- `artifacts/entwurf-oberflaeche.html` — „Golf · Übersicht“: Link „Stand eintragen“ unter „km heute“, Stift je Zeile in „Angaben“, „Notiz anlegen“ und „Bearbeiten“ in Notizen; „Golf · Belege“ → Foto antippen → „Als Titelbild setzen“; die Zweige `addkm`, `editfact`, `note-new`/`note-edit`, `doc` im Klick-Handler sind die Vorlage. URL-Parameter `?open=km`, `?open=fact` zeigen die Blätter.
- README → „Kontrakt“, Tabelle „Kleine Eingaben“; `docs/decisions/021-kleine-eingaben-durch-menschen.md`
- `web/js/vehicle.js`, `sheet.js`, `toast.js`, `document.js` (Phasen 1, 5, 7)

## AK

- [ ] Alles nur bei `permissions.write`; bei `lesend` fehlen Link, Stifte und Knöpfe ganz.
- [ ] „Stand eintragen“: Blatt mit „Kilometer laut Tacho“ (`inputmode="numeric"`, Pflicht, 0–2.000.000), „Abgelesen am“ (Datum, Standard heute, nicht in der Zukunft), „Notiz (optional)“, Satz „Gilt als deine Angabe. Ist der Stand kleiner als ein früherer, entsteht ein Klärfall statt eines Fehlers.“; nach dem Speichern Übersicht neu laden, Leiste „Kilometerstand gespeichert“ bzw. bei `klaerfall` „Stand gespeichert — widerspricht einem früheren, siehe Klärfälle“.
- [ ] Angaben: vierte Spalte mit Stift (`.iconsm`, 16 px Symbol, Trefferfläche 44 px, `aria-label="<Feld> korrigieren"`); Blatt „<Feld> korrigieren“ mit vorbelegtem Wert und Satz „Dein Wert ersetzt den bisherigen sofort (Herkunft: Nutzer, bestätigt). Der alte Wert bleibt im Verlauf.“; strittiges Feld: statt Stift der Link „strittig“ zum Klärfall (inline in der Übersicht aufklappen). Fehler der Prüfung (z. B. FIN-Format) unter dem Feld mit `aria-describedby`.
- [ ] Notizen: „Notiz anlegen“ rechts im Abschnittskopf; je Notiz aufklappbar mit „Bearbeiten“; Blatt mit Thema und Text (mehrzeilig); Hinweis „Speichern legt Revision <n+1> an; frühere bleiben erhalten.“ bzw. „Gleiches Thema wie eine vorhandene Notiz ergänzt diese als neue Revision.“
- [ ] Titelbild: im Blatt bzw. in der Detailansicht eines Fotos (`belege.html#beleg/<id>` und Reiter Belege) Knopf „Als Titelbild setzen“; danach Leiste „Titelbild gesetzt“, Garage-Karte zeigt es beim nächsten Laden.
- [ ] Prüfung nach README → „Breakpoints und Handy“ (alle drei Blätter bei 360 px: Tastatur des Handys verdeckt die Knöpfe nicht — Blatt rollt, Knopfzeile bleibt erreichbar); Tastatur am Desktop (Enter speichert, Escape schließt).

## Checkliste

- [ ] Blätter und Anbindung in `vehicle.js`, `document.js`
- [ ] Doc-Update: `docs/code-map.md`

## Report-Back
