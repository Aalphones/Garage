# GARAGE – Konzept: MCP-first Fahrzeugakte

Nov 14, 2026 · @Sascha

## Kurzfassung

GARAGE (Arbeitstitel) ist eine Fahrzeugakte für mehrere Nutzer: Agenten füllen und lesen sie über MCP, Menschen sehen sie im Browser. Sie läuft wie Dispatch in PHP 8.5 ohne Framework auf Strato Shared Hosting mit MySQL und übernimmt dessen MCP-Schicht, OAuth mit CIMD, Medienablage und Diagramm-Renderer.

Drei Ideen tragen das Konzept:

1. **Strukturiert, wo gerechnet wird; Markdown, wo erklärt wird.** Ereignisse, Positionen, Messwerte und Intervallregeln sind Tabellen. Strategien und Hintergründe (DSG-Strategie, Kühlmittel-Hinweise) bleiben Notizen.
2. **Jede Angabe trägt ihre Herkunft.** Beleg, Nutzerangabe, Recherche oder Agent, dazu eine Konfidenz. Widersprüche überschreiben nichts, sie werden Klärfälle.
3. **Fälligkeiten und Kilometerstand werden berechnet, nicht abgeschrieben.** Die App rechnet „was zuerst eintritt“ aus Intervallregel, letzter Ausführung und Kilometerprognose.

Die Akte als Markdown bleibt: als MCP-Resource und Export, damit Agenten weiter den gewohnten Kontext bekommen und ein Verkauf eine druckbare Historie hat.

Alles rund ums Auto gehört hinein: Wartung, Versicherung mit ihren Vorgängen, Steuer, Unterhalt, Zubehör, Fotos. Belege kommen auf zwei Wegen: Bild in den Chat mit „leg das in der Garage ab“, oder über den Eingang der App, den ein Agent danach abarbeitet. Hausrat, Technik und Sammlungen kommen später in eine eigene App.

## Bestandsaufnahme: was die beiden Akten heute tragen

Die Akten decken Wartung gut ab, Wertermittlung kaum, und sie enthalten schon jetzt Widersprüche, die eine Datenbank gar nicht erst zulassen würde.

| Bereich | Golf Sportsvan | Clio III |
| --- | --- | --- |
| Stammdaten | FIN, Typ, Motor- und Getriebecode, EZ 04/2019, Massen, Reifengröße, Kennzeichen, Halter, Kauf | FIN, Motorcode, EZ 09/2012, Leistung, Reifengröße, Kennzeichen, Halter |
| Wartungshistorie | 7 Einträge 2019–2026 mit Rechnungspositionen | 23 Einträge 2015–2026 |
| Intervallregeln | 8 Positionen plus DSG | 10 Positionen inkl. Zahnriemen |
| Teile | OEM- und Bosch-Nummern für 5 Bauteile | zuletzt verbaute Marken und Nummern für 12 Teile |
| Messwerte | Profiltiefe (2 Termine), Batterie, Frostschutz, Reifendruck | Reifendruck, Bremswerte der HU |
| Reifen | Größe und „Ganzjahr“, keine Marke | vollständige Reifenhistorie seit 2015 |
| Kosten | Rechnungsbeträge, Steuer 134 €/Jahr, Kaufpreis 13.000 € | nur Klimakompressor (\~2.267 €) |
| Wert | Kaufpreis bei 17.000 km | nichts |

**Fehlt für eine Wertermittlung, bei beiden:** HSN/TSN, Ausstattung samt Sonderausstattung, Listenneupreis, Unfall- und Schadenfreiheit, Schlüsselanzahl, Zustand mit Fotos, Rückrufstatus. Beim Clio zusätzlich Halterzahl und Kaufpreis.

**Widersprüche und Lücken, die eine App automatisch melden würde:**

- Golf, Öl: Die Rechnung vom 06.10.2026 nennt 0W-20, die Spezifikation 5W-30.
- Golf, Kilometer: Auftrag 26.910 km, Rechnung 26.921 km; der DSG-Abschnitt rechnet noch mit 21.249 km.
- Golf, Batterietest am 27.01.2026 „im Rahmen der Inspektion“ – die Inspektion war am 02.03.2026.
- Golf, Auftrag nennt „Kühlmittel-Service“, Rechnung „Kältemittel ablassen und auffüllen“. Das sind zwei verschiedene Kreisläufe. Der Hinweis „Schraube Lenkgetriebe“ hat keinen Befund.
- Golf, DSG: Die Regel „60.000 km oder 6 Jahre“ meldet ab EZ 04/2019 seit 04/2025 fällig, der Text plant 2028/2029. Regel oder Plan stimmt nicht; die Regel selbst ist unbelegt 🟡.
- Golf, die Fälligkeitsliste vergisst die Schiebedach-Wasserabläufe (24 Monate ab 03/2026, also 03/2028).
- Clio, Innenraumfilter: Regel „jährlich“, letzter Tausch 01/2025, Inspektion 04/2026 ohne Filter – nach eigener Regel überfällig seit 01/2026.
- Clio, Bremsflüssigkeit: Ein Wechsel ist nie belegt, das Intervall lautet 120.000 km / 48 Monate. Status unbekannt.
- Clio, „Renault-Original“ b0 242 129 510 (Zündkerzen) und b1 987 435 501 (Innenraumfilter) haben das Format von Bosch-Artikelnummern 🟡.
- Clio, das Feld „Leergewicht / zul. Gesamtgewicht“ enthält nur 1580 kg zGM.

Das ist kein Versagen einzelner Agenten. Freitext driftet, sobald mehrere Durchläufe an derselben Datei schreiben. Schema, Herkunft und Berechnung verhindern das.

## Recherche: welche Daten Wartungsbuch und Wertermittlung brauchen

Bewertungssysteme rechnen mit einem Normfahrzeug und korrigieren um Abweichungen. Die Akte muss deshalb genau diese Abweichungen als Daten führen: Laufleistung, Ausstattung, Zustand, Historie.

