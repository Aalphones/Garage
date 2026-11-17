# Phase 5 — Teilen-Ziel Android

**Rating:** standard. Modell: `sonnet`.

## Kontext — vor dem ersten Edit lesen

- Plan-README: [`README.md`](README.md) — E14.
- Entwurf: [`artifacts/entwurf-belege.html#teilen`](artifacts/entwurf-belege.html) (Bildschirm „Geteilt (Android)“: Hinweis „2 Dateien aus ‚Teilen‘ übernommen“, darunter das Formular „Neuer Eintrag“ mit den geteilten Dateien).
- Report-Back Phase 4; `web/eingang.html`, `web/js/inbox.js`, `web/js/capture.js` (Funktion, die eine Liste von `File` in die Seitenliste übernimmt), `web/.htaccess`, `docs/conventions/frontend.md`.
- Web Share Target: https://developer.chrome.com/docs/capabilities/web-apis/web-share-target (POST + `multipart/form-data`, `params.files`, Service Worker fängt den POST ab). iOS unterstützt es nicht.
- Vault-Fehlerklassen: keine einschlägig.

## Abnahmekriterien

- [ ] `https://quantum-canvas.de/garage/manifest.webmanifest` liefert `application/manifest+json`; Chrome-DevTools → Application → Manifest zeigt keine Fehler, „Installierbar“.
- [ ] `https://quantum-canvas.de/garage/sw.js` ist registriert mit Scope `/garage/`; die Seiten laden weiter frisch vom Server (kein Offline-Cache der Oberfläche).
- [ ] Android (Inhaber): installierte Web-App erscheint in „Teilen“ als „GARAGE“; ein geteiltes PDF und ein Foto landen in `eingang.html` im Formular „Neuer Eintrag“ mit Hinweis „n Dateien aus ‚Teilen‘ übernommen“; danach ist der Cache `garage-geteilt` leer.
- [ ] Teilen ohne Anmeldung: Eingang zeigt die Anmeldung, nach dem Anmelden sind die Dateien noch da.

## Checkliste

- [ ] `web/manifest.webmanifest`: `name` „GARAGE“, `short_name` „GARAGE“, `start_url` `/garage/eingang.html`, `scope` `/garage/`, `display` `standalone`, `background_color` `#ffffff`, `theme_color` `#111111`, `icons` 192 und 512 (PNG, `purpose: any maskable`), `share_target`: `{"action": "/garage/teilen", "method": "POST", "enctype": "multipart/form-data", "params": {"title": "title", "text": "text", "files": [{"name": "dateien", "accept": ["image/*", "application/pdf"]}]}}`.
- [ ] Icons `web/icons/garage-192.png`, `web/icons/garage-512.png`: schwarzes Quadrat, weißes „G“ in Systemschrift fett, Sicherheitsrand 20 % (für maskable) — per PHP-GD-Skript im Scratchpad erzeugen, Skript nicht ins Repo.
- [ ] `web/.htaccess`: `AddType application/manifest+json .webmanifest`; `Header set Service-Worker-Allowed "/garage/"` nur für `sw.js` (`<Files "sw.js">`), `Cache-Control: no-cache` für `sw.js`.
- [ ] `web/sw.js`: `install` → `skipWaiting()`, `activate` → `clients.claim()`; `fetch`: nur `POST` auf Pfad `/garage/teilen` behandeln — `formData()`, alle `dateien` als `Response(file, {headers: {'Content-Type': file.type, 'X-Name': encodeURIComponent(file.name)}})` unter `/garage/geteilt/<n>` in Cache `garage-geteilt` legen (vorher leeren), dann `Response.redirect('/garage/eingang.html?geteilt=' + n, 303)`; alle anderen Anfragen nicht anfassen (kein `respondWith`).
- [ ] Registrierung in `web/js/account.js`, `inbox.js`, `document.js`: `navigator.serviceWorker?.register('/garage/sw.js', {scope: '/garage/'})`, Fehler still ignorieren; `<link rel="manifest" href="/garage/manifest.webmanifest">` in allen drei Seiten (nicht in `einladung.html`/`zustimmung.html`).
- [ ] `inbox.js`: bei `?geteilt=n` nach erfolgreicher Anmeldung die Einträge aus dem Cache lesen, zu `File` machen (Name aus `X-Name`), an `capture.js` übergeben, Cache leeren, Hinweis anzeigen, Parameter per `history.replaceState` entfernen.
- [ ] `docs/code-map.md` (`web/`-Zeilen), `docs/conventions/frontend.md` (Service Worker nur fürs Teilen, kein Offline-Cache), `docs/knowledge/GAPS.md` (iOS: kein Teilen-Ziel).

## Report-Back
