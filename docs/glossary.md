# Glossar — GARAGE

Ein Begriff, eine Bedeutung. Code und Tabellen englisch, Erklärungen deutsch. Neue Fachbegriffe hier ergänzen, sobald sie abgeklärt sind.

## Konten und Zugang

| Begriff | Bedeutung |
|---|---|
| Nutzer (`users`) | Ein Mensch mit Konto; meldet sich mit E-Mail und Passwort an. |
| Garage (`garages`) | Mandant: ein Haushalt oder Fuhrpark, dem Fahrzeuge und Verträge gehören. Jede Faktenzeile trägt `garage_id`. |
| Mitglied (`garage_members`) | Zuordnung Nutzer ↔ Garage mit Rolle: Inhaber, Mitglied, lesend. |
| Einladung (`invitations`) | Link mit Token, über den jemand Mitglied einer Garage wird; nie Abgleich über die E-Mail. |
| Mitglied entfernen | Löschen der Zeile in `garage_members` durch einen Inhaber oder das Mitglied selbst („Garage verlassen“), mit Audit-Eintrag; der Zugriff endet mit der nächsten Anfrage, offene Einladungen der Person in dieser Garage werden widerrufen. Das Konto bleibt; der letzte Inhaber lässt sich nicht entfernen (ADR-026). |
| Rücksetz-Link (`password_resets`) | 24 Stunden gültiger Einmal-Link (`gpwr_`), mit dem ein Mitglied ein neues Passwort setzt; erzeugt ihn ein Inhaber, der alle Garagen des Ziel-Kontos als Inhaber verwaltet. Gespeichert nur als SHA-256; Einlösen beendet alle Browser-Sitzungen, feste Zugänge und verbundene Apps bleiben (ADR-026). |
| Akteur | Wer gerade handelt (Nutzer, Agent mit Token, Deploy) samt erlaubten `garage_id`s; jede Abfrage bekommt ihn mit. |
| Scope | Recht eines Tokens: `garage:read`, `garage:write`, `garage:files`. |
| CIMD | Client ID Metadata Document: eine KI-App weist sich mit einer URL zu ihren Metadaten aus, statt sich zu registrieren. |
| Zustimmungsseite | Seite, auf der sich ein Nutzer anmeldet und einer KI-App Zugriff auf seine Garagen gibt. |

## Fahrzeugakte

