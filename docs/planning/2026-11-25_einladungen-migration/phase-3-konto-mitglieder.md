# Phase 3 — Konto: Abschnitt „Mitglieder“

Rating: standard · Modell: sonnet

## Kontext

- `README.md` dieses Plans → Kontrakt.
- `docs/conventions/frontend.md`, ADR-017 (Dark Botanical), Breakpoints und Prüfbreiten aus `docs/archive/2026-11/2026-11-18_oberflaeche/README.md` → „Breakpoints und Handy“.
- Muster: `web/index.html` (Abschnitt „Feste Zugänge“: Liste `plain-list`, Formular, `#token-reveal` mit Kopieren), `web/js/token.js` (laden, rendern, widerrufen mit `confirm`, Reveal), `web/js/account.js` (`renderGarages`, `Promise.all` beim Laden), `web/js/sheet.js` (Blatt mit Fokusfalle), `web/js/dropdown.js`, `web/js/dom.js`.
- Fehlerklassen geprüft: keine einschlägig.

## Akzeptanzkriterien

1. Neuer Abschnitt `<h3>Mitglieder</h3>` auf `index.html` direkt nach „Garagen“: je Garage eine Gruppe mit Garagenname als `<h4>`, darunter die Mitglieder als `plain-list` (Name, E-Mail klein, Rolle als Wort, „seit“-Datum, „du“ beim eigenen Eintrag).
2. Nur bei `can_manage`: je fremdem Mitglied die Knöpfe „Rolle ändern“ (Blatt mit Auswahl `inhaber`/`mitglied`/`lesend`), „Passwort-Link“ und „Entfernen“ (`confirm` mit Name); beim eigenen Eintrag stattdessen „Garage verlassen“ für alle Rollen.
3. Nur bei `can_manage`: Knopf „Einladen“ öffnet ein Blatt mit Rolle (Standard `mitglied`), Notiz/E-Mail (optional), Gültigkeit in Tagen (Standard 7); nach dem Anlegen zeigt ein Reveal wie `#token-reveal` den Link mit „Kopieren“ und, wenn `navigator.share` existiert, „Teilen“ (Titel „Einladung in die Garage …“).
4. Offene Einladungen stehen unter den Mitgliedern (Rolle, Notiz, „gültig bis“) mit „Widerrufen“.
5. Der Passwort-Link erscheint im selben Reveal-Muster mit dem Satz „Gilt 24 Stunden. Schick ihn nur an diese Person.“
6. Jede Rolle hat eine dezente Erklärung (?-Symbol mit Text): Inhaber = verwaltet Mitglieder und löscht; Mitglied = liest und schreibt; Lesend = liest nur. Die Gültigkeit hat einen Hinweistext unter dem Feld.
7. Fehler (`last_owner`, `forbidden`) erscheinen als Meldung im Abschnitt mit Fokus (`showError`), Text aus der API.
8. 360 px ohne waagerechtes Scrollen, hell und dunkel; Knöpfe einer Zeile brechen unter den Namen um.

## Checkliste

- [x] Markup in `web/index.html` (Container `#member-groups`, Fehlerzeile, Reveal `#member-reveal`).
- [x] Neues Modul `web/js/member.js` mit `setupMembers()` → `{ load(garages) }` (Muster `token.js`), Blätter über `sheet.js`.
- [x] `account.js`: `setupMembers` einbinden, in `Promise.all` laden.
- [x] Stil nur über bestehende Klassen und Tokens aus `web/css/tokens.css`; neue Regeln, falls nötig, in die vorhandene Konto-Stilstelle (nicht neue Farbwerte).
- [x] `docs/code-map.md`: `member.js` in der `js/`-Zeile.
- [x] `node --check web/js/member.js web/js/account.js`.

## Report-Back

Fertig (2026-11-26). `node --check` grün. Darstellung mit Attrappen-API in Edge bei 360 px geprüft (dunkel und hell, kein waagerechtes Scrollen); Blätter, Link-Anzeige, Teilen und die echten Routen sind nicht durchgeklickt.
