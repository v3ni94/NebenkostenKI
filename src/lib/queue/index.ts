/**
 * DB-basierte Job-Queue (kein Redis-Zwang, siehe ARCHITECTURE.md).
 *
 * Vorgesehenes Muster: eine Tabelle "Job" (Payload als JSON, Status,
 * attempts, runAt, lockedAt) wird von einem separaten Worker-Prozess
 * (siehe docker-compose.yml, Service "worker") per Polling abgearbeitet.
 * Für Phase 1 ist dies nur als Konzept dokumentiert; das Job-Modell wird
 * ergänzt, sobald der erste asynchrone Anwendungsfall (z.B. PDF-Erzeugung
 * für einen kompletten Abrechnungslauf) ansteht.
 */
export {};
