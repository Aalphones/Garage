# Phase 4 — Seiten Einladung (bestehendes Konto) und Passwort

Rating: standard · Modell: sonnet

## Kontext

- `README.md` dieses Plans → Kontrakt (`invitations/join`, `password-reset/preview|accept`).
- Muster: `web/einladung.html` + `web/js/invitation.js` (Token aus dem Fragment, `history.replaceState`, `preview`, Formular), `web/js/login.js` (Anmeldeformular, geteilt), `web/js/api.js` (`post`, `restore`), `web/.htaccess` (CSP), `web/sw.js` (Cache-Liste, falls Seiten dort stehen).
- Fehlerklassen geprüft: keine einschlägig.

## Akzeptanzkriterien

1. `einladung.html` zeigt nach der Vorschau zwei Wege: „Neues Konto anlegen“ (bestehendes Formular) und „Ich habe schon ein Konto“. Ist schon eine Sitzung da (`restore` erfolgreich), steht nur „Beitreten als <Name>“ mit einem Knopf.
2. „Ich habe schon ein Konto“ zeigt das geteilte Anmeldeformular; nach der Anmeldung ruft die Seite `invitations/join` und leitet nach `/garage/garage.html` weiter. `already_member` erscheint als Meldung mit Link zur Garage.
3. Neue Seite `web/passwort.html` + `web/js/password.js`: Token aus dem Fragment (wie Einladung), Vorschau „Neues Passwort für <Name>“, zwei Felder (Passwort, Wiederholung, `autocomplete="new-password"`), Hinweis „mindestens 12 Zeichen“; nach Erfolg Weiterleitung nach `/garage/`.
4. Ohne Token oder bei 404/410 zeigt die Seite den Satz aus der API und einen Link zur Anmeldung.
5. Beide Seiten 360 px ohne waagerechtes Scrollen, hell und dunkel, Thema per `theme.js` im `<head>` wie `einladung.html`.

## Checkliste

- [ ] `einladung.html`/`invitation.js` erweitern.
- [ ] `passwort.html`, `password.js` neu.
- [ ] Anmeldeseite `index.html`: Satz unter dem Formular „Passwort vergessen? Bitte den Inhaber deiner Garage um einen Link.“
- [ ] `docs/code-map.md` (Seite und Modul), `docs/routes.md` (Seite, falls Seiten dort geführt werden).
- [ ] `node --check` auf beide Module.

## Report-Back
