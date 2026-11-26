# Findings — Meilenstein 6

Format: `- [ ] → Phase N: <Erkenntnis>` (erledigt: `[x]`, mit Commit oder Klärfall-Nummer).

- [x] → Phase 2: Wer die Inhaber-Rolle verliert, dessen offene Einladungen werden jetzt widerrufen (ADR-026). Ein vorher erzeugter Rücksetz-Link darf ebenso wenig weiterwirken: beim Annehmen (und in `preview`) erneut prüfen, dass `created_by` noch Inhaber aller Garagen des Ziel-Kontos ist.
- [x] → Phase 2: Bausteine vorhanden: `Domain\GarageAccess` (404/403, Sperre der Inhaber-Zeilen), `Api\BodyField`, Router-Platzhalter `{userId}`. Die Tabelle `password_resets` liegt in `025_members.sql`.
- [x] → Phase 3: Nach Rolle ändern oder Entfernen die ganze Mitgliederliste neu laden (offene Einladungen der Person verschwinden mit) und das im Bestätigungstext sagen. Wer sich selbst herabstuft oder die Garage verlässt, verliert die Verwaltung sofort.
- [x] → Phase 3: Rücksetz-Link: 403 `forbidden` heißt hier meist „das Konto ist auch in einer fremden Garage“, nicht „du bist kein Inhaber“ — die Meldung des Servers anzeigen. Ein Inhaber darf laut Kontrakt auch für sich selbst einen Link erzeugen.
- [x] → Phase 4: `passwort.html`: `preview` und `accept` können 429 `too_many_attempts` liefern (gemeinsame Bremse mit dem Login). 410 `reset_used` deckt auch „nicht mehr gültig“ ab — die Meldung des Servers anzeigen.
- [x] → Phase 6: Viele Belege sind reine Scans ohne Textebene. Mit `pdftoppm -r 90 -jpeg` rendern und als Bild lesen; bei Text-PDFs `pdftotext -enc UTF-8` (sonst kaputte Umlaute). Bankverbindung, Geburtsdatum und Zahlungskartendaten nicht in den Dokumenttext übernehmen.
- [x] → Phase 6: Behauptungen der alten Akte am Beleg und, wo sie sicherheitsrelevant sind (Steuertrieb, Ölsorte), an einer Quelle prüfen. Beim Golf waren eine Werkstattposition, eine Postensumme und mutmaßlich der Steuertrieb falsch.
- [x] → Phase 6: Reihenfolge bei Widersprüchen: erst den Beleg-Wert `bestaetigt` schreiben, dann den Akten-Wert — so entsteht der Klärfall von selbst. `konflikt` lässt sich nicht von Hand anlegen.
- [x] → Phase 6: Das Feld `source` kennt keine „Fahrzeugakte“. Reine Akten-Werte als `nutzer`/`agent` mit `wahrscheinlich` bzw. `unsicher` erfassen und eine Notiz „Herkunft der Erfassung“ mit Akte und Stand anlegen. Auch Angaben ohne eigenes Feld (Typschlüssel, Bremsbeläge „i.O.“) mitnehmen.
- [x] → Phase 6: Messwerte nehmen höchstens zwei Nachkommastellen an (Lambda genauer in die Notiz). Bei einem Versicherungs-Nachtrag die Beitragsperiode teilen und die zeitanteiligen Beträge aus dem Nachtrag rechnen.
- [x] → Phase 6: `task_key` nur an Positionen vergeben, für die auch eine Regel angelegt wird. Upload-Tickets gelten kurz; je Datei `request_upload` → `curl` → `store_document`.
- [ ] → Phase 7: Die Akte aus `get_dossier` bewertet „Keine Lücke im Scheckheft“ trotz 40 Monaten ohne Eintrag (08/2021–12/2024) und „Halterzahl nicht eingetragen“, obwohl der Abschnitt Zulassung „Halterzahl: 2“ zeigt. Beides in `docs/knowledge/GAPS.md` aufnehmen.
- [ ] → Phase 7: Nach einem Nachtrag zeigt die Vertragsübersicht als Beitrag/Jahr einen aus der laufenden Teilperiode hochgerechneten Wert (406,66 €), der Nachtrag nennt 408,30 €. In `docs/knowledge/GAPS.md` aufnehmen.

## Abgleich `get_dossier` gegen `golf-sportsvan.md` (Phase 5, 2026-11-26)

Stammdaten, 7 Einträge der Wartungshistorie, Rechnungsdetails, Intervalle, Teile und offene Punkte stimmen überein, außer:

