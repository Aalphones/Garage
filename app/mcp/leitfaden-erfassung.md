# Leitfaden: Belege erfassen

Eine Werkstattrechnung wird zerlegt und mit `record_service_event` ins Scheckheft geschrieben. Reihenfolge: lesen, zerlegen, Trockenlauf, Freigabe, schreiben.

## 1. Vorher lesen

- `list_vehicles`: gehört der Beleg zu einem bekannten Fahrzeug? Passen FIN, Kennzeichen oder Name?
- `list_events` für dieses Fahrzeug: gibt es die Rechnung schon (gleiche Rechnungsnummer, gleiches Datum)?
- `get_vehicle`: welche Stammdaten und Intervallregeln gibt es schon?

## 2. Den Kopf lesen

| Angabe | Feld | Hinweis |
|---|---|---|
| Leistungsdatum | `performed_on` | `YYYY-MM-DD`; nicht das Rechnungsdatum, wenn beide abweichen |
| Rechnungsdatum | `invoiced_on` | nur, wenn es abweicht |
| Kilometerstand | `odometer_km` | ganze Zahl; steht keiner auf dem Beleg, weglassen |
| Werkstatt | `workshop` (Name, Ort) | so, wie sie auf dem Beleg steht |
| Rechnungsnummer | `invoice_number` | **immer mitgeben**, sie erkennt dieselbe Rechnung wieder |
| Art | `kind` | `inspektion`, `reparatur`, `hu`, `reifen`, `schaden`, `rueckruf`, `kauf`, `sonstiges` |
| Summen | `net_eur`, `vat_eur`, `gross_eur` | Euro, exakt wie gedruckt; nicht nachrechnen |
| Kurzbezeichnung | `title` | z. B. „Inspektion 30.000 km“ |
| Herkunft | `source`, `confidence` | für den ganzen Aufruf einmal; siehe `garage://leitfaden/herkunft` |

## 3. Die Positionen zerlegen

Jede Zeile des Belegs wird eine Position:

- **Typ**: `arbeit`, `teil`, `material` (Öl, Kühlmittel) oder `pauschale`.
- **Beschreibung**: der Text des Belegs, nicht umformuliert.
- **Teilenummer**: wenn gedruckt, sonst weglassen.
- **Menge** (und Einheit) und **Netto** der Zeile.
- **`task_key`** für wiederkehrende Arbeiten, damit die Fälligkeit neu gerechnet wird. Übliche Schlüssel: `oelwechsel`, `oelfilter`, `luftfilter`, `innenraumfilter`, `zuendkerzen`, `bremsfluessigkeit`, `kuehlmittel`, `zahnriemen`, `dsg_oel`, `hu`, `inspektion`. Ein Schlüssel ohne Intervallregel warnt; die Regel legst du mit `upsert_task_rule` an.

## 4. Erst prüfen, dann schreiben

1. `validate_service_event` mit denselben Angaben: nichts wird gespeichert, das Ergebnis zeigt, was geschehen würde (neu, ergänzt, Klärfall, Warnungen).
2. Dem Nutzer das Ergebnis samt Klärfällen zeigen.
3. Nach seinem Okay `record_service_event`.

## 4a. Beleg anhängen

Nach `record_service_event` hängst du die Rechnung an das Ereignis: `attach_document {"document_id": <ID>, "target": "ereignis", "target_id": <event_id>, "role": "beleg", "text": "<erkannter Text>"}`. Den erkannten Text (Datum, Beträge, Positionen) gibst du mit; die Suche findet ihn. Das Dokument kommt vorher per `store_document` (Datei-Parameter, `request_upload` oder `import_document_from_url`); ohne Datei (Status „wartet“) lässt es sich nicht anhängen. Ein Tacho-Foto: `record_odometer`, dann `attach_document` mit `target` `kilometerstand` und der `reading_id`. Dasselbe Dokument zweimal anzuhängen schadet nicht (`unveraendert`).

## 5. Unleserlich oder widersprüchlich

Ein unleserlicher Betrag, ein fehlendes Datum oder eine Position, die du nicht zuordnen kannst: **nie raten.** Lass den Wert weg und lege mit `open_item` eine Nachfrage an. Widerspricht der Beleg einem bestätigten Wert, entsteht ein Klärfall; sag dem Nutzer, dass er in der Oberfläche entscheidet.

Herkunft und Konfidenz: `garage://leitfaden/herkunft`.
