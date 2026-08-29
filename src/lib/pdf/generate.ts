/**
 * PDF-Erzeugung für Einzelabrechnungen und Rechnungen.
 *
 * Architekturentscheidung (siehe ARCHITECTURE.md): Layout-Rendering per
 * Playwright/Chromium (HTML/CSS -> PDF, hohe Gestaltungstreue zum HVM-CI,
 * gleiche Templates wie im Web-UI), Nachbearbeitung (Metadaten, Zusammenführen,
 * Signaturfelder etc.) per pdf-lib. Alternative @react-pdf/renderer wurde
 * geprüft und in ARCHITECTURE.md begründet zurückgestellt.
 *
 * Platzhalter für Phase 1.
 */
export async function renderUnitStatementPdf(): Promise<Buffer> {
  throw new Error("renderUnitStatementPdf ist noch nicht implementiert.");
}
