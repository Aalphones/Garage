# 016 — Eingang: Reservierung mit Frist ohne Cron

**Status:** Akzeptiert (2026-11-17) — Meilenstein 3, Phase 3.

## Kontext

Menschen legen Fotos und PDFs im Browser in den Eingang (`inbox_items`); ein Agent arbeitet sie später ab. Damit zwei Agenten nicht denselben Eintrag erfassen und ein abgestürzter Agent keinen Eintrag für immer sperrt, braucht der Eintrag eine Reservierung mit Frist. Auf Strato läuft kein Cron.

## Entscheidung

- **`claim_inbox_item` setzt `claimed_until`** (Standard 180 Minuten, 10–720) und Status `in_arbeit`. Derselbe Anspruchsteller verlängert mit demselben Aufruf; ein fremder, noch gültiger Anspruch ist `invalid_value` („reserviert bis …“).
- **Die Frist wird nie von einem Job abgeräumt.** Der Status `in_arbeit` bleibt in der Datenbank stehen; ob er noch gilt, entscheidet die Abfrage: SQL liefert `claimed_until >= UTC_TIMESTAMP()` als `is_claim_live`, und `InboxService::effectiveStatus` macht aus `in_arbeit` mit abgelaufener Frist `neu`. Das ist die einzige Stelle, die so übersetzt; Listen, Reservieren und Freigeben fragen alle dort.
- **Die Zeit kommt vom Datenbankserver** (`UTC_TIMESTAMP()`), nie aus PHP, damit Server- und Datenbankuhr nicht auseinanderlaufen.
- **`release_inbox_item` nur mit gültiger eigener Reservierung.** Ist die Frist abgelaufen oder hat jemand anderes übernommen, antwortet es `invalid_value` („Reservierung abgelaufen …“); der Agent reserviert dann erneut.
- **Sperrreihenfolge:** erst das Fahrzeug, dann der Eintrag, damit Erfassen und Abschließen sich nicht gegenseitig blockieren.
- **Sortierung:** der Agent bekommt den ältesten freien Eintrag zuerst (nichts bleibt liegen), die Oberfläche den neuesten zuerst.

## Verworfen

- **Cron-Job, der Fristen zurücksetzt:** Strato bietet ihn nicht zuverlässig, und ein verpasster Lauf ließe Einträge hängen.
- **Ablauf beim nächsten Schreibzugriff:** Lesen würde dann schreiben; ein Eintrag mit abgelaufener Frist erschiene in der Liste bis dahin als reserviert.

## Konsequenzen

- Ein Eintrag mit abgelaufener Frist ist in jeder Sicht `neu`, obwohl die Spalte `in_arbeit` zeigt; wer die Tabelle direkt liest, muss `effectiveStatus` beachten.
- Der Klärfall-Ausgang (`klaerfall`) legt einen offenen Punkt der Art `nachfragen` an („Eingang #id: …“); gelöst wird er wie jeder andere nur von Menschen.
