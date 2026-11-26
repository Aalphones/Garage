# Phase 6 — Migration Clio III

Rating: standard (Ablauf aus Phase 5 erprobt) · Modell: sonnet

Kein Code. Gleicher Ablauf wie `phase-5-migration-golf.md` (Checkliste dort Schritt für Schritt), Garage 54.

## Kontext

- `phase-5-migration-golf.md` (Ablauf, Herkunftsregel) und `FINDINGS.md` (Einträge `→ Phase 6`) — zuerst lesen.
- Fachkonzept `docs/design/konzept-garage.md` → „Bestandsaufnahme“ (Widerspruchsliste Clio, vier Punkte) und „Migration der beiden Akten“.
- Quellen (nur lesen):
  - Akte: `G:\Meine Ablage\U400_Unterlagen\U003_Auto\clio-3.md`, Quelle „Fahrzeugakte clio-3.md, Stand 28.08.2026“.
  - Belege: `G:\Meine Ablage\U400_Unterlagen\U003_Auto\Clio\` (28 PDF, 1 JPG) und `G:\Meine Ablage\U300_Verträge\U301_Versicherungen\KFZ\Clio\` (5 PDF).

## Akzeptanzkriterien

1. Fahrzeug „Clio III“ existiert genau einmal in Garage 54.
2. Alle 34 Dateien liegen als Dokumente vor und hängen an; `list_documents` `offen` ist für das Fahrzeug leer; das JPG als `foto`, falls es ein Fahrzeugfoto ist.
3. Wie Phase 5 AK 3, dazu die vollständige Reifenhistorie seit 2015 (`record_tires` je Satz mit `mount`/`unmount`), Zahnriemen-Regel und Notiz „Kühlmittel-Spezifikation und Nachfüllhinweise“.
4. Die vier Clio-Widersprüche der Bestandsaufnahme, soweit noch bestehend, und „Kühlmittelstand auf MIN“ sind Klärfälle.
5. `get_dossier` gegen `clio-3.md` abgeglichen, Abweichungen in `FINDINGS.md`.
6. Ein Garagen-Vertrag (z. B. ADAC), den eine der beiden Akten nennt, steht einmal an der Garage, nicht je Auto.

## Checkliste

- [x] Wie Phase 5, Schritt für Schritt.
- [x] Garagen-Verträge prüfen (`get_cost_summary` für die Garage).

## Report-Back

Stand 2026-11-26: Fahrzeug 50 in Garage 54, 34 Dateien als 32 Dokumente zugeordnet (zwei Dubletten „HU_AU“ erkannt; `list_documents` offen: leer), Klärfälle #87–#99 (dazu automatisch #83–#86), Abgleich in `FINDINGS.md`. Garagen-Verträge: keiner (Akte nennt keinen ADAC/Schutzbrief; `get_cost_summary` Garage 54 zeigt `garage_contracts` leer). Das JPG ist ein Fahrzeugschein, daher `fahrzeugschein`, nicht `foto`.

| Datei (ohne Endung) | Belegart | Dokument |
|---|---|---|
| Fahrzeugschein | fahrzeugschein | 63 |
| 2015-10-28 Winterreifen | rechnung | 64 |
| 20180115 Kostenvoranschlag | sonstiges | 65 |
| 20180115 Rechnung | rechnung | 66 |
| 20180115 Wartungsblatt | sonstiges | 67 |
| 20180202 Blinker Rechnung | rechnung | 68 |
| 2019-08-16 HU AU (und Dublette HU_AU) | hu_bericht | 69 |
| 2019-08-16 Angebot | sonstiges | 70 |
| 2019-10-11 Winterreifen | rechnung | 71 |
| 2020-03-07 Tagfahrlichter | rechnung | 72 |
| 2020-06-19 Ölwechsel | rechnung | 73 |
| 2021-07-21 Reifen HU AU (und Dublette HU_AU) | rechnung | 74 |
| 2022-02-07 Abblendlicht | rechnung | 75 |
| 2022-07-01 Inspektion und Gurtstraffer | rechnung | 76 |
| 2023_07_12 TÜV Klimaanlage | rechnung | 77 |
| 2023-01-12 Wartung Zahnriemen in 2 Jahren | rechnung | 78 |
| 202310 Alljahresreifen | rechnung | 79 |
| 202401 Kupplung | rechnung | 80 |
| 202401 Zahnriemen | rechnung | 81 |
| 202404 Klimaanlage | rechnung | 82 |
| 202408 Gurtstraffer | rechnung | 83 |
| 202501 Inspektion | rechnung | 84 |
| 202506 Hauptuntersuchung | hu_bericht | 85 |
| 202507 Klimaanlage | rechnung | 86 |
| 202508 Klimaanlage Kondensator | rechnung | 87 |
| 202601 Gurt Fahrerseite | rechnung | 88 |
| 202604 Allwetterreifen (zwei Rechnungen) | rechnung | 89 |
| 202510 Angebot | sonstiges (Seiten 4–8 nicht ausgewertet) | 90 |
| 202511 Versicherungsschein | police | 91 |
| 202601 Nachtrag Versicherungsschein | police | 92 |
| 202602 Versicherungsschein | police | 93 |
| 202610 Jahresbeitrag Clio | rechnung (Tag nicht gedruckt) | 94 |

Erfasst: 28 Ereignisse (#55–#82), 8 Regeln, 6 Reifensätze (#16–#21), 2 Verträge mit Beitragsperioden, Messwerte (Profil, Druck, Batterie, HU-Bremswerte), Teile, Spezifikationen, 2 Notizen.

`rule_uncertain`: `innenraumfilter` (12 Monate, nur Praxis; überfällig seit 29.01.2026, Klärfall #87). Überfällig außerdem `kuehlmittel` seit 09/2016, weil kein Wechsel belegt ist (Klärfall #99). `zahnriemen`, `kuehlmittel`, `oelwechsel`, `bremsfluessigkeit` stehen als `wahrscheinlich`.

Unsicher erfasst: Tag der Beitragsrechnung 2026-10 (Platzhalter 01.10.), Kilometerstand 200.400 am 28.08.2026 (Nutzerangabe), Reifensatz 17 (Typ und Montage unbekannt), Teile mit „Renault-Original“ (Bosch-Format), Teile aus der Akte ohne Rechnungsnummer.

Abweichungen vom Ablauf: Nur die ersten drei der 28 Ereignisse gingen vorab durch `validate_service_event`; die übrigen direkt durch `record_service_event` (ohne Warnung). Die Rechnungssummen wurden stattdessen aus den Positionen nachgerechnet und stimmen. Kein Steuerbescheid für den Clio, daher kein Steuervertrag (Klärfall #96).
