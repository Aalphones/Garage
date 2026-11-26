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

- [x] Ressourcen und Prompt lesen, `list_vehicles`, `get_vehicle` nach Anlage.
- [x] Akte ganz lesen; Belegliste aus beiden Ordnern mit Dateiname → Belegart aufstellen (in `## Report-Back`).
- [x] Fahrzeug anlegen, Stammdaten aus dem Fahrzeugschein-Beleg (falls vorhanden, sonst Akte → `wahrscheinlich`).
- [x] Je Datei: `request_upload` → `curl -F "file=@<Pfad>" <upload_url>` → `store_document` mit `ticket_id`, `doc_type`, `text`. Dubletten meldet der SHA-256.
- [x] Rechnung für Rechnung: `validate_service_event`, dann `record_service_event` mit Rechnungsnummer, Positionen mit `task_key`, `attach_document`.
- [x] Regeln (`upsert_task_rule`), Messwerte (`record_measurement`, ein Aufruf je Tag), Reifen, Teile.
- [x] Verträge: `upsert_contract`, `record_contract_term` je Beitragsperiode aus den Versicherungs-PDFs, Steuer als Vertrag mit Bescheid.
- [x] Kaufpreis `add_valuation`, Notiz `upsert_note`.
- [x] Klärfälle per `open_item`; Klärfälle, die Schreibwerkzeuge selbst melden, nur notieren.
- [x] `get_dossier` gegen die Akte abgleichen, Abweichungen nachtragen oder als Klärfall anlegen, Ergebnis in `FINDINGS.md` (`- [ ] → Phase 6: …` für alles, was die Clio-Migration anders machen soll).

## Report-Back

Stand 2026-11-26: Fahrzeug 49 in Garage 54, 15 Dokumente zugeordnet (`list_documents` offen: leer), Klärfälle #71–#82, Abgleich in `FINDINGS.md`.

| Datei (ohne Endung) | Belegart | Dokument |
|---|---|---|
| 202509 Fahrzeugschein | fahrzeugschein | 48 |
| 20260108 Fahrzeugschein | fahrzeugschein | 49 |
| 202412 Inspektion | rechnung | 50 |
| 202603 Auftrag Inspektion | sonstiges | 51 |
| 202603 Inspektion | rechnung | 52 |
| 202605 HU AU | hu_bericht | 53 |
| 202610 Ölwechsel Rechnung | rechnung | 54 |
| 202610 Ölwechsel | sonstiges | 55 |
| 202601 KFZ Steuer | bescheid | 56 |
| 202602 Crit Air Zertifikat | sonstiges (nur Bestellbestätigung) | 57 |
| 202510 Angebot | sonstiges (Seiten 6–8 nicht ausgewertet) | 58 |
| 202510 EVB Nummer | sonstiges | 59 |
| 202602 Versicherungsschein | police | 60 |
| 20260331 Versicherungsschein | police | 61 |
| 202610 Jahresbeitrag Golf | rechnung | 62 |

Alle 8 Regeln haben eine Fälligkeit. `rule_uncertain`: `oelwechsel` (flexibel, Höchstwerte) und `dsg_oel` (unbelegt; dadurch seit 18.04.2025 als überfällig gezeigt, siehe Klärfall #76).

Unsicher erfasst: Tag der Beitragsrechnung (nur „Oktober 2026“ gedruckt), Montagedatum des Reifensatzes, Kauftag.
