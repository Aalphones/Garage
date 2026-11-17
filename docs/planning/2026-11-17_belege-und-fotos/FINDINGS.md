# Findings — Meilenstein 3

Getaggte Erkenntnisse während der Umsetzung. Format: `- [ ] → Phase N: <Erkenntnis>` (abgehakt, sobald in der Ziel-Phase berücksichtigt).

- [ ] → Phase 4: Der Browser muss PDF-Seitenbilder als `pages[]` senden (höchstens 20 Seiten zu je 4 MB, JPEG); längere PDFs vorher abfangen. Server-Grenze: `max_file_uploads` steht über `web/.user.ini` auf 40.
- [ ] → Phase 4: `doc_type` eines Eingangs-Dokuments setzt nur der Mensch per `PATCH /garage/api/documents/{id}`; kein Tool ändert ihn.
- [ ] → Phase 4: `GET /garage/api/inbox` zeigt `in_arbeit` mit abgelaufener Frist bereits als `neu` (ADR-016); die Oberfläche übernimmt den Status 1:1 und rechnet nichts selbst.
- [ ] → Phase 7: Testkonten und Garagen „M3P3 Garage A/B“ auf Strato sperren oder löschen; CSP-`sandbox` (aus Phase 1) bleibt offen.