- Die Schwacke-Liste bewertet nach Marke, Modell, Typ, Erstzulassung, Antrieb, Kilometerstand, Zustand und Sonderausstattung. Basis ist ein Normfahrzeug mit durchschnittlichem Zustand und Laufleistung. Pkw bewertet sie nur bis 12 Jahre, für Privatleute ist sie nicht mehr einsehbar ([VW FS](https://www.vwfs.de/service/mobilitaetslexikon/s/schwacke-liste.html)).
- Online-Rechner setzen einen durchschnittlichen Verschleißzustand voraus; der echte Zustand kann besser oder schlechter sein ([VW FS](https://www.vwfs.de/magazin/auto-und-mobilitaet/restwert.html)).
- Wertfaktoren laut Allianz: Alter, Kilometer, Klasse und Ausstattung, Antrieb, Unfall- (offenbarungspflichtig), Hagel- und Lackschäden, Wartungshistorie mit aktueller HU, Vorbesitzer (der Zustand zählt mehr), Farbe. Faustregel: Jahr 1 etwa −25 %, nach 3 Jahren etwa 50 % Restwert, ab Jahr 4 etwa 5–6 % pro Jahr ([Allianz](https://www.allianz.de/auto/kfz-versicherung/wertverlust-auto/), Stand 27.07.2026).
- HSN und TSN stehen in den Feldern 2.1 und 2.2 der Zulassungsbescheinigung Teil I; Versicherer ermitteln damit die Typklasse, Werkstätten bestellen damit Teile ([ADAC](https://www.adac.de/rund-ums-fahrzeug/auto-kaufen-verkaufen/kfz-zulassung/hsn-tsn-faq/)).
- Die Rückrufdatenbank des KBA hat kein FIN-Feld und listet nicht alle Herstellermaßnahmen. Für den konkreten VW zeigt myVolkswagen nach FIN-Eingabe Rückrufe und Feldmaßnahmen ([CarCheckerVIN](https://www.carcheckervin.com/de/kba-rueckruf-fahrgestellnummer)).

Daraus ergeben sich elf Feldgruppen. W = Wartungsbuch, B = Bewertung; **fett** = fehlt heute in beiden Akten.

| Feldgruppe | Felder | Zweck |
| --- | --- | --- |
| Identität und Zulassung | FIN, **HSN/TSN**, Typgenehmigung, Kennzeichen-Verlauf, Halter-Verlauf mit Von–Bis, **Halterzahl**, Aufbewahrungsort Teil II, COC | W, B |
| Technik und Spezifikation | Motor- und Getriebecode, Leistung, Hubraum, Schadstoffklasse, CO₂, Massen, Rad- und Reifengröße, Öl (Freigabe, Viskosität, Füllmenge), Kühlmittel, Bremsflüssigkeit, Getriebeöl, Batterie, Steuerkette oder Zahnriemen | W |
| Ausstattung | **Serien- und Sonderausstattung** (bei VW mit PR-Codes), **Listenneupreis**, Farbe mit Farbcode, Lackart | B |
| Wartungsereignisse | Leistungs- und Rechnungsdatum, km, Werkstatt (Herstellerpartner ja/nein), Art, Positionen (Arbeit, Teil, Material, Teilenummer, Menge, Preis), Summen, Rechnungs- und Auftragsnummer, **Eintrag im digitalen Serviceplan** | W, B |
| Intervallregeln | km, Monate, Erstintervall, „flexibel nach Bordanzeige“, Quelle, Konfidenz | W |
| Messwerte und Befunde | Profiltiefe je Rad, Reifendruck, **Bremsbelag und -scheibe in mm**, Batterie (V, A), Frostschutz, HU-Bremswerte, Fehlerspeicher, Mängel | W, B |
| Reifen und Räder | Satz, Marke, Modell, Größe, Saison, **DOT**, Montage und Demontage (Datum, km), Lagerort | W, B |
| Schäden, Rückrufe, Garantie | **Unfall- und Schadenshistorie** mit Reparatur und Gutachten, **Rückrufe** mit KBA-Referenz und Status, Garantie bis | B |
| Zustand | **Lack, Innenraum, Rost, Nichtraucher, Schlüsselanzahl, Fotos mit Datum** | B |
| Kosten | Kauf, Steuer, **Versicherung (Typklasse, SF-Klasse)**, Kraftstoff, Wartung, Reparatur | B |
| Wert | **Bewertungen** (Quelle, Wertart, Datum, km, Betrag, Spanne), **Vergleichsinserate**, Angebote, Verkaufspreis | B |

## Architektur

GARAGE übernimmt Dispatchs Architektur fast unverändert. Neu sind Mandanten, eine private Oberfläche hinter Login und ein Fälligkeits-Feed statt Cron.

&#91;embedded content: Architektur · 4 Zugänge, 3 Endpunkte, 1 Domänenschicht\]

Vier Zugänge führen über drei Endpunkte in eine Domänenschicht; dort und nur dort gelten Mandantentrennung, Herkunft und Berechnung.

- **Gleich wie Dispatch:** PHP 8.5 ohne Framework und Composer, MySQL über PDO, Code im gesperrten Ordner `garage-app/` (nach jedem Deploy muss `.env` mit 403 antworten), `deploy.cmd` mit WinSCP und Nachprüfung, Migrationen über einen tokengeschützten Endpunkt statt SSH.
- **Als Code übernommen:** MCP-Protokollschicht und Registry (ADR-016), Tokens und Scopes (017), OAuth 2.1 mit CIMD und Zustimmungsseite (024, 025), HttpClient mit SSRF-Schutz und Medienablage inklusive PDF (011), Diagramme als Server-SVG (030).
- **Neu, Mandanten:** Dispatch kennt genau ein Konto aus der `.env`. GARAGE hat Konten in `users`; die Zustimmungsseite verlangt die Anmeldung als Nutzer, das Token trägt dessen `user_id`, der MCP-Server sieht nur dessen Garagen.
- **Neu, keine statische Website:** Private Daten gibt es nur nach Login. Eine HTML-Hülle mit ES-Modulen lädt alles über `/garage/api`.
- **Neu, iCal-Feed:** Je Garage eine geheime Abo-URL mit allen Fälligkeiten. Der Kalender fragt selbst ab, der Server braucht keinen Hintergrundprozess.
- **Nachbarschaft:** Auf quantum-canvas.de müssen `/garage/` und `/garage-app/` in Dispatchs reservierte Namen und in den Ausnahme-Block der Wurzel-`.htaccess` (ADR-001, ADR-003). Sonst greifen Blog-CSP und Caching auf die neue App durch.

Alles Berechnete entsteht beim Lesen. Bei zwei Fahrzeugen mit einigen Dutzend Ereignissen kostet das Millisekunden.

## Anmeldung mit Google oder Apple

Das geht, ohne dass Google oder Apple an Fahrzeugdaten kommen: Sie bestätigen nur, wer sich anmeldet. GARAGE bleibt selbst der Aussteller aller Sitzungen und Tokens, auch für die KI-Apps per MCP.

Technisch ist das OpenID Connect mit Authorization Code und PKCE als reine Weiterleitung. Auf den GARAGE-Seiten läuft kein Google- oder Apple-Skript, die strenge CSP bleibt. Die ID-Tokens prüft PHP mit `openssl` gegen die veröffentlichten Schlüssel der Anbieter, ohne Composer.

|  | Google | Apple |
| --- | --- | --- |
| Wir bekommen | `sub` als dauerhafte ID; E-Mail und `email_verified`, wenn der Scope `email` angefragt wird; Name und Bild nur mit `profile` und ohne Garantie ([Google](https://developers.google.com/identity/openid-connect/reference?hl=en)) | `sub`; E-Mail, echt oder als Weiterleitungsadresse über „E-Mail-Adresse verbergen“; den Namen nur bei der allerersten Anmeldung ([Google Cloud über Apple](https://docs.cloud.google.com/identity-platform/docs/web/apple?authuser=9)) |
| Sie bekommen bei der Einrichtung | App-Name, Support-Adresse, Domain, Rückleit-URLs, Datenschutz-URL | Domain und Return-URLs der Services ID |
| Sie bekommen bei jeder Anmeldung | dass dieses Google-Konto sich bei GARAGE anmeldet, samt Zeitpunkt – es passiert auf ihrer Seite | dasselbe für die Apple-ID |
| Sie bekommen nie | Fahrzeugdaten, Belege, Fotos, Kosten, MCP-Tokens, welche KI-App sich verbindet | ebenso |
| Aufwand | Google-Cloud-Projekt; nur `openid email profile` braucht keine Prüfung ([Unipile](https://www.unipile.com/integrating-google-oauth-2-0-user-authentication-into-your-app/)); Name und Logo auf dem Zustimmungsbildschirm brauchen eine leichtere Markenprüfung ([Google](https://support.google.com/cloud/answer/13463073?hl=id)) | Mitgliedschaft im Apple Developer Program, 99 $ im Jahr ([Coinbase Docs](https://docs.cdp.coinbase.com/embedded-wallets/social-login/apple)); Services ID, privater Schlüssel, Client-Geheimnis als selbst signiertes JWT |

**Regeln:**

- Konten werden über `sub` je Anbieter verknüpft, nie über die E-Mail. Google warnt selbst, dass ein Konto zu verschiedenen Zeiten verschiedene Adressen haben kann. Neue Tabelle `user_identities`: Anbieter, `sub`, E-Mail zum Zeitpunkt, verknüpft am.
- Den Apple-Namen speichert GARAGE bei der ersten Anmeldung sofort; danach liefert Apple ihn nicht mehr.
- Einladungen laufen über einen Link mit Token, nicht über den Abgleich der E-Mail – eine Apple-Weiterleitungsadresse passt sonst nie. Mails an solche Adressen kommen nur an, wenn die Absenderdomain bei Apple registriert ist und einen SPF-Eintrag hat ([sarunw](https://sarunw.com/posts/sign-in-with-apple-2/)).
- Passwort-Login bleibt als Rückfall, falls ein Anbieter ausfällt oder ein Mitglied keinen der beiden nutzen will.
- Die Zustimmungsseite für Claude und andere KI-Apps zeigt dieselben Knöpfe. Nach der Anmeldung stellt GARAGE das Token aus; der Anbieter erfährt davon nichts.
- Sobald Fremde Konten bekommen, nennt die Datenschutzerklärung beide Anbieter.

## Datenmodell

Das Modell hat 44 Tabellen in sechs Bereichen; jede Tabelle mit Fakten trägt Herkunft und Konfidenz, jede trägt `garage_id`. Berechnet und nie gespeichert werden Fälligkeiten, Kilometerprognose, Halterzahl, Scheckheft-Lücken sowie Unterhalt pro Monat und pro Kilometer.

| Bereich | Tabelle | Inhalt |
| --- | --- | --- |
| Konten | `users` | E-Mail, Passwort-Hash (bcrypt), Name |
| Konten | `garages`, `garage_members` | Haushalt oder Fuhrpark; Rolle je Mitglied: Inhaber, Mitglied, lesend |
| Konten | `invitations` | Einladung per Link: E-Mail, Token-Hash, Rolle, gültig bis |
| Konten | `tokens`, `oauth_clients`, `oauth_codes` | aus Dispatch, erweitert um `user_id` und optional erlaubte Fahrzeuge |
| Fahrzeug | `vehicles` | FIN, HSN, TSN, Marke, Modell, Variante, Motor- und Getriebecode, EZ, Farbe und Farbcode, Kraftstoff, kW, Hubraum, Schadstoffklasse, CO₂, Massen, Listenneupreis, Status (aktiv, verkauft, stillgelegt), Schlüsselanzahl, Ablageorte der Papiere, Titelbild |
| Fahrzeug | `registrations` | Von, Bis, Kennzeichen, Halter, Anschrift |
| Fahrzeug | `ownership_changes` | Kauf oder Verkauf: Datum, km, Preis, Gegenpartei, Beleg |
| Fahrzeug | `equipment` | Code (PR-Nummer), Bezeichnung, Serie oder Sonder, Neupreis |
| Fahrzeug | `specs` | Bereich, Schlüssel, Wert, Einheit – etwa Motoröl / Viskosität / 0W-20 |
| Historie | `odometer_readings` | Datum, km, auslösendes Ereignis |
| Historie | `events` | Art (Inspektion, Reparatur, HU, Reifen, Schaden, Rückruf, Kauf, Sonstiges), Leistungs- und Rechnungsdatum, km, Werkstatt, Summen netto/USt/brutto, Rechnungs- und Auftragsnummer |
| Historie | `event_items` | Position: Typ (Arbeit, Teil, Material, Pauschale), Wartungsaufgabe, Teil, Teilenummer, Hersteller, Menge, Einheit, Netto |
| Historie | `workshops` | Name, Ort, Herstellerpartner ja/nein |
| Historie | `tasks`, `task_rules` | Wartungsaufgaben je Fahrzeug; Regel mit km, Monaten, Erstintervall, Modus (fest oder flexibel), gültig ab |
| Historie | `measurements` | Datum, km, Größe (Profiltiefe, Druck, Belag, Batterie, Frostschutz), Position (VL, VR, HL, HR), Wert, Einheit |
| Historie | `tire_sets`, `tire_mounts` | Reifensatz mit Marke, Größe, Saison, DOT; Montage und Demontage mit Datum und km |
| Historie | `parts` | Bauteil, OEM-Nummer, Marke, Artikelnummer, Rolle (verbaut, Alternative), geprüft ja/nein |
| Historie | `damages`, `recalls` | Schaden mit Reparatur, Gutachten, Minderwert; Rückruf mit KBA-Referenz, Herstellercode, Status |
| Historie | `documents` | Belege und Fotos: Typ (Foto, Rechnung, HU-Bericht, Police, Bescheid, Fahrzeugschein, Kaufvertrag), Datei, SHA-256, Aufnahmedatum, Seitenbilder bei PDFs, erkannter Text, Status (unzugeordnet, zugeordnet) |
| Historie | `notes` | Thema und Markdown mit Revisionen – die DSG-Strategie lebt hier |
| Historie | `open_items` | Klärfall: Art (Konflikt, Nachfragen, Beobachten), Text, Bezug, Status, gelöst durch |
| Wert | `valuations` | Datum, km, Quelle (DAT, Gutachten, Händlerangebot, eigene Schätzung), Wertart (Händlereinkauf, Privatverkauf), Betrag, Spanne |
| Wert | `market_listings` | Vergleichsinserat: URL, Abrufdatum, EZ, km, Preis, Ausstattungsnotiz, PLZ |
| Kosten | `contracts` | Versicherung, Steuer, Club, Stellplatz, Finanzierung: Anbieter, Nummer, Beginn, Hauptfälligkeit, Kündigungsfrist, Zahlweise, Ebene (Fahrzeug oder Garage), Umlageschlüssel |
| Kosten | `contract_terms` | Beitragsperiode: Von, Bis, Betrag, Merkmale wie SF-Klasse, Typklasse, Regionalklasse, angegebene Jahresfahrleistung |
| Kosten | `contract_items` | welche Fahrzeuge ein Vertrag abdeckt |
| Kosten | `expenses` | sonstige Kosten: Kategorie, Betrag, Datum, Verteilzeitraum |
| Kosten | `fuel_logs` | Tankbuch: Datum, km, Liter, Preis, voll getankt |
| Querschnitt | `audit_log` | Akteur, Aktion, vorher und nachher als JSON |
| Konten | `user_identities` | Anmeldung über Google oder Apple: Anbieter, sub, E-Mail zum Zeitpunkt, verknüpft am |
| Fahrzeug | `accessories` | Zubehör: Bezeichnung, Kategorie, Hersteller, Artikelnummer, Kaufdatum, Preis, Garantie bis, montiert oder eingelagert, Lagerort, geht beim Verkauf mit |
| Fahrzeug | `permits` | Plaketten, Vignetten, Ausweise: Art, Nummer, gültig von und bis, Preis |
| Kosten | `contract_events` | Versicherungsvorgang: Art (Beitragsrechnung, Änderung, Schadenmeldung, Regulierung, Rückstufung, Kündigung), Datum, Betrag, Status, Schadennummer, Bezug zum Schaden |
| Querschnitt | `attachments` | verbindet ein Dokument mit Ereignis, Vertrag, Vorgang, Schaden, Ausgabe, Tankvorgang, Zubehör, Plakette oder Fahrzeug; Rolle (Beleg, Foto, Anhang) |
| Querschnitt | `upload_tickets` | Ticket-Hash, Nutzer, Höchstgröße, erlaubte Typen, gültig bis, eingelöst am, Dokument |
| Querschnitt | `inbox_items` | Eingang: Status (neu, in Arbeit, erfasst, Klärfall, verworfen), Notiz, Fahrzeug-Hinweis, reserviert von, Frist, Ergebnis |
| Querschnitt | `inbox_documents` | welche Dateien zu einem Eingangs-Eintrag gehören, in Seitenreihenfolge |
| Kosten | `contract_allocations` | feste Umlage-Anteile eines Garagen-Vertrags je Fahrzeug, mit Gültigkeit von und bis |

**Herkunft als Pflichtfeld.** Jede Faktenzeile hat `source` (Beleg, Nutzer, Recherche, Agent, Werkstatt), `source_document_id` oder `source_url`, `confidence` (bestätigt, wahrscheinlich, unsicher) und den Akteur. Die Oberfläche zeigt das als kleines Herkunfts-Abzeichen an jedem Wert.

**Regeln im Code, nicht im Prompt:**

- Mandantentrennung: Jeder Repository-Aufruf bekommt den Akteur mit seinen erlaubten `garage_id`s und hängt die Bedingung selbst an. Eine Abfrage ohne Akteur gibt es nicht.
- Kilometer laufen nur vorwärts. Ein kleinerer Stand nach einem größeren wird gespeichert und öffnet einen Klärfall.
- Ein Ereignis ist eindeutig über Fahrzeug, Werkstatt und Rechnungsnummer. Ein zweiter Import derselben Rechnung ergänzt statt zu verdoppeln.
- Ein Wert, der einem bestätigten Wert widerspricht, überschreibt ihn nicht. Er landet daneben, und ein Klärfall entsteht.
- Nichts wird hart gelöscht. Löschen ist ein Status mit Grund, sichtbar im Audit-Log.

## MCP-Oberfläche

Der Server `/garage/mcp` bietet 44 Tools, 6 Resources und 5 Prompts. Er spricht dieselben Protokoll-Generationen wie Dispatch (2026-07-28 zustandslos plus ältere Clients mit `initialize`) und übernimmt Registry, Pflicht-Annotationen und Fehlerformen unverändert.

**Scopes:** `garage:read` (lesen), `garage:write` (erfassen und ergänzen), `garage:files` (Belege und Fotos hochladen). Kein Scope erlaubt Löschen, Fahrzeugübergabe, Mitgliederverwaltung oder das Auflösen von Klärfällen – das bleibt Menschen in der Oberfläche vorbehalten, analog zu Dispatchs Regel „Nichts geht ohne Freigabe online“.

| Tool | Scope | Zweck |
| --- | --- | --- |
| `list_vehicles` | read | Alle Fahrzeuge der erlaubten Garagen mit geschätztem km-Stand, nächster Fälligkeit, offenen Klärfällen |
| `get_vehicle` | read | Stammdaten, Spezifikationen, Ausstattung – je Wert mit Herkunft |
| `get_dossier` | read | Die ganze Akte als Markdown, Aufbau wie die heutigen Dateien |
| `get_due_items` | read | Fälligkeiten mit Horizont in Monaten und km, je Aufgabe mit Rechenweg |
| `list_events`, `get_event` | read | Historie mit Filter nach Zeitraum und Art; ein Ereignis mit Positionen und Belegen |
| `get_measurements` | read | Messreihen, etwa Profiltiefe je Rad |
| `get_parts` | read | Teile mit OEM-Nummer und Alternativen |
| `get_value_summary` | read | Bewertungen, Vergleichsinserate, Wertfaktoren-Check |
| `list_open_items` | read | Klärfälle und Beobachtungen |
| `search` | read | Volltext über Ereignisse, Positionen, Notizen, Teile |
| `record_service_event` | write | Ein Ereignis mit Positionen, km, Summen und Beleg-Bezug; idempotent über die Rechnungsnummer |
| `validate_service_event` | write | Trockenlauf: zeigt, was angelegt würde und welche Klärfälle entstünden |
| `record_odometer` | write | Kilometerstand mit Datum und Herkunft |
| `set_vehicle_facts` | write | Stammdaten ergänzen; Widerspruch zu Bestätigtem öffnet einen Klärfall |
| `set_spec` | write | Spezifikation setzen, etwa Öl-Freigabe |
| `upsert_task_rule` | write | Intervallregel mit Quelle und Konfidenz |
| `record_measurement` | write | Messwert je Position |
| `record_tires` | write | Reifensatz anlegen, montieren, einlagern |
| `add_part` | write | Teil oder Alternative mit Quelle |
| `record_damage`, `record_recall` | write | Schaden oder Rückruf mit Status |
| `add_valuation`, `add_market_listing` | write | Bewertung oder Vergleichsinserat mit URL und Abrufdatum |
| `upsert_note` | write | Markdown-Notiz zu einem Thema, mit Revision |
| `open_item` | write | Klärfall oder Beobachtung anlegen, Lösung vorschlagen |
| `store_document`, `import_document_from_url` | files | Beleg oder Foto ablegen – per Datei-Parameter (ChatGPT), Widget (Claude) oder Ticket-ID – mit erkannten Daten und Zuordnung; die Datei geht nie durchs Modell |
| `get_cost_summary` | read | Unterhalt pro Monat und km für einen Zeitraum, nach Block oder Monat, mit Liste fehlender Blöcke |
| `upsert_contract` | write | Versicherung, Steuer oder anderen Vertrag anlegen oder ergänzen |
| `record_contract_term` | write | Beitragsperiode aus Rechnung oder Bescheid, mit SF- und Typklasse |
| `record_expense` | write | sonstige Kosten mit Kategorie und Verteilzeitraum |
| `record_fuel` | write | Tankvorgang mit km, Liter, Preis; Verbrauch rechnet die App zwischen zwei Volltankungen |
| `list_unassigned` | read | Dokumente, die noch keinem Datensatz zugeordnet sind |
| `get_document` | read | Dokument als Bildblöcke, je Seite ganz oder als obere und untere Hälfte, plus Metadaten und erkannter Text |
| `attach_document` | write | Dokument an Ereignis, Vertrag, Vorgang, Schaden, Zubehör oder Fahrzeug hängen; erkannten Text speichern |
| `request_upload` | files | Upload-Ticket: einmalige Adresse, 15 Minuten gültig, für Widget, Sandbox oder Rechner |
| `record_contract_event` | write | Versicherungsvorgang mit Status und Schadennummer |
| `upsert_accessory` | write | Zubehör anlegen, montieren, einlagern |
| `upsert_permit` | write | Plakette, Vignette oder Ausweis mit Gültigkeit |
| `confirm_document` | files | nur für das Widget sichtbar: bestätigt Daten und Ticket nach dem Ablegen |
| `list_inbox` | read | Einträge im Eingang nach Status, mit Vorschau und Notiz |
| `claim_inbox_item` | write | Eintrag mit Frist reservieren; liefert die Bilder gleich mit (Muster aus Dispatchs claim\_queue\_item) |
| `release_inbox_item` | write | Eintrag zurückgeben oder mit Begründung zum Klärfall machen |

**Resources:** `garage://fahrzeuge` (Übersicht), `garage://fahrzeug/{id}/akte` (Markdown-Akte), `garage://fahrzeug/{id}/faelligkeiten`, `garage://leitfaden/erfassung` (wie man eine Rechnung zerlegt), `garage://leitfaden/herkunft` (wann welche Konfidenz) und das Widget ui://garage/beleg-ablegen.

**Prompts:** `beleg_erfassen` (Rechnung oder Werkstattauftrag → Ereignis), `was_steht_an` (Fälligkeiten mit Kostenschätzung), `marktwert_recherche` (Vergleichsinserate sammeln und ablegen), `verkaufsakte` (Unterlagen, Wertargumente, offene Punkte vor dem Verkauf), eingang\_abarbeiten (alle offenen Eingangs-Einträge nacheinander erfassen).

**Server-Anweisungen** (Datei `app/mcp/instructions.md`, wie bei Dispatch) legen fünf Regeln fest:

1. Belege schlagen Behauptungen. Was eine Rechnung zeigt, ist `bestätigt`; was eine Webseite sagt, ist höchstens `wahrscheinlich`.
2. Kein Intervall ohne Quelle. Eine Regel ohne Fundstelle wird `unsicher` gespeichert.
3. Vor jedem Schreiben `get_vehicle` lesen, um Doppelungen zu vermeiden.
4. Widersprüche nie still auflösen, sondern `open_item` anlegen.
5. Freitext nur in Notizen; alles Rechenbare in die strukturierten Tools.

Antworten tragen wie bei Dispatch Beispiele in den Fehlermeldungen, damit ein Agent sich ohne Rückfrage korrigieren kann.

## Fotos und Belege

Belege und Fotos kommen auf zwei Wegen, die im selben Speicher enden: im Chat (Bild ablegen, „leg das in der Garage ab“) oder über den Eingang der App, den ein Agent danach abarbeitet. Beide werden gebaut und in M2 an echten Handys verprobt; danach wird einer Standard, der andere bleibt Rückfall.

&#91;embedded content: Belege auf zwei Wegen · Chat zuerst oder Eingang zuerst, ein gemeinsamer Speicher\]

Weg A schickt die Datei je nach Client über einen eigenen Kanal an GARAGE, nie durch das Modell. Weg B hat dieses Problem gar nicht: Die Datei liegt schon auf dem Server, und das Modell bekommt sie über `get_document` als Bild.

### Weg A · Chat zuerst

**Warum nicht einfach Base64 im Tool-Aufruf:** MCP hat bis heute keinen festen Weg für Dateien vom Client zum Server; die Vorschläge SEP-1306 und SEP-2356 stehen nicht in der Revision 2026-07-28 ([DEV Community](https://dev.to/kenimo49/7-services-0-upload-solutions-why-mcp-file-transfer-fails-2f2o)). Base64 als Argument tippt das Modell ab; in einem dokumentierten Fall kam eine kaputte Datei an, und der Server meldete trotzdem Erfolg ([jztan](https://blog.jztan.com/mcp-file-upload-base64-corruption/)).

| Client | Weg der Datei | Was du tust |
| --- | --- | --- |
| ChatGPT | `store_document` meldet seine Datei-Felder in `openai/fileParams`; ChatGPT übergibt die angehängte Datei mit Download-URL, GARAGE holt sie ab ([OpenAI](https://developers.openai.com/apps-sdk/reference)). Für die Handy-App wurde dabei ein Fehler gemeldet 🟡 ([OpenAI-Forum](https://community.openai.com/t/mcp-file-uploads-broken-on-mobile-app/1372060)). | Bild in den Chat, ein Satz |
| Claude Web und Desktop | `store_document` bringt ein MCP-App-Widget mit: erkannte Daten samt Klärfällen und eine Ablagefläche; das Widget lädt das Original per Ticket hoch. MCP Apps laufen unter anderem in Claude, Claude Desktop und VS Code ([MCP](https://modelcontextprotocol.io/docs/extensions/apps)). | Bild in den Chat, ein Satz, im Widget bestätigen und das Bild auf die Fläche ziehen |
| Claude mit Code-Ausführung | Ticket anfordern, Datei per `curl` aus der Sandbox hochladen – so machen es andere MCP-Dienste für Claude.ai ([FutureSearch](https://futuresearch.ai/blog/mcp-large-dataset-upload)). Nur, wenn die Sandbox die GARAGE-Domain erreichen darf 🟡. | nichts extra |
| Claude Code, Codex, lokale Agenten | Ticket anfordern, Datei vom Rechner per HTTP hochladen | Pfad oder Ordner nennen |
| Später alle | Die MCP-Erweiterung für Datei-Uploads lässt den Client die Datei übertragen, am Modell vorbei ([MCP-Blog](https://blog.modelcontextprotocol.io/posts/exploring-file-uploads-and-downloads-in-mcp/)); GARAGEs Ticket-Endpunkt passt dazu | Bild in den Chat, ein Satz |

**Wenn kein Weg greift** – etwa in der Claude-App auf dem Handy, falls sich die Fotoauswahl im Widget dort nicht öffnet 🟡: Der Agent erfasst die Daten trotzdem, und am Ereignis entsteht der Klärfall „Original fehlt“. Nachgereicht wird es über den Eingang (Weg B).

**Upload-Tickets:** `request_upload` liefert eine einmalige Adresse, 15 Minuten gültig, nur für den anfragenden Nutzer, mit Höchstgröße und erlaubten Typen. `store_document` verweist danach nur noch auf die Ticket-ID. GARAGE prüft Typ anhand der Bytes, Größe und SHA-256; Fotos werden neu kodiert, das Aufnahmedatum bleibt, GPS fällt weg (Dispatch ADR-011); PDFs bleiben byte-genau. Strato verträgt laut Dispatch-Messung 128 MB je Upload und 512 MB Speicher je Aufruf.

### Weg B · Eingang zuerst

Du lädst in der Web-App hoch, ein Agent arbeitet den Eingang später ab. Bilder in Tool-Ergebnissen sind normales MCP; Dispatch liefert so schon seine Queue-Anhänge an Agenten aus.

- **Hochladen:** Kamera-Knopf auf jeder Fahrzeugseite, mehrere Seiten nacheinander; der Browser verkleinert Fotos vorher. Auf Android zusätzlich „Teilen → GARAGE“ aus jeder App, denn installierte Web-Apps können sich als Teilen-Ziel anmelden ([Chrome for Developers](https://developer.chrome.com/docs/capabilities/web-apis/web-share-target)); iOS Safari kann das nicht ([plyr.fm-Recherche](https://tangled.org/zzstoatzz.io/plyr.fm/blob/main/docs/research/2025-12-19-web-share-target-ios-pwa.md)).
- **PDFs:** Die Upload-Seite rendert jede Seite zusätzlich als Bild, weil auf dem Strato-Paket kein PDF-Renderer läuft 🟡; das Original bleibt byte-genau.
- **Abarbeiten:** „arbeite den Eingang ab“ in Claude, ChatGPT oder als geplante Aufgabe in der Nacht. `claim_inbox_item` reserviert einen Eintrag mit Frist und liefert die Bilder gleich mit – das Muster von Dispatchs `claim_queue_item` (Standardfrist 180 Minuten, höchstens 6 Bilder je Antwort). Gelingt die Erfassung, schließt sie den Eintrag; sonst gibt `release_inbox_item` ihn zurück oder macht ihn zum Klärfall.
- **Abwägung:** läuft in jedem MCP-Client und sammelt Belege für einen späteren Durchgang; dafür zwei Schritte statt einem und eine Upload-Seite in der App.

### Verprobung

Die Abnahme von M2 prüft jeden Client mit echten Belegen auf beiden Wegen. Das Ergebnis entscheidet, welcher Weg Standard wird.

| Client | Weg A · Chat | Weg B · Eingang |
| --- | --- | --- |
| Claude Web und Desktop | Widget, laut Doku unterstützt | Bilder im Tool-Ergebnis, in Dispatch im Einsatz |
| Claude Android | Widget wird angezeigt, Fotoauswahl offen 🟡 | Bilder im Tool-Ergebnis erwartet 🟡 |
| ChatGPT Web | Datei-Parameter, dokumentiert | Bilder im Tool-Ergebnis 🟡 |
| ChatGPT Android | Fehler in der Handy-App gemeldet 🟡 | Bilder im Tool-Ergebnis 🟡 |
| Claude Code, Codex | Ticket-Upload vom Rechner | Bilder im Tool-Ergebnis |
| Geplante Aufgabe | – | arbeitet den Eingang ohne dich ab |

### Für beide Wege

**Was der Agent ablegt:** das Ereignis mit Positionen, Rechnungsnummer und km; den Beleg als Anhang mit Rolle „Rechnung“; den erkannten Text am Dokument für die Volltextsuche; eine Konfidenz je Feld. Ein unleserlicher Kilometerstand wird ein Klärfall, kein geratener Wert. PDFs liest das Modell schon im Chat, GARAGE muss sie nicht rendern: `get_document` liefert für PDFs den gespeicherten Text, für Fotos Bildblöcke, dichte Seiten auf Wunsch in zwei Hälften. Claude verkleinert Bilder über 1568 px Kante ([Claude Docs](https://platform.claude.com/docs/build-with-claude/vision-coordinates)); Dispatchs Grenze von 1024 px wird dafür angehoben.

**Fotos vom Auto:** derselbe Weg – „leg die als Fotos vom Golf ab, das erste ist das Titelbild“. Die Galerie ordnet nach Schlagwort (außen, innen, Motorraum, Tacho, Reifen, Schaden) und Aufnahmedatum. Ein Tacho-Foto wird zum Kilometerstand mit Beleg; Schadenfotos hängen am Schaden und am Versicherungsvorgang.

**Anhänge überall:** Die Tabelle `attachments` verbindet Dokumente mit Ereignis, Vertrag, Versicherungsvorgang, Schaden, Ausgabe, Tankvorgang, Zubehör, Plakette oder Fahrzeug; `upload_tickets` hält die Tickets. Tools: `store_document` (Datei-Parameter und Widget), `request_upload`, `import_document_from_url`, `get_document`, `attach_document`, `list_unassigned`, dazu `confirm_document`, das nur das Widget sieht. Für den Eingang kommen list\_inbox, claim\_inbox\_item und release\_inbox\_item dazu, gespeichert in den Tabellen inbox\_items und inbox\_documents.

## Web-Oberfläche

Mobil zuerst, ein Fahrzeug pro Bildschirm, an jedem Wert die Herkunft. Erfasst wird über MCP; Belege kommen im Chat oder über den Eingang der App herein. Die App zeigt an, nimmt Belege entgegen, löst Klärfälle und verwaltet Zugänge. Technisch eine HTML-Hülle mit Vanilla-JS-Modulen ohne Build-Schritt gegen `/garage/api`. Diagramme zeichnet der Server als SVG mit Dispatchs Renderer, also hell, dunkel und im Druck lesbar.

&#91;embedded content: Entwurf Fahrzeug-Cockpit · Werte des Golf, berechnet aus der Akte\]

Die Fälligkeiten im Entwurf sind aus den Regeln der Golf-Akte gerechnet. Gelb heißt nicht „bald fällig“, sondern „Regel unbelegt“ – Herkunft wird sichtbar, ohne dass jemand nachlesen muss.

| Ansicht | Zeigt | Form |
| --- | --- | --- |
| Garage | Oben der Unterhalt aller aktiven Autos pro Monat; je Fahrzeug Kennzeichen, Modell, km heute, nächste Fälligkeit, Klärfälle, Wertspanne | Karten |
| Cockpit | Kennzahlen, Fälligkeiten der nächsten 36 Monate, Klärfälle | Kacheln und Liste |
| Scheckheft | Ereignisse chronologisch, aufklappbar mit Positionen und Beleg-Vorschau; Lücken rot markiert | Zeitleiste |
| Wartungsplan | Aufgabe, Regel, zuletzt, nächstes Datum und km, Fortschritt bis Fälligkeit | Tabelle mit Balken |
| Messwerte | km-Verlauf mit Prognose, Profiltiefe je Rad, Batterie | Linien |
| Teile und Betriebsstoffe | Öl, Filter, Kerzen mit OEM-Nummer und Alternativen, Kopierknopf | Liste |
| Reifen | Sätze, montierter Satz, DOT-Alter, Profil | Karten |
| Wert | Ankerwerte und Korridor über die Zeit, Inserate als Punkte (Preis gegen km), Wertfaktoren-Check | Linie, Streuung, Checkliste |
| Belege | Bibliothek nach Typ, PDF-Vorschau, Bezug zu Ereignissen | Raster |
| Klärfälle | Zwei Werte nebeneinander mit Herkunft; Knöpfe „A gilt“, „B gilt“, „beide falsch“ | Vergleichskarten |
| Export | Verkaufsakte als Druckansicht (PDF über den Browser), Akte als Markdown | Druck-CSS |
| Zugänge | Tokens, verbundene Apps, Mitglieder, Einladungen, iCal-Link | Listen |
| Kosten | Unterhalt pro Monat und km, laufend und mit Wertverlust; Verträge mit Stichtagen; Hinweis auf fehlende Blöcke | gestapelte Säulen, Linie |
| Fotos | Galerie je Fahrzeug mit Titelbild, Filter nach Schlagwort und Datum, Vollbild mit Wischen | Raster, Vollbild |
| Verträge und Vorgänge | Versicherung, Steuer, Club; Beitragsverlauf, offene Vorgänge, Stichtage, Anhänge | Karten, Linie, Zeitleiste |
| Zubehör und Plaketten | Was montiert, was eingelagert ist, wo es liegt; Plaketten mit Ablaufdatum | Liste |
| Notfallkarte | Kennzeichen, FIN, Versicherer, Vertragsnummer, Schaden-Hotline, Pannenhilfe; offline verfügbar | eine Karte |
| Eingang | Hochgeladene Belege mit Status (neu, in Arbeit, erfasst, Klärfall), Notiz und Fahrzeug; Kamera-Knopf zum Nachlegen | Liste mit Vorschaubild |

**Gestaltungsregeln:**

- Der Kamera-Knopf für den Eingang schwebt unten rechts auf jeder Fahrzeugseite, in Reichweite des Daumens.
- Das Kennzeichen ist das Erkennungszeichen jedes Fahrzeugs, nicht ein Markenlogo.
- Farbe trägt Bedeutung: Rot überfällig, Gelb unsicher oder unter 3 Monaten / 3.000 km, sonst neutral. Die Schwellen sind einstellbar.
- Geschätzte Werte tragen immer „≈“ und ihren Rechenweg im Tooltip.
- Jeder Wert hat ein Herkunfts-Abzeichen (Beleg, Nutzer, Recherche, Agent); Tippen öffnet den Beleg.
- Die Druckansicht funktioniert ohne Skript.
- Jeder Datensatz zeigt seine Anhänge als Leiste mit Vorschaubildern; Tippen öffnet Foto oder PDF im Vollbild.
- GARAGE ist installierbar (Web-App-Manifest, auf Android mit Teilen-Ziel) und hält Notfallkarte und letzte Ansichten offline vor.
- Leere Zustände erklären den nächsten Schritt („Noch keine Versicherung – Police im Chat an GARAGE geben“) statt einer leeren Tabelle.

## Wertermittlung in der App

Die App ersetzt kein Gutachten. Sie sammelt Ankerwerte, rechnet einen nachvollziehbaren Marktkorridor aus Vergleichsinseraten und zeigt die Wertfaktoren, die sich aus der eigenen Historie ergeben – ohne versteckte Prozent-Formel.

**Warum keine Automatik:** DAT-Daten gibt es über SilverDAT nur für Geschäftskunden, etwa die VIN-Abfrage ab 1,85 € netto je Übertragung ([DAT](https://www.dat.de/fileadmin/de/download/flyer-dat-vin-abfrage.pdf)). Die APIs von mobile.de setzen ein API- oder Händlerkonto voraus ([mobile.de](https://services.mobile.de/)). Ein Agent recherchiert Inserate deshalb wie ein Mensch und legt jedes mit URL und Abrufdatum ab.

**Drei Bausteine:**

1. **Ankerwerte.** Kaufpreis, externe Bewertungen, Händlerangebote, Gutachten – je mit Wertart (Händlereinkauf oder Privatverkauf), Datum und km. Beim Golf existiert genau einer: 13.000 € im Dezember 2025 bei 17.000 km.
2. **Marktkorridor.** Ab 8 passenden Inseraten (gleicher Motor und Getriebe, EZ ±1 Jahr, km ±25 %) zeigt die App Median und mittlere Hälfte. Ab 15 Inseraten verschiebt eine lineare Regression Preis gegen km und Alter den Korridor auf den eigenen Stand. Inserate älter als 60 Tage fallen heraus. Angebotspreise sind keine Verkaufspreise; die Oberfläche sagt das an der Grafik.
3. **Wertfaktoren-Check.** Aus den eigenen Daten berechnet, je mit Plus, Minus oder Unbekannt und Fundstelle: Scheckheft lückenlos, HU-Restlaufzeit, Unfallfreiheit (Nutzerangabe), Halterzahl, letzte Profiltiefe, große Arbeiten in den nächsten 12 Monaten, offene Rückrufe, Ausstattung erfasst.

**Was das für die beiden Autos heißt:**

- **Clio:** EZ 09/2012, also 14 Jahre alt – jenseits der 12-Jahres-Grenze der Schwacke-Liste. Hier trägt nur der Marktvergleich. Für den Preis sprechen bei rund 200.000 km frischer Zahnriemensatz (01/2024), Kupplung (02/2024), Klimakondensator (08/2025), neue Allwetterreifen (04/2026) und HU bis 06/2027.
- **Golf:** Zwischen den Inspektionen 17.08.2021 und 10.12.2024 liegen 40 Monate. Bei flexiblem Service mit höchstens 24 Monaten 🟡 fehlt damit vermutlich ein Eintrag um 2023 – der Check würde „Scheckheft lückenlos“ auf Unbekannt setzen. Erst den digitalen Serviceplan bei Ostmann abfragen lassen; vielleicht steht der Eintrag dort.

Die Wert-Ansicht zeigt den Verlauf aus Ankerwerten und Korridor über die Zeit und daneben die Inserate als Punkte (Preis gegen km), das eigene Auto hervorgehoben.

## Gesamtkosten und Unterhalt

Die App rechnet den Unterhalt pro Monat und pro Kilometer, getrennt nach vier Blöcken und immer zweimal: laufend (ohne Wertverlust) und als Vollkosten (mit Wertverlust). Versicherung und Steuer sind dafür eigene Verträge mit Beitragsperioden, nicht nur Rechnungsbeträge.

Die Blöcke folgen dem ADAC-Schema: Betriebskosten (Kraftstoff, Öl, Pflege), Fixkosten (Steuer, Versicherung) und Werkstatt (Inspektion, Verschleiß, Reifen) ([t-online über den ADAC](https://www.t-online.de/mobilitaet/aktuelles/id_101177394/adac-kuert-guenstig-fahrende-gebrauchtwagen-mit-verbrennungsmotor.html)). Dazu kommt der Wertverlust: Kaufpreis minus Restwert, verteilt auf die Haltedauer ([finanz.de](https://www.finanz.de/finanzierung/autokosten/)).

| Block | Posten | Woher | Verteilung |
| --- | --- | --- | --- |
| Fixkosten | Kfz-Haftpflicht, Teil- oder Vollkasko, Schutzbrief | Versicherungsvertrag, Beitragsrechnung | Jahresbeitrag gleichmäßig über die Versicherungsperiode |
| Fixkosten | Kfz-Steuer | Steuerbescheid | 12 Monate ab Fälligkeit |
| Fixkosten | HU/AU, Automobilclub, Stellplatz | Beleg oder Vertrag | über die Gültigkeit, HU also 24 Monate |
| Betrieb | Kraftstoff | Tankbuch; ohne Tankbuch Schätzung aus Normverbrauch, als Schätzung markiert | am Tankdatum |
| Betrieb | Pflege, Wäsche, Parken, Maut, Reifeneinlagerung | Beleg | am Belegdatum |
| Werkstatt | Inspektion, Verschleiß, Reparatur, Reifen | Ereignisse aus dem Scheckheft | am Leistungsdatum |
| Wertverlust | Kaufpreis minus aktueller Wert | Ankerwerte und Marktkorridor | linear zwischen zwei Werten |
| Optional | Finanzierung, Leasing | Vertrag | monatlich |
| Zubehör | Träger, Kindersitz, Matten, Dashcam und anderes | Zubehörliste mit Beleg | am Kaufdatum |
| Fixkosten | Plaketten, Vignetten | Plaketten-Liste | über die Gültigkeit |
| Fixkosten | Automobilclub (ADAC, ACE) | Mitgliedschaft als Garagen-Vertrag | Jahresbeitrag, umgelegt auf die aktiven Autos |

```latex
\text{Unterhalt pro Monat} = \frac{\sum K(Z)}{\text{Monate}(Z)} \qquad \text{Kosten pro km} = \frac{\sum K(Z)}{km_{\text{Ende}}(Z) - km_{\text{Anfang}}(Z)}
```

Z ist der Zeitraum (Standard: letzte 12 Monate, umschaltbar auf „seit Kauf“ oder ein Kalenderjahr). Kilometerstände zwischen zwei Messungen interpoliert die App linear. Sie zeigt immer, welche Blöcke fehlen – eine Zahl ohne Versicherung wirkt sonst billiger, als das Auto ist.

### Umlage auf alle aktiven Autos

Manche Kosten gehören zu keinem einzelnen Auto. Die ADAC-Mitgliedschaft etwa ist personenbezogen: Das Mitglied ist in jedem Fahrzeug geschützt, das es alleinverantwortlich steuert ([ADAC](https://www.adac.de/der-adac/verein/corporate-news/adac-community/)). Solche Verträge hängen an der Garage statt an einem Fahrzeug und werden umgelegt.

| Umlageschlüssel | Wirkung | Passt für |
| --- | --- | --- |
| gleich (Standard) | Beitrag geteilt durch die Zahl der aktiven Autos, tagesgenau | ADAC, ACE, Verkehrsrechtsschutz |
| nach km | Anteil an den gefahrenen km im Zeitraum | Waschabo, Mautbox |
| feste Anteile | etwa 70/30, mit Gültigkeit | gemeinsamer Stellplatz |
| ein Auto | wie ein Fahrzeugvertrag | Ausnahmen |

Aktiv ist ein Auto zwischen Kauf oder Zulassung und Verkauf oder Stilllegung. Kommt eins dazu oder geht eins, verschiebt sich die Umlage ab diesem Tag; vor der Umschreibung des Golf am 08.01.2026 trug der Clio den Club-Beitrag allein. Kfz-Versicherung und Kfz-Steuer bleiben am einzelnen Auto, weil jedes eigene Verträge und Bescheide hat; die Garage summiert sie nur.

**Beispiel ADAC:** Plus für eine Person kostet 94 € im Jahr und steigt auf 114 € ([Finanztip](https://www.finanztip.de/daily/adac-erhoeht-preise-check-mal-diese-guenstigen-alternativen/)), voraussichtlich im ersten Halbjahr 2027 statt wie geplant zum 1. Januar ([ADAC](https://www.adac.de/der-adac/verein/corporate-news/adac-zukunftssicher/)). Gleich verteilt trägt jedes Auto 47 € im Jahr (3,92 €/Monat), nach der Erhöhung 57 €. Nach km (Golf ≈ 13.000, Clio ≈ 9.800 km im Jahr) wären es 53,78 € und 40,22 €. Welchen Tarif du hast, steht noch nicht in der Akte; die Erhöhung wird eine neue Beitragsperiode im Vertrag.

**Garage gesamt:** Die Garagen-Übersicht zeigt den Unterhalt aller aktiven Autos pro Monat, gestapelt nach Auto, und die Kosten pro km über alle gefahrenen km. Jede Fahrzeugansicht weist die Umlage als eigene Zeile aus („davon ADAC-Anteil 3,92 €“).

**Modell:** `contracts` bekommen eine Ebene (Fahrzeug oder Garage) und einen Umlageschlüssel; feste Anteile mit Gültigkeit stehen in `contract_allocations`. `get_cost_summary` liefert ohne Fahrzeug die Garagen-Summe.

**Vertragsdaten Versicherung:** Versicherer, Vertragsnummer, Tarif, Beginn, Hauptfälligkeit, Kündigungsfrist, Zahlweise, Beitrag je Sparte (Haftpflicht, Teil-, Vollkasko), SF-Klassen, Typklassen je Jahr, Regionalklasse, Selbstbeteiligungen, angegebene Jahresfahrleistung, Fahrerkreis, Werkstattbindung, gemeldete Schäden.

**Vertragsdaten Steuer:** Bescheiddatum, Jahresbetrag, Fälligkeit, Zahlweise, Bemessung (Hubraum, CO₂, EZ), Befreiung.

**Prüfungen, die die App daraus rechnet:**

- **Steuer gegen Bescheid.** Für Benziner mit EZ ab 07/2009 gelten 2 € je angefangene 100 cm³ plus 2 € je g CO₂ über der Freigrenze: 120 g/km bis 2011, 110 g/km in 2012–2013, 95 g/km ab 2014 ([steuerschroeder.de](https://www.steuerschroeder.de/Steuerrechner/Kfz-Steuer.html)). Golf: 15 × 2 € + (147 − 95) × 2 € = 134 € – passt exakt zum Bescheid. Clio: Freigrenze 110 g/km, aber der CO₂-Wert aus Feld V.7 fehlt in der Akte.
- **Kündigungsstichtag.** Hauptfälligkeit minus Kündigungsfrist landet im Fälligkeits-Feed. Läuft der Vertrag bis 31. Dezember, ist der 30. November Stichtag; bei unterjähriger Hauptfälligkeit verschiebt er sich ([Allianz Direct](https://www.allianzdirect.de/kfz-versicherung/kuendigen/)). Nach einer Beitragserhöhung gibt es ab Zugang der Rechnung einen Monat Sonderkündigungsrecht ([steuertipps.de](https://www.steuertipps.de/altersvorsorge-rente-finanzen/autoversicherung-bis-30-11-kuendigen-kann-viel-geld-sparen)).
- **Jahresfahrleistung.** Die km-Prognose (Golf etwa 13.000 km/Jahr, Clio etwa 9.800 km/Jahr) steht neben der Angabe im Vertrag. Was eine Abweichung auslöst, regeln die Vertragsbedingungen; die App zeigt sie nur.
- **Beitragsverlauf.** Beitrag, SF- und Typklasse je Jahr als Linie, damit Sprünge auffallen.

**Was die Golf-Akte heute hergibt** (seit Umschreibung am 08.01.2026, Kauf bei 17.000 km):

| Block | Stand | Betrag |
| --- | --- | --- |
| Werkstatt | 3 Belege (02.03., 04.05., 06.10.2026) | 1.288,70 € |
| Steuer | Bescheid, anteilig 310 Tage | 113,81 € |
| Versicherung | fehlt | – |
| Kraftstoff | fehlt | – |
| Wertverlust | nur Kaufpreis 13.000 €, kein zweiter Wert | – |

Das ergibt ≈ 1.402 € für ≈ 11.300 km: rund 0,12 €/km und 138 €/Monat. Die App würde daran „unvollständig: Versicherung, Kraftstoff, Wertverlust fehlen“ schreiben.

**Modell und Tools:** Die Tabelle `costs` wird ersetzt durch `contracts` (Vertrag), `contract_terms` (Beitragsperiode mit Merkmalen wie SF- und Typklasse), `contract_items` (welche Fahrzeuge ein Vertrag abdeckt), `expenses` (sonstige Kosten mit Zeitraum für die Verteilung) und `fuel_logs` (Tankbuch: Datum, km, Liter, Preis, voll getankt). Neue Tools: `upsert_contract`, `record_contract_term`, `record_expense`, `record_fuel`, `get_cost_summary` (Zeitraum, Gruppierung nach Block oder Monat). Ein Agent erfasst Tankbelege aus Fotos und Beitragsrechnungen aus PDFs wie Werkstattrechnungen.

**Ansicht Kosten:** Monatssäulen gestapelt nach Block, daneben die Linie Kosten pro km über 12 rollierende Monate, darunter die Verträge mit Stichtagen.

## Rund ums Auto

Neben Wartung und Kosten führt die Akte alles, was ein Auto sonst an Papier, Fristen und Kleinkram erzeugt. Jeder Eintrag kann Fotos und Dokumente tragen, und jede Frist landet im Kalender-Abo.

| Bereich | Inhalt | Fristen | Kosten |
| --- | --- | --- | --- |
| Versicherungsvorgänge | Beitragsrechnung, Vertragsänderung, Schadenmeldung mit Schadennummer, Regulierung, SF-Rückstufung, Kündigung; dazu Schadenfotos, Gutachten, Schriftverkehr | Kündigungsstichtag, Sonderkündigung nach Beitragserhöhung | Beiträge, Selbstbeteiligung |
| Steuer | Bescheid je Jahr, Änderungsbescheide | Fälligkeit | Jahresbetrag |
| Zubehör | Bezeichnung, Kategorie (Träger, Kindersitz, Matten, Dashcam, Kabel), Hersteller, Artikelnummer, Kaufdatum, Preis, Garantie bis, montiert oder eingelagert, Lagerort, geht beim Verkauf mit | Garantieende | Kaufpreis |
| Plaketten und Vignetten | Umweltplakette, Crit'Air (der Golf hat eine), Vignetten, Parkausweis, Mautbox | gültig bis | Preis |
| Schlüssel und Papiere | Schlüsselanzahl (zählt bei der Wertermittlung), Ort von Ersatzschlüssel, Zulassungsbescheinigung Teil II, COC und Kaufvertrag | – | – |
| Pannenhilfe und Club | Mitgliedsnummer, Notrufnummer, Leistungen; als Garagen-Vertrag auf alle aktiven Autos umgelegt | Verlängerung | Beitrag |
| Sonstige Ausgaben | Parken, Wäsche, Reifeneinlagerung, Maut, auf Wunsch Bußgelder | – | Betrag |

**Versicherungsvorgänge** sind eigene Datensätze, nicht nur Notizen: Art, Datum, Betrag, Status (offen, in Bearbeitung, erledigt), Schadennummer, Bezug zum Schaden in der Historie. Eine Beitragsrechnung erzeugt einen Vorgang und eine neue Beitragsperiode; eine Schadenmeldung verknüpft Schaden, Fotos und spätere Regulierung.

**Notfallkarte:** Auf der Fahrzeugseite liegt eine Karte mit Kennzeichen, FIN, Versicherer, Vertragsnummer, Schaden-Hotline und Pannenhilfe. Die App hält sie offline vor, damit sie nach einem Unfall auch ohne Netz da ist.

**Modell und Tools:** neu sind `contract_events` (Versicherungsvorgänge), `accessories` (Zubehör) und `permits` (Plaketten, Vignetten, Ausweise); Schlüsselanzahl und Ablageorte stehen in `vehicles`. Dazu die Tools `record_contract_event`, `upsert_accessory` und `upsert_permit`.

## Migration der beiden Akten

Die Belege sind die Quelle, die Markdown-Akten nur die Gegenprobe. Ein Agent baut die Akten aus den Ordnern `Golf Sportsvan/` und `Clio/` neu auf und vergleicht am Ende mit den alten Dateien.

1. Beide Fahrzeuge mit Stammdaten anlegen (`set_vehicle_facts`), Herkunft „Fahrzeugschein“.
2. Alle Belege per Upload-Ticket vom Rechner hochladen (`request_upload`); Dubletten fängt der SHA-256 ab.
3. Prompt `beleg_erfassen` Rechnung für Rechnung: erst `validate_service_event`, dann `record_service_event`.
4. Intervallregeln mit Herkunft übernehmen: Werkstatt-Serviceplan = bestätigt, Web-Recherche = wahrscheinlich, DSG-Regel = unsicher.
5. Erklärtexte als Notizen übernehmen: DSG-Langlebigkeitsstrategie (Golf), Kühlmittel-Spezifikation und Nachfüllhinweise (Clio).
6. Die zehn Widersprüche aus der Bestandsaufnahme als Klärfälle anlegen; dazu „App-Connect freischalten“, „Batterie beobachten“ und „Kühlmittelstand auf MIN“.
7. `get_dossier` exportieren und mit den alten Dateien vergleichen. Jede Abweichung ist entweder ein Erfassungsfehler oder ein neuer Klärfall.

Danach schreibt kein Agent mehr in Markdown-Dateien. Wer die Akte als Datei braucht, holt sie aus `get_dossier`.

## Meilensteine

Acht Meilensteine, und jeder liefert eine fertige, am Handy abgenommene Ansicht. Die Gestaltung beginnt in M0 mit Design-Tokens, Komponenten und einem klickbaren Entwurf aller Hauptansichten; ab M2 erfassen Agenten echte Belege per Foto.

&#91;embedded content: Meilensteine M0–M7 · je eine Ansicht und eine Abnahme\]

Jede Abnahme ist Handprüfung wie bei Dispatch (ADR-004), mit festen Punkten für die Bedienung: 360 px Breite ohne waagerechtes Scrollen, hell und dunkel, Hauptaktion in Daumenreichweite, leere Zustände mit nächstem Schritt. Google- und Apple-Login kommen erst in M6; bis dahin reichen Passwort und feste Tokens je Nutzer.

## Offene Entscheidungen

Zehn Fragen klärt der Inhaber vor Meilenstein 1; die Empfehlung steht jeweils daneben.

| Frage | Optionen | Empfehlung |
| --- | --- | --- |
| Wo läuft GARAGE? | `/garage/` auf quantum-canvas.de neben Dispatch; eigene Domain | `/garage/`, solange nur der Haushalt sie nutzt. Dafür Dispatchs reservierte Namen und den Ausnahme-Block der Wurzel-`.htaccess` erweitern. Eigene Domain, sobald Fremde Konten bekommen. |
| Codebasis | Fork von Dispatch; gemeinsamer Kern-Ordner, per Skript in beide Repos kopiert (kein Composer) | Fork jetzt, Kern (MCP, OAuth, Auth, HttpClient, Medien, Diagramme) nach Meilenstein 3 herauslösen; derselbe Kern trägt später die geplante Hausrat-App |
| Registrierung | offen; nur per Einladung | nur per Einladung |
| Schreibrechte der Agenten | direkt mit Herkunft und Klärfällen; Freigabe-Warteschlange wie Dispatch | direkt; nur Änderungen an bestätigten Werten werden Klärfälle |
| Erinnerungen | Cron (bei Strato nur auf der Legacy-Plattform ab Hosting Plus, 15 Minuten Laufzeit – [Strato](https://www.strato.de/faq/article/1211/So-einfach-richten-Sie-Ihre-Cron-Jobs-ein.html)); iCal-Abo je Garage; geplante Agenten-Aufgabe | iCal-Abo mit geheimer URL – der Telefonkalender fragt selbst ab, kein Cron nötig. Optional zusätzlich eine geplante Aufgabe, die `get_due_items` liest. |
| Belege und Datenschutz | Ablage im gesperrten Ordner; zusätzlich verschlüsselt | gesperrter Ordner, Auslieferung nur über signierte Links mit kurzer Laufzeit (Dispatch-Muster). Bei fremden Nutzern: Impressum, Datenschutzerklärung, Löschkonzept. |
| Name | GARAGE; LOGBUCH; anderer | Geschmackssache – im Dispatch-Muster „GARAGE – Your agents. Your cars.“ |
| Apple-Login | gleich mit Apple Developer Program (99 $ im Jahr); erst Google und Passwort | Google und Passwort in M6; Apple dort nur, wenn ein Mitglied ein iPhone nutzt, sonst später – die Tabelle user\_identities ist darauf vorbereitet |
| PDFs für Agenten | Seitenbilder erzeugen; Original plus erkannter Text | Original plus der Text, den der Agent beim Erfassen schreibt – das Modell liest PDFs ohnehin im Chat; im Eingang zusätzlich Seitenbilder aus dem Browser |
| Belegweg auf dem Handy | Das Widget läuft laut Anthropic auch auf Android (als WebView); offen ist, ob sich darin die Fotoauswahl öffnet | Weg A (Chat) und Weg B (Eingang) beide bauen und in M2 an fünf Clients verproben; ein Test-Widget in Dispatch, das schon mit der Android-App verbunden ist, kann vorab Klarheit bringen 🟡 |

## Quellen

- [Dispatch, Repository](https://github.com/Aalphones/Dispatch) – Stack, ADR-011, 016, 017, 018, 024, 025, 030
- [VW Financial Services: Schwacke-Liste](https://www.vwfs.de/service/mobilitaetslexikon/s/schwacke-liste.html)
- [VW Financial Services: Restwert vom Auto](https://www.vwfs.de/magazin/auto-und-mobilitaet/restwert.html)
- [Allianz: Wertverlust beim Auto](https://www.allianz.de/auto/kfz-versicherung/wertverlust-auto/) (Stand 27.07.2026)
- [ADAC: HSN und TSN](https://www.adac.de/rund-ums-fahrzeug/auto-kaufen-verkaufen/kfz-zulassung/hsn-tsn-faq/)
- [CarCheckerVIN: KBA-Rückruf per Fahrgestellnummer](https://www.carcheckervin.com/de/kba-rueckruf-fahrgestellnummer)
- [DAT: VIN-Abfrage über SilverDAT](https://www.dat.de/fileadmin/de/download/flyer-dat-vin-abfrage.pdf)
- [mobile.de: APIs Overview](https://services.mobile.de/)
- [Strato: Cron-Jobs einrichten](https://www.strato.de/faq/article/1211/So-einfach-richten-Sie-Ihre-Cron-Jobs-ein.html)
- Fahrzeugakten Golf Sportsvan (Stand 31.10.2026) und Clio III (Stand 28.08.2026), hochgeladen am 14.11.2026

Ergänzt am 14.11.2026 (Kosten, Belege, Anmeldung):

- [t-online: ADAC kürt günstig fahrende Gebrauchtwagen](https://www.t-online.de/mobilitaet/aktuelles/id_101177394/adac-kuert-guenstig-fahrende-gebrauchtwagen-mit-verbrennungsmotor.html)
- [finanz.de: Autokosten berechnen](https://www.finanz.de/finanzierung/autokosten/)
- [steuerschroeder.de: Kfz-Steuer 2026](https://www.steuerschroeder.de/Steuerrechner/Kfz-Steuer.html)
- [Allianz Direct: Kfz-Versicherung kündigen](https://www.allianzdirect.de/kfz-versicherung/kuendigen/)
- [steuertipps.de: Autoversicherung bis 30.11. kündigen](https://www.steuertipps.de/altersvorsorge-rente-finanzen/autoversicherung-bis-30-11-kuendigen-kann-viel-geld-sparen)
- [OpenAI Apps SDK: Referenz, Datei-Parameter](https://developers.openai.com/apps-sdk/reference)
- [OpenAI-Forum: MCP-Datei-Uploads in der Handy-App](https://community.openai.com/t/mcp-file-uploads-broken-on-mobile-app/1372060)
- [MCP: Extensions – MCP Apps](https://modelcontextprotocol.io/docs/extensions/apps)
- [MCP-Blog: Datei-Uploads und -Downloads in MCP](https://blog.modelcontextprotocol.io/posts/exploring-file-uploads-and-downloads-in-mcp/)
- [DEV Community: MCP-Dateiübertragung im Test](https://dev.to/kenimo49/7-services-0-upload-solutions-why-mcp-file-transfer-fails-2f2o)
- [jztan: Base64-Uploads in MCP](https://blog.jztan.com/mcp-file-upload-base64-corruption/)
- [FutureSearch: Große Dateien über MCP](https://futuresearch.ai/blog/mcp-large-dataset-upload)
- [Claude Docs: Bildgrößen und Koordinaten](https://platform.claude.com/docs/build-with-claude/vision-coordinates)
- [Google: OpenID Connect Referenz](https://developers.google.com/identity/openid-connect/reference?hl=en)
- [Google: Markenprüfung des Zustimmungsbildschirms](https://support.google.com/cloud/answer/13463073?hl=id)
- [Unipile: Google OAuth Verification](https://www.unipile.com/integrating-google-oauth-2-0-user-authentication-into-your-app/)
- [Google Cloud: Anmeldung mit Apple](https://docs.cloud.google.com/identity-platform/docs/web/apple?authuser=9)
- [Coinbase Docs: Apple Sign In](https://docs.cdp.coinbase.com/embedded-wallets/social-login/apple)
- [sarunw: Private Email Relay Service](https://sarunw.com/posts/sign-in-with-apple-2/)
- Dispatch, `docs/knowledge/INDEX.md`: Strato-Messwerte (Upload 128 MB, Speicher 512 MB)
- [Chrome for Developers: Web Share Target API](https://developer.chrome.com/docs/capabilities/web-apis/web-share-target)
- [plyr.fm: Web Share Target auf iOS](https://tangled.org/zzstoatzz.io/plyr.fm/blob/main/docs/research/2025-12-19-web-share-target-ios-pwa.md)
- [Claude Help Center: Interaktive Connectors](https://support.claude.com/en/articles/13454812/use-interactive-connectors-in-claude)
- [Claude Docs: MCP Apps, Design-Richtlinien](https://claude.com/docs/connectors/building/mcp-apps/design-guidelines)
- Dispatch, `app/src/Mcp/Tools/QueueTools.php` und `MediaTools.php`
- [ADAC: Hilfe auf allen Kanälen (Mitgliedschaft personenbezogen)](https://www.adac.de/der-adac/verein/corporate-news/adac-community/)
- [ADAC: Fakten zur Beitragserhöhung](https://www.adac.de/der-adac/verein/corporate-news/adac-zukunftssicher/)
- [Finanztip: ADAC erhöht Preise](https://www.finanztip.de/daily/adac-erhoeht-preise-check-mal-diese-guenstigen-alternativen/)
