# Phase 11 — Notfallkarte offline

Rating: heikel · Modell: opus (Service Worker: Caches, Aktualisierung, Abmelden, Teilen-Ziel darf nicht brechen)

## Kontext

- ADR-025; Entwurf → Golf · Papiere (Zustand „offline auf diesem Gerät“, Zeile „Stand …“)
- `web/sw.js` (heute nur Teilen-Ziel: `fetch`-Handler für POST `/garage/teilen`, Cache `garage-geteilt`), `web/js/share.js` (`registerShareWorker`), `web/js/api.js` (`logout`), `web/js/tab-papers.js` (Phase 10), `web/js/garage.js`, `web/.htaccess` (CSP `'self'`), `web/manifest.webmanifest`
- API `GET vehicles/{id}/emergency` (Phase 6)
- Fehlerklassen: keine im Vault; Fallen als AK unten

## AK

- [x] Neue Seite `web/notfall.html` + `web/js/notfall.js`: zeigt die Notfallkarte(n) **nur aus dem Cache** `garage-notfall` (Einträge `/garage/notfall/<vehicle_id>.json`), ohne Anmeldung, ohne API-Aufruf; Adresse `notfall.html#<id>` zeigt eine Karte, ohne Hash alle gespeicherten; nichts gespeichert → Hinweis „Noch keine Notfallkarte auf diesem Gerät — öffne einmal den Reiter Papiere mit Netz.“ Gestaltung wie `.ecard` im Entwurf, Telefonnummern als `tel:`-Links, Zeile „Stand <as_of>“.
- [x] `tab-papers.js` schreibt nach jedem erfolgreichen Laden die `emergency`-Daten per Cache API (`caches.open('garage-notfall')`, `put('/garage/notfall/<id>.json', new Response(JSON.stringify(data), {headers:{'Content-Type':'application/json'}}))`) und zeigt dann „offline auf diesem Gerät“ und einen Link „Notfallkarte öffnen“ → `notfall.html#<id>`. `garage.js` aktualisiert nach dem Laden der Garage die Karte jedes aktiven Fahrzeugs im Hintergrund (ein `GET …/emergency` je Fahrzeug, Fehler still verwerfen).
- [x] `sw.js`: beim `install` die Hülle `notfall.html`, `js/notfall.js`, `js/theme.js`, `css/tokens.css`, `css/base.css`, `img/hintergrund.jpg` in `garage-notfall-huelle-v1` legen; `activate` löscht ältere `garage-notfall-huelle-*`; `fetch`: GET auf `/garage/notfall.html` (und die gelisteten Dateien, nur wenn die Anfrage von `notfall.html` kommt — einfacher: genau diese Pfade) → erst Netz, bei Fehler Cache; alle anderen Anfragen unverändert durchlassen (kein `respondWith`). Der POST des Teilen-Ziels funktioniert wie vorher.
- [x] Der Service Worker wird auch auf `fahrzeug.html` und `notfall.html` angemeldet (`registerShareWorker` umbenennen in `registerWorker`, Aufrufer anpassen).
- [x] `api.js` → `logout` löscht `garage-notfall` (`caches.delete`) vor der Weiterleitung; die Hülle bleibt (enthält keine Daten).
- [x] Manifest: `shortcuts` mit „Notfallkarte“ → `/garage/notfall.html`.
- [ ] Prüfung im Browser (nach Deploy oder lokal mit dem Dev-Server): Reiter Papiere laden → DevTools „Offline“ → `notfall.html#<id>` lädt mit Karte; Abmelden → `notfall.html` zeigt den Hinweis; Teilen-Ziel-POST (DevTools: `fetch('/garage/teilen', {method:'POST', body: new FormData()})`) leitet weiter wie bisher.

## Checkliste

- [x] `web/notfall.html`, `web/js/notfall.js`, `web/sw.js`, `web/js/share.js`, `web/js/api.js`, `web/js/tab-papers.js`, `web/js/garage.js`, `web/js/vehicle.js`, `web/manifest.webmanifest`.
- [x] Kommentar oben in `sw.js` auf den neuen Zweck anpassen (Teilen-Ziel + Hülle der Notfallkarte, keine App-Daten außer `garage-notfall`).
- [x] `node --input-type=module --check < …` für Module; `node --check web/sw.js` (klassisches Skript).
- [x] Doku: `docs/code-map.md` (Seite `notfall.html`, Caches), `docs/conventions/frontend.md` (welche Caches es gibt, wer sie löscht), `docs/knowledge/GAPS.md` (iOS: Service Worker eines nicht installierten Web-Auftritts kann nach Wochen ohne Besuch geräumt werden).

## Report-Back

- Karte `.ecard` und der Gerätecache in neuem `web/js/emergency.js` (speichern, lesen, löschen, Karte bauen); Reiter Papiere und `notfall.html` nutzen dieselbe Karte. `notfall.js` importiert nur `dom.js`, `emergency.js`, `share.js` — die Hülle in `sw.js` (`SHELL_FILES`) enthält deshalb diese drei zusätzlich zur Liste im AK.
- Hülle wird einzeln (`cache.add` je Datei, `allSettled`) statt per `addAll` gefüllt: eine fehlende Datei ließe sonst die Installation scheitern und das Teilen-Ziel fiele mit aus. Netz-zuerst frischt die Hülle bei jedem erfolgreichen Abruf auf.
- Reiter Papiere speichert die Karte auch im Leer-Zustand („Noch keine Papiere“), weil Kennzeichen und FIN trotzdem drauf gehören; „offline auf diesem Gerät“ und der Link erscheinen erst nach erfolgreichem Schreiben.
- `logout()` löscht `garage-notfall` im `finally`, also auch, wenn der Server die Abmeldung ablehnt; betrifft auch die Abmeldung auf der Zustimmungsseite.
- Offen: Browser-Prüfung (letzter AK-Punkt) nicht gelaufen — private Profil ohne Live-Läufe; als Lücke in `docs/knowledge/GAPS.md`. Syntax per `node --check` aller geänderten Module und `sw.js`, Manifest per `JSON.parse`.
- Karten verkaufter oder gelöschter Fahrzeuge bleiben im Gerätecache, bis abgemeldet wird.
