-- Meilenstein 1, Abschluss: Testkonten der Handpruefungen sperren, ihre Tokens widerrufen.
-- Betroffen sind nur Adressen der Form test-...@example.com (example.com ist reserviert, dort gibt es keine echten Konten).
-- Nichts wird geloescht: Fahrzeuge, Fakten und Audit-Eintraege der Testkonten bleiben stehen.
UPDATE tokens SET revoked = 1 WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'test-%@example.com');

UPDATE users SET status = 'gesperrt' WHERE email LIKE 'test-%@example.com';