| Abweichung | Ergebnis |
|---|---|
| Typschlüssel und Nenndrehzahl fehlten | nachgetragen (Spezifikationen) |
| Bremsbeläge „i.O.“ und AU-Fehlerspeicher fehlten; Lambda auf 1,00 gerundet | nachgetragen (Notiz „Herkunft der Erfassung“) |
| Zündkerzen-Summe in der Akte um 7,32 € zu niedrig (Dichtring fehlt) | nachgetragen aus der Rechnung |
| Ölviskosität 5W-30 (Akte) gegen 0W-20 (Rechnung) | Klärfall #71 |
| Kilometerstände Auftrag gegen Rechnung, alter Stand im DSG-Abschnitt | Klärfall #72 |
| Datum des Batterietests | Klärfall #73 |
| „Kühlmittel-Service“: im Auftrag steht nur Kältemittel | Klärfall #74 |
| Schraube Lenkgetriebe ohne Befund | Klärfall #75 |
| DSG-Regel gegen DSG-Plan; Fälligkeit in GARAGE überfällig | Klärfall #76 |
| Wasserabläufe fehlen in der Fälligkeitsliste der Akte | Klärfall #77 |
| App-Connect offen | Klärfall #78 |
| Batterie beobachten | Klärfall #79 |
| Steuerkette ohne Quelle; Recherche spricht für Zahnriemen | Klärfall #80 |
| Reifenart in den zwei Wartungslisten verschieden | Klärfall #81 |
| Kauftag unbekannt (Platzhalter 01.12.2025) | Klärfall #82 |
| Ölwechsel: Akte „nicht kalendarisch fix“, GARAGE zeigt Höchstwert 10/2028 | Regel `unsicher`, in Notiz „Herkunft der Erfassung“ |

In GARAGE zusätzlich aus Belegen, nicht in der Akte: Versicherung mit Beitragsperioden, Steuervertrag, Zulassung des Vorbesitzers, HU-Messwerte, Plakettenbestellung.

## Abgleich `get_dossier` gegen `clio-3.md` (Phase 6, 2026-11-26)

Stammdaten, Rechnungsdetails, Teile, Spezifikationen, Reifenhistorie und Verträge stimmen mit Beleg und Akte überein, außer:

| Abweichung | Ergebnis |
|---|---|
| Datumsangaben der Akte weichen vom Beleg ab (15.01.2018, 16.08.2019, 07.03.2020, 12.07.2023, 04/2024) | nachgetragen (Belegdatum erfasst, Notiz „Herkunft der Erfassung“) |
| Jahreswartung 12.01.2023 war volle Wartung mit Bremsflüssigkeit, 2018-01 Wartung mit Bremsen/Kerzen/Öl; Akte nannte nur Zahnriemen-Kontrolle | nachgetragen aus den Rechnungen |
| Bestandsaufnahme „Bremsflüssigkeit nie belegt“ | widerlegt, Wechsel 06/2020, 07/2021, 01/2023 nachgetragen |
| Bestandsaufnahme „Leergewicht / zGM nur 1580 kg“ | behoben, Leergewicht 1.062 kg aus dem Fahrzeugschein |
| Innenraumfilter überfällig seit 01/2026 | Klärfall #87 |
| Teilenummern „Renault-Original“ mit Bosch-Format | Klärfall #88 |
| Kühlmittelstand MIN am 28.08.2026 | Klärfall #89 (beobachten) |
| Zahnriemen-Intervall uneinheitlich | Klärfall #90 |
| Motorcode D4F-764 gegen D4F-740 | Klärfall #91 |
| Ölviskosität 5W-40 gegen 5W-30 | Klärfall #92 |
| Batterie 2021 „ersetzen“, Akte „ersetzt“ ohne Beleg | Klärfall #93 |
| Felgen 2023 beschädigt, Typ der Sommerräder | Klärfall #94 |
| Kilometerstand 13.08.2019 und 04/2026 weichen je Beleg ab | Klärfall #95 |
| Kein Steuerbescheid für den Clio | Klärfall #96 |
| Saisonwechsel der Reifen 2015–2023 unbelegt | Klärfall #97 |
| Kühlmittel-Zeitintervall 36 gegen 48 Monate | Klärfall #98 |
| Kühlmittel als überfällig seit 2016, kein Wechsel belegt | Klärfall #99 |
| Reifendruck 04/2026 vorn 2,4 gegen 2,3, hinten 2,2 gegen 2,0 (Inspektion gegen Reifenrechnung) | Klärfall #83–#86 (vom System angelegt) |
| Kaufpreis, Ausstattung, Plakette: keine Angabe in Akte oder Beleg | nicht erfasst, Hinweis in Notiz |

In GARAGE zusätzlich aus Belegen, nicht in der Akte: Versicherung mit Beitragsperioden (HUK 655/620777-T, Vorvertrag 509/407411-H), HU-Bremswerte, Batteriewerte 2020–2026, Zulassung (Fahrzeugschein 31.07.2017). Garagen-Vertrag (ADAC/Schutzbrief): weder in Akte noch Beleg.

- [ ] → Phase 7: Die Klärfälle #84 und #86 melden einen Widerspruch bei „event_id“ (81 gegen 82), wenn zwei Messwerte desselben Tages an verschiedenen Ereignissen hängen. Das ist kein fachlicher Widerspruch; in `docs/knowledge/GAPS.md` aufnehmen.
- [ ] → Phase 7: Das Dossier bewertet „Lücke im Scheckheft: Zündkerzen“, obwohl Ereignis 75 (29.01.2025) sie enthält, und „Halterzahl nicht eingetragen“, obwohl die Zulassung „Halterzahl: 1“ zeigt. Beides in `docs/knowledge/GAPS.md` aufnehmen.
- [ ] → Phase 7: `upsert_task_rule` mit anderem Intervall überschreibt die alte Regel ohne Rückfrage (Ergebnis `aktualisiert`); beim Zahnriemen versehentlich 72 statt 60 Monate gesetzt und zurückkorrigiert. In `docs/knowledge/GAPS.md` aufnehmen.