| Begriff | Bedeutung |
|---|---|
| Akte (Dossier) | Alles zu einem Fahrzeug; als Markdown über `get_dossier` und als Druckansicht. |
| Lücke (Scheckheft) | Eine Wartungsaufgabe, die später ausgeführt wurde als Fälligkeit + 3 Monate bzw. + 3.000 km (auch ab Erstzulassung); wird im Scheckheft rot mit Rechenweg gezeigt, nie gespeichert (ADR-020). |
| Gelöscht (wiederherstellbar) | Status `geloescht` eines Fahrzeugs oder Datensatzes, gesetzt nur von Menschen; Fahrzeuge lassen sich als Inhaber 30 Tage wiederherstellen, Datensätze über die Rückgängig-Leiste (ADR-019). |
| Druckansicht | HTML-Fassung der Akte ohne Skript unter `/garage/akte/{slug}`, erreichbar über einen signierten Link (600 s) aus „Drucken“. |
| Ereignis (`events`) | Ein datierter Vorgang am Fahrzeug: Inspektion, Reparatur, HU, Reifen, Schaden, Rückruf, Kauf. Eindeutig über Werkstatt und Rechnungsnummer, ohne Rechnungsnummer über Art, Leistungsdatum und Werkstatt. |
| Position (`event_items`) | Eine Zeile eines Ereignisses: Arbeit, Teil, Material oder Pauschale. |
| Wartungsaufgabe (`tasks`) | Wiederkehrende Arbeit an einem Fahrzeug, etwa Ölwechsel. |
| Intervallregel (`task_rules`) | Wann eine Wartungsaufgabe fällig wird: km, Monate, Erstintervall, fest oder flexibel; mit Quelle und Konfidenz. |
| Fälligkeit | Berechnet aus Regel, letzter Ausführung und Kilometerprognose — „was zuerst eintritt"; nie gespeichert. |
| Kilometerprognose | Aus den Kilometerständen geschätzter heutiger Stand; geschätzte Werte tragen „≈". Grundlage: Kilometer pro Tag zwischen dem letzten Stand und einem Vergleichsstand 30 bis 365 Tage davor (ADR-011 Teil 2). |
| Basis (einer Fälligkeit) | Ausgangspunkt der Rechnung: die letzte Ausführung der Aufgabe (Ereignis mit Position desselben `task_key`), sonst Erstzulassung bei 0 km mit den Erstintervallen. |
| Effektives Fälligkeitsdatum | Das frühere von Fälligkeitsdatum (aus Monaten) und km-Fälligkeitsdatum. |
| km-Fälligkeitsdatum | Tag, an dem die Kilometerprognose den fälligen Stand (Basis-km + Intervall) erreicht. |
| Fälligkeitsstatus | `ueberfaellig`, `bald` (innerhalb von 3 Monaten oder 3.000 km), `ok`, `unbekannt` (nicht berechenbar, z. B. ohne Ausführung und Erstzulassung). |
| Unsichere Regel (`rule_uncertain`) | Intervallregel mit Konfidenz `unsicher`, also ohne Beleg; die Fälligkeit ist dann nur ein Anhaltspunkt. |
| Herkunft (`source`) | Woher eine Angabe stammt: Beleg, Nutzer, Recherche, Agent, Werkstatt. Pflichtfeld jeder Faktenzeile. |
| Konfidenz | Sicherheit einer Angabe: bestätigt, wahrscheinlich, unsicher. |
| Fakt (`vehicle_facts`) | Eine Angabe zu einem Fahrzeug: Feld, Wert, Herkunft, Konfidenz, Akteur, Zeitpunkt. Ein Fahrzeug hat je Feld höchstens einen aktuellen Fakt. |
| aktuell / abgelöst / strittig | Status eines Fakts: gilt jetzt · wurde durch einen neueren ersetzt (bleibt als Verlauf) · widerspricht einem bestätigten Wert und wartet auf einen Menschen. |
| Klärfall (`open_items`) | Offener Punkt: Konflikt zweier Werte, Nachfrage oder Beobachtung. Lösen dürfen nur Menschen. Bei einem Konflikt trägt er den Vorschlag (`proposal_json`), damit der neue Wert nicht neu erfasst werden muss. |
| Vorschlag (`proposal_json`) | Der gemeldete, aber nicht übernommene Wert an einem Klärfall; „Neuer Wert gilt“ übernimmt ihn, ohne dass er neu erfasst wird. |
| Entscheidung (`decision`) | Antwort eines Menschen auf einen Klärfall: `bestehend` (gespeicherter Wert bleibt), `neu` (Vorschlag gilt), `verwerfen` (schließen ohne Änderung); bei Nachfrage/Beobachtung `erledigt` oder `verwerfen`. |
| Datensatz-Abgleich (`RecordMerger`) | Regel für Listen wie Zulassung und Ausstattung: derselbe natürliche Schlüssel ergibt keinen zweiten Datensatz, sondern ergänzt leere Spalten, aktualisiert nicht bestätigte Werte oder wird Klärfall. Ergebnisse: angelegt · ergaenzt · aktualisiert · unveraendert · klaerfall. |
| Spezifikation (`spec.<bereich>.<schluessel>`) | Technischer Wert eines Fahrzeugs (Ölsorte, Füllmenge, Reifendruck) als Fakt mit Herkunft; Einheit steht im Wert. Eintrag über `set_spec`. |
| Werkstatt (`workshops`) | Betrieb, der ein Ereignis ausführt; je Garage über Name + Ort erkannt, mit optionalem Vermerk „Vertragswerkstatt“. |
| Trockenlauf (`validate_service_event`) | Rechnet ein Ereignis vollständig durch (Abgleich, Klärfälle, Kilometerstand) und rollt am Ende zurück: zeigt, was geschähe, speichert nichts; IDs sind vorläufig. |
| Strittiger Stand | Kilometerstand, der einem früheren größeren oder späteren kleineren widerspricht (Kilometer laufen nur vorwärts, ADR-011): gespeichert, aber ohne Wirkung auf die Prognose, bis ein Mensch entscheidet. |
| Zulassung (`registrations`) | Kennzeichen eines Fahrzeugs ab einem Datum, optional mit Halter; mehrere über die Zeit. Schlüssel: Kennzeichen + gültig ab. |
| Ausstattung (`equipment`) | Serien- oder Sonderausstattung mit optionalem Code und Listenpreis. Schlüssel: Name. |
| Messwert (`measurements`) | Gemessene Größe an einem Tag (Profiltiefe, Reifendruck, Bremsbelag, Batterie …) mit Position und fester Einheit. Schlüssel: Datum + Größe + Position + Bezeichnung. |
| Reifensatz (`tire_sets`) | Ein Satz Reifen oder Räder (z. B. „Sommer 16 Zoll“) mit Größe, Saison, DOT und Lagerort. Schlüssel: Bezeichnung. |
| Montage (`tire_mounts`) | Zeitraum, in dem ein Reifensatz am Fahrzeug ist (montiert am, ausgebaut am). Höchstens ein Satz ist montiert; Montieren beendet die Montage des vorigen Satzes am selben Tag. |
| DOT (`dot`) | Produktionsdatum eines Reifens als vier Ziffern `WWYY` (Woche, Jahr); `dot_age_years` sind die vollen Jahre seit dem Montag dieser Woche. |
| Teil (`parts`) | Verbautes Teil oder passende Alternative zu einem Bauteil, mit Marke, Artikel- und OEM-Nummer; `verified` heißt Passform am Fahrzeug geprüft. |
| Schaden (`damages`) | Unfall, Hagel, Lack, Glas oder Vandalismus an einem Datum, mit Reparaturstand, Gutachten und Wertminderung. |
| Rückruf (`recalls`) | Herstellerrückruf mit Herstellernummer und/oder KBA-Referenz und Status (offen, erledigt, nicht betroffen). |
| Notiz-Revision (`note_revisions`) | Eine Fassung der Notiz zu einem Thema. Jede Änderung ist eine neue Revision; die alten bleiben, die Akte zeigt die neueste. |

