# Phase 5 — Migration Golf Sportsvan

Rating: heikel (schreibt in Live-Daten, Agenten können nichts löschen; Urteil Beleg gegen Akte) · Modell: opusplan

Kein Code. Die Session arbeitet mit den MCP-Werkzeugen `garage` gegen die Live-Garage „Garage Sascha“ (ID 54, am 2026-11-25 leer) und lädt Dateien per `curl` hoch.

## Kontext

- Fachkonzept `docs/design/konzept-garage.md` → „Bestandsaufnahme“ (Widerspruchsliste Golf) und „Migration der beiden Akten“ (Schritte 1–7).
- MCP-Ressourcen zuerst lesen: `garage://leitfaden/erfassung`, `garage://leitfaden/herkunft`; Prompt `beleg_erfassen`.
- Quellen (nur lesen, nie ändern):
  - Akte: `G:\Meine Ablage\U400_Unterlagen\U003_Auto\golf-sportsvan.md` — Grundlage für alle Werte.
  - Belege: `G:\Meine Ablage\U400_Unterlagen\U003_Auto\Golf Sportsvan\` (10 PDF) und `G:\Meine Ablage\U300_Verträge\U301_Versicherungen\KFZ\Golf Sportsvan\` (5 PDF).
- Herkunftsregel (README → „Von mir gesetzt“): am PDF belegt → `bestaetigt` mit Dokument als Quelle; nur in der Akte → `wahrscheinlich` mit Quelle „Fahrzeugakte golf-sportsvan.md, Stand 31.10.2026“; unbelegte Regel → `unsicher`.

## Akzeptanzkriterien

1. Fahrzeug „Golf Sportsvan“ existiert genau einmal in Garage 54 (vorher `list_vehicles`).
2. Alle 15 Dateien liegen als Dokumente vor (Upload-Ticket, `store_document` mit `doc_type` und erkanntem Text) und hängen an ihrem Ereignis, Vertrag oder Fahrzeug; `list_documents` mit Status `offen` ist für das Fahrzeug leer.
3. Stammdaten, Zulassung, Ausstattung, Spezifikationen, alle Werkstattereignisse mit Rechnungsnummer (vorher je `validate_service_event`), Kilometerstände, Wartungsregeln mit `task_key`, Messwerte, Reifen, Teile, Verträge mit Beitragsperioden (Versicherung, Steuer), Kaufpreis als Bewertung, Notiz DSG-Langlebigkeitsstrategie sind erfasst, soweit Akte oder Beleg sie tragen.
4. Jeder Widerspruch aus der Bestandsaufnahme (Golf, sechs Punkte), der in Akte oder Beleg noch besteht, ist ein Klärfall; dazu „App-Connect freischalten“ und „Batterie beobachten“ als Klärfall/Beobachtung, sofern die Akte sie noch führt.
5. `get_dossier` ist gegen `golf-sportsvan.md` abgeglichen; jede Abweichung steht in `FINDINGS.md` als „nachgetragen“ oder „Klärfall #<id>“.
6. `get_due_items` liefert für jede Regel eine Fälligkeit; Regeln mit `rule_uncertain` stehen in `## Report-Back`.

## Checkliste

- [ ] Ressourcen und Prompt lesen, `list_vehicles`, `get_vehicle` nach Anlage.
- [ ] Akte ganz lesen; Belegliste aus beiden Ordnern mit Dateiname → Belegart aufstellen (in `## Report-Back`).
- [ ] Fahrzeug anlegen, Stammdaten aus dem Fahrzeugschein-Beleg (falls vorhanden, sonst Akte → `wahrscheinlich`).
- [ ] Je Datei: `request_upload` → `curl -F "file=@<Pfad>" <upload_url>` → `store_document` mit `ticket_id`, `doc_type`, `text`. Dubletten meldet der SHA-256.
- [ ] Rechnung für Rechnung: `validate_service_event`, dann `record_service_event` mit Rechnungsnummer, Positionen mit `task_key`, `attach_document`.
- [ ] Regeln (`upsert_task_rule`), Messwerte (`record_measurement`, ein Aufruf je Tag), Reifen, Teile.
- [ ] Verträge: `upsert_contract`, `record_contract_term` je Beitragsperiode aus den Versicherungs-PDFs, Steuer als Vertrag mit Bescheid.
- [ ] Kaufpreis `add_valuation`, Notiz `upsert_note`.
- [ ] Klärfälle per `open_item`; Klärfälle, die Schreibwerkzeuge selbst melden, nur notieren.
- [ ] `get_dossier` gegen die Akte abgleichen, Abweichungen nachtragen oder als Klärfall anlegen, Ergebnis in `FINDINGS.md` (`- [ ] → Phase 6: …` für alles, was die Clio-Migration anders machen soll).

## Report-Back
