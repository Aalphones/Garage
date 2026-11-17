# Leitfaden: Herkunft und Konfidenz

Jede Angabe trägt `source` (woher), optional `source_url` und `confidence` (wie sicher). Ein bestätigter Wert wird nie überschrieben; ein abweichender Wert wird ein Klärfall.

| Quelle | `source` | `confidence` | Weiteres |
|---|---|---|---|
| Beleg oder Fahrzeugschein (Rechnung, Zulassungsbescheinigung) | `beleg` | `bestaetigt` | |
| Aussage einer Werkstatt | `werkstatt` | `bestaetigt` | auch mündlich oder im Serviceheft vermerkt |
| Aussage des Nutzers | `nutzer` | `bestaetigt`, wenn er es sicher weiß; sonst `wahrscheinlich` | |
| Webseite, Forum, Datenbank | `recherche` | höchstens `wahrscheinlich` | `source_url` mitgeben |
| eigene Ableitung | `agent` | `unsicher` | z. B. aus einem anderen Modell geschlossen |

Regeln:

- Beleg schlägt Behauptung: gibt es einen Beleg, trägst du ihn mit `beleg` ein, auch wenn der Wert schon aus einer Recherche vorliegt (die Konfidenz steigt).
- Kein Intervall ohne Quelle: eine Wartungsregel ohne Fundstelle wird `unsicher` gespeichert; `get_due_items` meldet sie als unbelegt.
- Eine Webseite wird nie `bestaetigt`, auch wenn sie amtlich wirkt — bestätigt wird erst durch einen Beleg des Fahrzeugs oder die Aussage des Nutzers.
- Schätzungen (Kilometerprognose, Fälligkeit aus Kilometern) trägst du nicht ein; sie entstehen beim Lesen.
