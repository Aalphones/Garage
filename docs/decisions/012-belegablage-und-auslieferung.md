# 012 — Belegablage, Upload-Ticket und signierte Links

**Status:** Akzeptiert (2026-11-17) — Meilenstein 3, Phase 1.

## Kontext

Belege und Fotos kommen aus Chats (ChatGPT, Claude) und aus dem Browser. Dateien dürfen nie durch das Sprachmodell laufen (Base64 im Tool-Argument sprengt Kontext und Grenzen), Fotos tragen GPS-Daten, und ein Mensch muss Belege sehen können, ohne dass die Dateien öffentlich liegen.

## Entscheidung

- **Ein Speicher für alle Wege:** Tabelle `documents`, Dateien unter `app/storage/documents/<garage_id>/<uid>/`. PDFs byte-genau als `original.pdf`; Bilder werden immer neu kodiert (`original.jpg`, längste Kante höchstens 4000 px, Qualität 90) plus `1568.jpg` (für Agenten) und `400.jpg` (Vorschau). EXIF und GPS fallen weg; das Aufnahmedatum (`DateTimeOriginal`) wird vorher gelesen und als `captured_on` gespeichert.
- **Erlaubt nach Bytes** (`finfo`): JPEG, PNG, WebP, GIF, PDF; höchstens 30 MB, 50 Megapixel (vor dem Dekodieren geprüft). HEIC, SVG, HTML werden abgewiesen. Dubletten über SHA-256 der Eingangs-Bytes je Garage: zweites Hochladen liefert das vorhandene Dokument mit `duplicate: true`.
- **Upload-Ticket:** `request_upload` liefert eine einmalige Adresse `POST /garage/upload/{slug}` (Slug 32 Hex, in der Datenbank nur als SHA-256), 15 Minuten gültig, gebunden an Nutzer und Garage. Der Slug ist die Berechtigung; die Rolle in der Garage wird beim Einlösen erneut geprüft. Erst `store_document` mit `ticket_id` setzt Typ, Fahrzeug und Titel; auf einer Dublette überschreibt es nichts.
- **Auslieferung nur über signierte Links:** `GET /garage/files/{slug}`, Slug = `base64url(payload).base64url(hmac)`, 600 Sekunden gültig, Schlüssel abgeleitet aus `GARAGE_JWT_SECRET`. Kein Login nötig, der Link ist die Berechtigung; er gilt für jeden, der ihn hat, bis er abläuft.
- **Status des Dokuments:** `wartet` (Datei fehlt), `unzugeordnet` (Datei da, kein Anhang), `zugeordnet`, `verworfen`. Nichts wird hart gelöscht, auch nicht die Datei.

## Verworfen

- **Original-Bytes von Fotos behalten:** EXIF/GPS blieben im Bestand und in jedem Download.
- **Auslieferung über Login-Cookie:** Agenten und Download-Werkzeuge hätten keinen Zugang; ein Link ist teilbar und läuft ab.
- **Base64 im Tool-Argument:** Kontext- und Größengrenzen der Clients, die Datei liefe durchs Modell.

## Konsequenzen

- Die Ticket-Prüfung (`findTicketByHash`) und die Auslieferung (`findById`) sind die einzigen Abfragen ohne Akteur (Ausnahme von Regel 3 in `AGENTS.md`); beide stehen im Repository mit Kommentar.
- Das HTTP-Gerüst der Domain setzt die Content-Security-Policy der Website auch auf Dateiantworten und ersetzt eine eigene (`sandbox`); wirksam bleibt `default-src 'self'` samt `nosniff`.
