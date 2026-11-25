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

- [ ] Wie Phase 5, Schritt für Schritt.
- [ ] Garagen-Verträge prüfen (`get_cost_summary` für die Garage).

## Report-Back
