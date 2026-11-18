# Phase 5 — Teilen-Ziel Android

**Rating:** standard. Modell: `sonnet`.

## Kontext — vor dem ersten Edit lesen

- Plan-README: [`README.md`](README.md) — E14.
- Entwurf: [`artifacts/entwurf-belege.html#teilen`](artifacts/entwurf-belege.html) (Bildschirm „Geteilt (Android)“: Hinweis „2 Dateien aus ‚Teilen‘ übernommen“, darunter das Formular „Neuer Eintrag“ mit den geteilten Dateien).
- Report-Back Phase 4; `web/eingang.html`, `web/js/inbox.js`, `web/js/capture.js` (Funktion, die eine Liste von `File` in die Seitenliste übernimmt), `web/.htaccess`, `docs/conventions/frontend.md`.
- Web Share Target: https://developer.chrome.com/docs/capabilities/web-apis/web-share-target (POST + `multipart/form-data`, `params.files`, Service Worker fängt den POST ab). iOS unterstützt es nicht.
- Vault-Fehlerklassen: keine einschlägig.

## Abnahmekriterien

- [x] `https://quantum-canvas.de/garage/manifest.webmanifest` liefert `application/manifest+json` (per curl belegt, gültiges JSON); Chrome-DevTools „Installierbar“ nicht gesehen — beim Handy-Test mitprüfen.
- [x] `https://quantum-canvas.de/garage/sw.js` liefert 200, `Service-Worker-Allowed: /garage/`, `Cache-Control: no-cache` (per curl); die Registrierung im Browser nicht gesehen. Kein Offline-Cache der Oberfläche: der Worker behandelt nur den Teilen-POST.
- [ ] Android (Inhaber, offen): installierte Web-App erscheint in „Teilen“ als „GARAGE“; ein geteiltes PDF und ein Foto landen in `eingang.html` im Formular „Neuer Eintrag“ mit Hinweis „n Dateien aus ‚Teilen‘ übernommen“; danach ist der Cache `garage-geteilt` leer.
- [ ] Teilen ohne Anmeldung: Eingang zeigt die Anmeldung, nach dem Anmelden sind die Dateien noch da (Inhaber, offen; im Code umgesetzt: der Cache wird erst nach erfolgreicher Anmeldung gelesen, auch ohne `?geteilt=`).

## Checkliste

- [x] `web/manifest.webmanifest`: `name` „GARAGE“, `short_name` „GARAGE“, `start_url` `/garage/eingang.html`, `scope` `/garage/`, `display` `standalone`, `background_color` `#ffffff`, `theme_color` `#111111`, `icons` 192 und 512 (PNG, `purpose: any maskable`), `share_target`: `{"action": "/garage/teilen", "method": "POST", "enctype": "multipart/form-data", "params": {"title": "title", "text": "text", "files": [{"name": "dateien", "accept": ["image/*", "application/pdf"]}]}}`.
- [x] Icons `web/icons/garage-192.png`, `web/icons/garage-512.png`: schwarzes Quadrat, weißes „G“ in Systemschrift fett, Sicherheitsrand 20 % (für maskable) — per PHP-GD-Skript im Scratchpad erzeugen, Skript nicht ins Repo.
- [x] `web/.htaccess`: `AddType application/manifest+json .webmanifest`; `Header set Service-Worker-Allowed "/garage/"` nur für `sw.js` (`<Files "sw.js">`), `Cache-Control: no-cache` für `sw.js`.
- [x] `web/sw.js`: `install` → `skipWaiting()`, `activate` → `clients.claim()`; `fetch`: nur `POST` auf Pfad `/garage/teilen` behandeln — `formData()`, alle `dateien` als `Response(file, {headers: {'Content-Type': file.type, 'X-Name': encodeURIComponent(file.name)}})` unter `/garage/geteilt/<n>` in Cache `garage-geteilt` legen (vorher leeren), dann `Response.redirect('/garage/eingang.html?geteilt=' + n, 303)`; alle anderen Anfragen nicht anfassen (kein `respondWith`).
- [x] Registrierung in `web/js/account.js`, `inbox.js`, `document.js`: `navigator.serviceWorker?.register('/garage/sw.js', {scope: '/garage/'})`, Fehler still ignorieren; `<link rel="manifest" href="/garage/manifest.webmanifest">` in allen drei Seiten (nicht in `einladung.html`/`zustimmung.html`).
- [x] `inbox.js`: bei `?geteilt=n` nach erfolgreicher Anmeldung die Einträge aus dem Cache lesen, zu `File` machen (Name aus `X-Name`), an `capture.js` übergeben, Cache leeren, Hinweis anzeigen, Parameter per `history.replaceState` entfernen.
- [x] `docs/code-map.md` (`web/`-Zeilen), `docs/conventions/frontend.md` (Service Worker nur fürs Teilen, kein Offline-Cache), `docs/knowledge/GAPS.md` (iOS: kein Teilen-Ziel).

## Report-Back

**Ergebnis:** `web/manifest.webmanifest`, `web/sw.js`, `web/js/share.js` (Registrierung + Cache lesen), Icons `web/icons/garage-192|512.png`, `.htaccess` (Manifest-Typ, `sw.js`-Header), Manifest-Link in `index.html`/`eingang.html`/`belege.html`, Übernahme in `inbox.js` mit Hinweiszeile `#shared-note`. Deployt, Rauchtest grün; Manifest und `sw.js` per curl auf Strato geprüft.

**Abweichung:** `inbox.js` liest den Cache bei jedem Start nach erfolgreicher Anmeldung, nicht nur bei `?geteilt=n` — sonst gingen die Dateien verloren, wenn die Anmeldung über `index.html` läuft und der Parameter weg ist. Der Parameter wird beim Start entfernt.

**Offen für den Inhaber (Android):** Web-App installieren, aus einer anderen App ein PDF und ein Foto an „GARAGE“ teilen, prüfen: Formular „Neuer Eintrag“ mit Hinweis, danach Cache leer; Teilen bei abgemeldeter App; DevTools „Installierbar“. Eingetragen in `docs/knowledge/GAPS.md`.
