# 004 — Keine automatisierten Tests

**Status:** Akzeptiert (2026-11-16)

## Kontext

Laufzeit ist PHP ohne Composer auf Shared Hosting. Ein Test-Framework brächte die erste Entwicklungs-Abhängigkeit und eine zweite Werkzeugkette mit sich; DISPATCH fährt mit demselben Stack ohne Tests.

## Entscheidung

- Kein Test-Framework, keine Testdateien, kein Test-Schritt in der CI.
- Absicherung: strenge Typen (`declare(strict_types=1)`, typisierte Signaturen), PHP-Syntaxprüfung in CI und `deploy.cmd`, Rauchtest nach jedem Deploy, Handprüfung nach [`../conventions/dod.md`](../conventions/dod.md).

## Konsequenzen

- Sicherheitsrelevantes (Mandantentrennung, Scopes, OAuth, Sperre von `garage-app/`) braucht in der Handprüfung eine Liste der durchgespielten Fälle, einschließlich der abzuweisenden.