## Belege

| Begriff | Bedeutung |
|---|---|
| Dokument / Beleg (`documents`) | Abgelegte Datei: Foto, Rechnung, HU-Bericht, Police, Bescheid, Fahrzeugschein, Kaufvertrag. |
| Anhang (`attachments`) | Verbindung eines Dokuments mit einem Datensatz (Ereignis, Vertrag, Schaden …) mit Rolle. |
| Upload-Ticket (`upload_tickets`) | Einmalige Upload-Adresse, 15 Minuten gültig, damit Dateien nie durch das Sprachmodell laufen; in der Datenbank nur als SHA-256 des Slugs. |
| Signierter Link | Zeitlich begrenzte Adresse `/garage/files/{slug}` (600 Sekunden), die eine Datei ohne Login ausliefert; die Signatur ist die Berechtigung. |
| Dublette | Datei mit gleichem SHA-256 in derselben Garage; der Upload liefert das vorhandene Dokument mit `duplicate: true`. |
| Wartet (Status) | Dokument ist angelegt, die Datei fehlt noch; der Upload auf dessen Ticket füllt genau dieses Dokument. |
| Eingang (`inbox_items`) | In der App hochgeladene Belege, die ein Agent später abarbeitet (Weg B). |
| Reservierung (`claimed_until`) | Ein Agent hält einen Eingangs-Eintrag mit Frist (Standard 180 Minuten); danach gilt er wieder als `neu`. Berechnet beim Lesen, kein Cron (ADR-016). |
| Weg A / Weg B | Beleg kommt im Chat (A) oder über den Eingang der App (B) herein. |
| Widget (MCP App) | Kleine Oberfläche im Claude-Chat (`ui://garage/beleg-ablegen`): zeigt bei einem Dokument im Status `wartet` eine Ablagefläche, lädt per Upload-Ticket hoch und ruft `confirm_document`. |
| Teilen-Ziel (`share_target`) | Eintrag „GARAGE“ in der Android-Teilen-Liste; der Service Worker nimmt die Dateien an und übergibt sie dem Eingangsformular. |
| Verprobungs-Matrix | Tabelle Client × Weg (A/B/Teilen-Ziel); der Inhaber trägt ein, was in echten Clients funktioniert; sie entscheidet, welcher Weg Standard wird. |

## Kosten und Wert

