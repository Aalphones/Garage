# GARAGE — Kontext

> **Your agents. Your cars.**

Ausführliches Fachkonzept: [`design/konzept-garage.md`](design/konzept-garage.md). Diese Datei und die ADRs unter [`decisions/`](decisions/) haben Vorrang, wo das Konzept abweicht.

## Ziel & Vision

GARAGE ist eine Fahrzeugakte für mehrere Nutzer: KI-Agenten (ChatGPT, Claude) füllen und lesen sie über MCP, Menschen sehen sie im Browser. Rechenbares ist strukturiert (Ereignisse, Positionen, Messwerte, Intervallregeln), jede Angabe trägt ihre Herkunft, und Fälligkeiten, Kilometerstand und Unterhalt werden berechnet statt abgeschrieben. Widersprüche überschreiben nichts, sie werden Klärfälle.

## Scope

- Mehrere Konten von Anfang an: Nutzer, Garagen (Haushalt oder Fuhrpark), Mitglieder mit Rolle, Einladung per Link.
- MCP-Server `/garage/mcp` mit OAuth 2.1 und CIMD wie DISPATCH, damit sich ChatGPT und Claude per Anmeldung verbinden; dazu feste Tokens je Nutzer für Claude Code und geplante Aufgaben. Scopes `garage:read`, `garage:write`, `garage:files`.
- Fahrzeugakte: Stammdaten, Zulassung, Ausstattung, Spezifikationen, Wartungsereignisse mit Positionen, Intervallregeln und Fälligkeiten, Messwerte, Reifen, Teile, Schäden, Rückrufe, Notizen, Klärfälle.
- Belege und Fotos auf zwei Wegen: im Chat (Upload-Ticket, Datei-Parameter, Widget) und über den Eingang der App, den ein Agent abarbeitet.
- Kosten und Verträge (Versicherung, Steuer, Club, Umlage auf aktive Autos), Tankbuch, Zubehör, Plaketten; Wertermittlung aus Ankerwerten, Vergleichsinseraten und Wertfaktoren.
- Web-Oberfläche hinter Login, mobil zuerst: HTML-Hülle mit Vanilla-JS-Modulen gegen `/garage/api`.
- iCal-Abo je Garage für Fälligkeiten; Akte als Markdown-Export und Druckansicht.
- Deploy-Skript für Strato, das nur in die eigenen Unterordner schreibt und danach nachprüft.

## Nicht-Ziele

- Hausrat, Technik, Sammlungen — kommen später in eine eigene App.
- Automatische Wertermittlung über DAT, Schwacke oder mobile.de-APIs; Inserate recherchiert ein Agent wie ein Mensch.
- Hintergrundprozesse, Server-Cron, Node auf dem Server, Composer-Abhängigkeiten.
- Offene Registrierung — Konten nur per Einladung.
- Agenten löschen, übergeben Fahrzeuge, verwalten Mitglieder oder lösen Klärfälle — das bleibt Menschen in der Oberfläche.
- Automatisierte Tests ([ADR-004](decisions/004-keine-automatisierten-tests.md)).

## Stack

