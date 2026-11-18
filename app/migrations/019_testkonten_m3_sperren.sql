-- Meilenstein 3, Abschluss: Testkonten der Pruefungen sperren, ihre Tokens widerrufen.
-- Betroffen sind nur Adressen der Form test-m3-...@example.com (Phase 7) und ...@example.invalid (Phase 1 bis 6 nutzten diese Form);
-- example.com und .invalid sind reserviert, dort gibt es keine echten Konten.
-- Nichts wird geloescht: Fahrzeuge, Akten, Dokumente, Eingang und Audit-Eintraege der Testkonten bleiben stehen.
UPDATE tokens SET revoked = 1 WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'test-m3-%@example.com' OR email LIKE '%@example.invalid');

UPDATE users SET status = 'gesperrt' WHERE email LIKE 'test-m3-%@example.com' OR email LIKE '%@example.invalid';