| Begriff | Bedeutung |
|---|---|
| Vertrag (`contracts`) | Versicherung, Steuer, Club, Stellplatz, Finanzierung; hängt an einem Fahrzeug oder an der Garage. |
| Beitragsperiode (`contract_terms`) | Zeitraum mit Betrag und Merkmalen wie SF-Klasse und Typklasse. |
| Vorgang (`contract_events`) | Datiertes Ereignis an einem Vertrag: Beitragsrechnung, Änderung, Schadenmeldung, Regulierung, Rückstufung, Kündigung; mit Bearbeitungsstand. |
| Tankbuch (`fuel_logs`) | Tankungen mit Datum, km, Litern und Betrag. Der Verbrauch wird beim Lesen zwischen zwei Volltankungen gerechnet, nie gespeichert. |
| Ausgabe (`expenses`) | Sonstige Kosten wie Parken, Wäsche oder Bußgeld, wahlweise über bis zu 36 Monate verteilt. |
| Zubehör (`accessories`) | Zubehörteil mit Kaufdatum, Preis, Garantie, Montagestand und der Angabe, ob es beim Verkauf mitgeht. |
| Plakette (`permits`) | Plakette oder Vignette mit Gültigkeit und Preis; ihr Ablauf ist ein Stichtag. |
| Umlage | Verteilung eines Garagen-Vertrags auf die aktiven Autos, tagesgenau: gleich (1 ÷ Zahl der an dem Tag aktiven Autos), nach km (Anteil an den km aller aktiven Autos im Monat) oder feste Anteile (`contract_allocations`). |
| Block | Einer der vier Kostenblöcke: Fixkosten (`fix`: Vertragsperioden, Plaketten, HU über 24 Monate, Umlage), Betrieb (`run`: Tankungen, Zubehör, Ausgaben), Werkstatt (`shop`: Ereignisse außer Kauf und HU), Wertverlust (`loss`). |
| Laufend | Kosten ohne Wertverlust (Fixkosten + Betrieb + Werkstatt). |
| Vollkosten | Laufende Kosten plus Wertverlust; Grundlage von „pro km“. |
| Aktiv-Zeitraum | Tage, an denen ein Auto Kosten trägt und an der Umlage teilnimmt: ab Kaufpreis-Datum (sonst ältester Zulassung, sonst Beginn des Zeitraums) bis zum Verkauf oder zur Stilllegung (`status_since`); ohne dieses Datum zählt ein nicht aktives Auto nie. |
| Unterhalt | Kosten pro Monat und pro km, laufend und mit Wertverlust (Vollkosten). |
| Ankerwert (`valuations`) | Belegter Wert mit Datum: Kaufpreis, Verkaufspreis, Gutachten, Händlerangebot, Online-Bewertung oder Schätzung. Der Kaufpreis ist der Ausgangspunkt des Wertverlusts. |
| Vergleichsinserat (`market_listings`) | Angebot eines vergleichbaren Autos mit URL und Abrufdatum; der Agent sagt beim Ablegen, ob Motor und Getriebe passen. Es zählt mit passendem Antrieb, Erstzulassungsjahr ±1, km ±25 % des eigenen Stands und höchstens 60 Tage alt. |
| Marktkorridor | Ab 8 zählenden Vergleichsinseraten Median und mittlere Hälfte der Preise, ab 15 die auf km und Alter des eigenen Autos umgerechnete Mitte mit Band. Angebotspreise sind keine Verkaufspreise. |
| Wertfaktor | Einer von acht aus den eigenen Daten berechneten Punkten (Scheckheft, HU, Unfallfreiheit, Halter, Profiltiefe, anstehende Arbeiten, Rückrufe, Ausstattung) mit Plus, Minus oder Unbekannt und Fundstelle. |
| Stichtag | Gerechneter Termin aus Verträgen und Papieren, nie gespeichert: Kündigung (Hauptfälligkeit − Kündigungsfrist − 1 Tag), Sonderkündigung (offene Beitragsrechnung über der Periode davor + 1 Monat), Steuer- und Club-Fälligkeit, Ablauf einer Plakette, Ende einer Garantie. Bis 30 Tage vorher `warn`. |
| Notfallkarte | Kennzeichen, FIN, Versicherer mit Vertragsnummer und Telefon, Pannenhilfe mit Mitgliedsnummer und Telefon eines Fahrzeugs; oben im Reiter Papiere und offline aus dem Gerätecache (`notfall.html`), beim Abmelden gelöscht (ADR-025). |
| Prüfzeile | Nachrechnung je Vertrag in den Papieren: Kfz-Steuer aus Hubraum, CO₂ und Erstzulassung gegen den Beitrag, vereinbarte Fahrleistung gegen die Kilometerprognose, Umlage-Rechenweg eines Garagen-Vertrags. |
| Kalender-Abo | Geheimer Link je Garage auf eine `.ics`-Datei mit Fälligkeiten und Stichtagen der aktiven Autos; jede Rolle kann ihn kopieren, nur der Inhaber erneuert ihn, danach ist der alte Link tot. |

## Betrieb

| Begriff | Bedeutung |
|---|---|
| Brücke | `www/garage/index.php`, einziger PHP-Einstieg; bindet den gesperrten Code ein. |
| Gesperrter Ordner | `www/garage-app/`, per `.htaccess` für jeden HTTP-Aufruf gesperrt. |
| Deploy-Token | Geheimnis aus `deploy.env`, schützt `POST /garage/api/deploy`. |
| Rauchtest | Statusprüfung nach jedem Deploy: eigene Adressen, Sperre, Nachbarn. |
| Nachbarn | Andere Anwendungen auf `quantum-canvas.de`: DISPATCH, CardMaker, CamScanner. |
