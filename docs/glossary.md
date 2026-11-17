# Glossar — GARAGE

Ein Begriff, eine Bedeutung. Code und Tabellen englisch, Erklärungen deutsch. Neue Fachbegriffe hier ergänzen, sobald sie abgeklärt sind.

## Konten und Zugang

| Begriff | Bedeutung |
|---|---|
| Nutzer (`users`) | Ein Mensch mit Konto; meldet sich mit Passwort, später auch mit Google oder Apple an. |
| Garage (`garages`) | Mandant: ein Haushalt oder Fuhrpark, dem Fahrzeuge und Verträge gehören. Jede Faktenzeile trägt `garage_id`. |
| Mitglied (`garage_members`) | Zuordnung Nutzer ↔ Garage mit Rolle: Inhaber, Mitglied, lesend. |
| Einladung (`invitations`) | Link mit Token, über den jemand Mitglied einer Garage wird; nie Abgleich über die E-Mail. |
| Akteur | Wer gerade handelt (Nutzer, Agent mit Token, Deploy) samt erlaubten `garage_id`s; jede Abfrage bekommt ihn mit. |
| Scope | Recht eines Tokens: `garage:read`, `garage:write`, `garage:files`. |
| CIMD | Client ID Metadata Document: eine KI-App weist sich mit einer URL zu ihren Metadaten aus, statt sich zu registrieren. |
| Zustimmungsseite | Seite, auf der sich ein Nutzer anmeldet und einer KI-App Zugriff auf seine Garagen gibt. |

## Fahrzeugakte

| Begriff | Bedeutung |
|---|---|
| Akte (Dossier) | Alles zu einem Fahrzeug; als Markdown über `get_dossier` und als Druckansicht. |
| Ereignis (`events`) | Ein datierter Vorgang am Fahrzeug: Inspektion, Reparatur, HU, Reifen, Schaden, Rückruf, Kauf. Eindeutig über Fahrzeug, Werkstatt und Rechnungsnummer. |
| Position (`event_items`) | Eine Zeile eines Ereignisses: Arbeit, Teil, Material oder Pauschale. |
| Wartungsaufgabe (`tasks`) | Wiederkehrende Arbeit an einem Fahrzeug, etwa Ölwechsel. |
| Intervallregel (`task_rules`) | Wann eine Wartungsaufgabe fällig wird: km, Monate, Erstintervall, fest oder flexibel; mit Quelle und Konfidenz. |
| Fälligkeit | Berechnet aus Regel, letzter Ausführung und Kilometerprognose — „was zuerst eintritt"; nie gespeichert. |
| Kilometerprognose | Aus den Kilometerständen geschätzter heutiger Stand; geschätzte Werte tragen „≈". |
| Herkunft (`source`) | Woher eine Angabe stammt: Beleg, Nutzer, Recherche, Agent, Werkstatt. Pflichtfeld jeder Faktenzeile. |
| Konfidenz | Sicherheit einer Angabe: bestätigt, wahrscheinlich, unsicher. |
| Fakt (`vehicle_facts`) | Eine Angabe zu einem Fahrzeug: Feld, Wert, Herkunft, Konfidenz, Akteur, Zeitpunkt. Ein Fahrzeug hat je Feld höchstens einen aktuellen Fakt. |
| aktuell / abgelöst / strittig | Status eines Fakts: gilt jetzt · wurde durch einen neueren ersetzt (bleibt als Verlauf) · widerspricht einem bestätigten Wert und wartet auf einen Menschen. |
| Klärfall (`open_items`) | Offener Punkt: Konflikt zweier Werte, Nachfrage oder Beobachtung. Lösen dürfen nur Menschen. Bei einem Konflikt trägt er den Vorschlag (`proposal_json`), damit der neue Wert nicht neu erfasst werden muss. |
| Datensatz-Abgleich (`RecordMerger`) | Regel für Listen wie Zulassung und Ausstattung: derselbe natürliche Schlüssel ergibt keinen zweiten Datensatz, sondern ergänzt leere Spalten, aktualisiert nicht bestätigte Werte oder wird Klärfall. Ergebnisse: angelegt · ergaenzt · aktualisiert · unveraendert · klaerfall. |
| Spezifikation (`spec.<bereich>.<schluessel>`) | Technischer Wert eines Fahrzeugs (Ölsorte, Füllmenge, Reifendruck) als Fakt mit Herkunft; Einheit steht im Wert. Eintrag über `set_spec`. |
| Zulassung (`registrations`) | Kennzeichen eines Fahrzeugs ab einem Datum, optional mit Halter; mehrere über die Zeit. Schlüssel: Kennzeichen + gültig ab. |
| Ausstattung (`equipment`) | Serien- oder Sonderausstattung mit optionalem Code und Listenpreis. Schlüssel: Name. |

## Belege

| Begriff | Bedeutung |
|---|---|
| Dokument / Beleg (`documents`) | Abgelegte Datei: Foto, Rechnung, HU-Bericht, Police, Bescheid, Fahrzeugschein, Kaufvertrag. |
| Anhang (`attachments`) | Verbindung eines Dokuments mit einem Datensatz (Ereignis, Vertrag, Schaden …) mit Rolle. |
| Upload-Ticket | Einmalige Upload-Adresse, 15 Minuten gültig, damit Dateien nie durch das Sprachmodell laufen. |
| Eingang (`inbox_items`) | In der App hochgeladene Belege, die ein Agent später abarbeitet (Weg B). |
| Weg A / Weg B | Beleg kommt im Chat (A) oder über den Eingang der App (B) herein. |

## Kosten und Wert

| Begriff | Bedeutung |
|---|---|
| Vertrag (`contracts`) | Versicherung, Steuer, Club, Stellplatz, Finanzierung; hängt an einem Fahrzeug oder an der Garage. |
| Beitragsperiode (`contract_terms`) | Zeitraum mit Betrag und Merkmalen wie SF-Klasse und Typklasse. |
| Umlage | Verteilung eines Garagen-Vertrags auf die aktiven Autos: gleich, nach km, feste Anteile oder ein Auto. |
| Unterhalt | Kosten pro Monat und pro km, laufend und mit Wertverlust (Vollkosten). |
| Ankerwert | Belegter Wert: Kaufpreis, Bewertung, Händlerangebot, Gutachten. |
| Marktkorridor | Median und mittlere Hälfte passender Vergleichsinserate. |
| Wertfaktoren-Check | Aus den eigenen Daten berechnete Plus/Minus/Unbekannt-Liste (Scheckheft lückenlos, HU, Unfallfreiheit …). |
| Notfallkarte | Kennzeichen, FIN, Versicherer, Hotlines; offline verfügbar. |

## Betrieb

| Begriff | Bedeutung |
|---|---|
| Brücke | `www/garage/index.php`, einziger PHP-Einstieg; bindet den gesperrten Code ein. |
| Gesperrter Ordner | `www/garage-app/`, per `.htaccess` für jeden HTTP-Aufruf gesperrt. |
| Deploy-Token | Geheimnis aus `deploy.env`, schützt `POST /garage/api/deploy`. |
| Rauchtest | Statusprüfung nach jedem Deploy: eigene Adressen, Sperre, Nachbarn. |
| Nachbarn | Andere Anwendungen auf `quantum-canvas.de`: DISPATCH, CardMaker, CamScanner. |
