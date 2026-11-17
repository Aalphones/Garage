-- Meilenstein 2, Abschluss: Testkonten der Pruefungen sperren, ihre Tokens widerrufen.
-- Betroffen sind nur Adressen der Form test-m2-...@example.com und p2r...@example.com (Phase 2 nutzte diese Form);
-- example.com ist reserviert, dort gibt es keine echten Konten.
-- Nichts wird geloescht: Fahrzeuge, Akten, Klaerfaelle und Audit-Eintraege der Testkonten bleiben stehen.
UPDATE tokens SET revoked = 1 WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'test-m2-%@example.com' OR email LIKE 'p2r%@example.com');

UPDATE users SET status = 'gesperrt' WHERE email LIKE 'test-m2-%@example.com' OR email LIKE 'p2r%@example.com';