| Layer | Choice |
|---|---|
| Sprache | PHP 8.5, `declare(strict_types=1)` — dieselbe Version wie DISPATCH auf demselben Strato-Paket |
| Framework | keins; eigener PSR-4-Autoloader (`Garage\` → `app/src/`) |
| Datenbank | MySQL bei Strato, eigene Datenbank, PDO, `utf8mb4`, InnoDB |
| Oberfläche | HTML-Hülle, Vanilla-JS als ES-Module, kein Build-Schritt, keine Bibliotheken |
| MCP und OAuth | aus DISPATCH kopiert und auf mehrere Konten umgebaut ([ADR-005](decisions/005-kern-aus-dispatch-kopiert.md)) |
| Deploy | `deploy.cmd` + portables WinSCP ([ADR-003](decisions/003-deploy-skript.md)) |
| CI | GitHub Actions: PHP-Syntaxprüfung |
| Lokale Entwicklung | `php -S localhost:8081 dev-router.php`, MySQL lokal |

Kein Composer: das Strato-Paket hat keins, und DISPATCH zeigt, dass JWT und OAuth mit PHP-Bordmitteln (`hash_hmac`) gehen.

## Constraints

- **Gemeinsame Domain:** `quantum-canvas.de` liefert `www/` aus; dort laufen DISPATCH (Wurzel), CardMaker und CamScanner. GARAGE belegt nur `/garage/` und `/garage-app/` ([ADR-001](decisions/001-pfade-auf-gemeinsamer-domain.md)).
- **Wurzel-`.htaccess` gehört DISPATCH.** Rewrites für die OAuth-Discovery von GARAGE und die Ausnahmen für `/garage/` stehen dort.
- **Ein SFTP-Zugang, der nur `www/` sieht.** Code und Daten liegen gesperrt im Webroot ([ADR-002](decisions/002-code-im-webroot-gesperrt.md)).
- **Kein SSH, kein Cron:** Migrationen laufen über einen tokengeschützten Endpunkt; Erinnerungen über ein iCal-Abo.
- **Strato-Grenzen** (von DISPATCH gemessen): 128 MB je Upload, 512 MB Speicher je Aufruf.
- **Private Daten:** Fahrzeugdaten, Belege und Fotos nur nach Anmeldung; Belege nur über signierte Links mit kurzer Laufzeit.

## Meilensteine

Grob; Detail-Phasen macht der jeweilige Plan. Jeder Meilenstein endet mit einer Handprüfung auf Strato.

0. **Gerüst und Deploy (erledigt beim Aufsetzen):** Brücke `/garage/`, gesperrter Code-Ordner, Health- und Deploy-Endpunkt, Migrator, `deploy.cmd` mit Rauchtest; in DISPATCH die Ausnahmen der Wurzel-`.htaccess`, die Discovery-Umleitung und die Nachbarprüfung.
1. **Konten, MCP und OAuth (erledigt 2026-11-17, Server-Ablauf auf Strato per curl belegt; die echte Verbindung aus claude.ai und ChatGPT steht aus, siehe `knowledge/GAPS.md`):** `users`, `garages`, `garage_members`, `invitations`, `tokens`, OAuth-Tabellen; Passwort-Login, Zustimmungsseite mit Anmeldung als Nutzer, Token mit `user_id`; MCP-Schicht aus DISPATCH; Tools `list_vehicles`, `get_vehicle`, `create_vehicle`, `set_vehicle_facts`.
2. **Fahrzeugakte (erledigt 2026-11-17, 28 Tools, Server-Ablauf auf Strato per curl und im Browser belegt; offen: die echte Verbindung aus claude.ai/ChatGPT aus Meilenstein 1, Klärfall-Seite nur als Platzhalter bis Meilenstein 4, Löschen und Statuswechsel durch Menschen bis Meilenstein 4, Belege bis Meilenstein 3, siehe `knowledge/GAPS.md`):** Fahrzeug, Zulassung, Ausstattung, Spezifikationen, Ereignisse mit Positionen, Kilometerstände, Wartungsaufgaben und Regeln mit Fälligkeitsrechnung, Messwerte, Reifen, Teile, Klärfälle, Notizen, Audit-Log; `get_dossier`.
3. **Belege und Fotos:** Dokumente, Anhänge, Upload-Tickets, Eingang; Weg A (Chat) und Weg B (Eingang) an echten Clients verproben.
4. **Oberfläche:** Gestaltung gemeinsam in eigener Session (Design-Tokens, Komponenten, Entwurf der Hauptansichten), dann Garage, Cockpit, Scheckheft, Wartungsplan, Belege, Klärfälle.
5. **Kosten, Verträge, Wert:** Verträge mit Beitragsperioden, Umlage, Tankbuch, Ausgaben, Zubehör, Plaketten, Bewertungen und Vergleichsinserate, Kosten- und Wert-Ansicht, iCal-Feed.
6. **Einladungen in der Oberfläche, Migration der beiden bestehenden Akten.**
## Offene Fragen

Aus dem Konzept (→ „Offene Entscheidungen"), Empfehlung jeweils dort:

- Belege und Datenschutz bei fremden Nutzern: Impressum, Datenschutzerklärung, Löschkonzept; eigene Domain, sobald Fremde Konten bekommen.
- PDFs für Agenten: Original plus erkannter Text (empfohlen) oder Seitenbilder.
- Belegweg auf dem Handy: welcher Weg Standard wird, entscheidet die Verprobung in Meilenstein 3.
- Gemeinsamer Kern mit DISPATCH nach Meilenstein 3 ([ADR-005](decisions/005-kern-aus-dispatch-kopiert.md)).
- Die Meilenstein-Grafik des Konzepts (M0–M7) liegt nur als eingebettete Grafik vor und fehlt im Markdown; die Liste oben ist daraus abgeleitet.
