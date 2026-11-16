# Definition of Done — GARAGE

**Pflicht, bevor eine Aufgabe abgehakt wird.** Es gibt keine automatisierten Tests (ADR-004); diese Handprüfung ist die einzige Prüfebene.

- Jeden Punkt einzeln durchgehen und im Abschlussbericht sagen, was konkret geprüft wurde: Adresse aufgerufen, Statuscode, Antwort, Seite im Browser geöffnet, Konsole geprüft.
- „Die CI ist grün" belegt nur die Syntax, nicht das Verhalten.
- Sicherheitsrelevantes (Mandantentrennung, Scopes, OAuth, Upload-Tickets, Sperre von `garage-app/`) braucht eine Liste der durchgespielten Fälle, einschließlich der abzuweisenden — mindestens: Nutzer B fragt ein Fahrzeug von Nutzer A ab.
- Oberfläche: 360 px Breite ohne waagerechtes Scrollen, hell und dunkel, Hauptaktion in Daumenreichweite, leere Zustände mit nächstem Schritt.
- Alles, was Deploy oder Routing berührt, schließt mit einem grünen Rauchtest von `deploy.cmd`, inklusive der Nachbarn.

## Wenn hier nicht prüfbar

Braucht ein Punkt den echten Strato-Server, ein Handy oder Daten, die nur der Inhaber hat: das ausdrücklich sagen, um Bestätigung bitten, den Punkt nicht stellvertretend abhaken.

## Critical Rules

1. **Kein Haken ohne Beleg** — ein stiller Fehler (Route nie verdrahtet, Header nie angekommen, fremde Garage sichtbar) fällt sonst erst im Betrieb auf.
