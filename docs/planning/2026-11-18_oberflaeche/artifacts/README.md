# Entwurf — Oberfläche (Meilenstein 4)

| Datei | Bildschirme | Phase |
|---|---|---|
| `entwurf-oberflaeche.html` | Garage (Filter, Gelöscht), Fahrzeug mit sieben Reitern (Übersicht, Scheckheft Zeitstrahl/Tabelle, Wartung Tabelle/Karten, Messwerte, Reifen, Teile, Belege), Klärfälle, Eingang, Konto (Darstellung), Akte drucken; Blätter Status ändern, Fahrzeug löschen, Notiz, Beleg (mit „Als Titelbild setzen“), Kilometerstand eintragen, Wert korrigieren, Notiz anlegen/bearbeiten; Kamera-Knopf mit direkter Kamera und Leiste „Foto liegt im Eingang“; Klärfälle aufklappbar in der Übersicht; Herkunfts-Popover; Zustände leer/lädt/Fehler; Rollen Inhaber/Mitglied/lesend | 1 (Tokens, Bauteile), 5–8 (Seiten) |
| `_entwurf-oberflaeche/hintergrund.jpg` | Hintergrundbild des Inhaltsbereichs (1920 × 1081, aus dem Bild des Inhabers verkleinert) | 1 → `web/img/hintergrund.jpg` |

**Ansehen:** `entwurf-oberflaeche.html` direkt im Browser öffnen (braucht nur den Bildordner daneben; das Beispielfoto des Golf lädt von images.unsplash.com). Die graue Entwurfsleiste oben wechselt Thema, Rolle und Zustand, „Werkzeug zeigen“ blendet zu jeder Aktion die API-Route bzw. das MCP-Tool ein, „Springen“ öffnet jeden Bildschirm, rechts die Breitenstufe; darunter steht, wofür der Bildschirm da ist. URL-Parameter für Prüfbilder: `?theme=light`, `?state=leer|laden|fehler`, `?role=lesend`, `?tools=1`, `?open=status|src|dd|oi|km|fact`.

**Gestaltung:** eigenes Token-System „GARAGE Dark Botanical“ (ADR-017), Familie von DISPATCH: Flächen fast schwarz/grau mit Verlauf ins Dunkelgrüne, Teal `#43D6C5` nur als Hervorhebung, Zustandsfarben Rot `#F08A80` / Gelb `#EDC961` (hell `#B42318` / `#7A5200`). Kontrast aller Text-Tokens gegen Hintergrund, Fläche und erhöhte Fläche gerechnet: mindestens 5,1:1 (hell) bzw. 6,6:1 (dunkel); Rahmen von Bedienelementen ≥ 3:1.

**Platzhalter:** alle Werte mit „Beispiel“-Marke und alles, was nicht in der Bestandsaufnahme des Konzepts steht (Kennzeichen, Beträge, Werkstatt, Teilenummern, Profiltiefen, Polo, „Golf (doppelt)“); das Unsplash-Foto (VW Polo) steht für ein Titelbild. Nicht Teil der App: Entwurfsleiste, „Beispiel“-Marken, Werkzeug-Einblendungen, die Simulation von Löschen/Lösen ohne Server.

Geprüft 2026-11-18 per Headless-Chrome: alle zwölf Ansichten bei 360 px mit gemessenem Überlauf 0 (Garage, sieben Reiter, Klärfälle, Eingang, Konto, Druck), 390 px hell und dunkel (dazu Konto mit offener Auswahl, Blatt Status, leerer Zustand), 768 px Übersicht und Scheckheft, 860 px Wartung, 1366 px Übersicht und Scheckheft mit stehender Seitenleiste, 1440 px Übersicht.

**Neu erzeugen:** keine Erzeugung — die HTML-Datei ist die Quelle. Das Bild: aus dem Original (4096 × 2306 PNG des Inhabers) auf 1920 px Breite, JPEG Qualität 72.
