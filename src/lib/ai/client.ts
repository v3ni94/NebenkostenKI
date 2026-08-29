// Platzhalter für den Anthropic-Client (Extraktion von Belegdaten / Analyse).
//
// WICHTIG (Compliance, siehe Spezifikation Abschnitt 8):
// - Vor produktivem Einsatz muss ein Auftragsverarbeitungsvertrag (AVV/DPA) mit
//   Anthropic abgeschlossen sein.
// - Es ist zu prüfen und zu dokumentieren, ob/wie eine Zero-Data-Retention-
//   Vereinbarung greift, bevor personenbezogene Daten (Mieter-/Eigentümerdaten,
//   Belege) an die API übermittelt werden.
// - Modellauswahl erfolgt ausschließlich über ENV (ANTHROPIC_MODEL_EXTRACT,
//   ANTHROPIC_MODEL_ANALYZE), niemals hartkodiert.
// - Nutzung ist je Nutzer zu limitieren (AI_DAILY_LIMIT_PER_USER) und in
//   AiUsageLog zu protokollieren (Kosten-/Missbrauchskontrolle).
//
// Implementierung folgt in einer späteren Phase, sobald der Extraktions-Flow
// (Beleg-Upload -> Kostenposition) ansteht.

import Anthropic from "@anthropic-ai/sdk";

let cachedClient: Anthropic | null = null;

/**
 * Liefert einen konfigurierten Anthropic-Client. Wirft, wenn kein API-Key
 * gesetzt ist, damit Fehlkonfiguration früh auffällt statt in der KI-Anfrage
 * selbst.
 */
export function getAnthropicClient(): Anthropic {
  if (cachedClient) return cachedClient;
  const apiKey = process.env.ANTHROPIC_API_KEY;
  if (!apiKey) {
    throw new Error("ANTHROPIC_API_KEY ist nicht gesetzt (.env).");
  }
  cachedClient = new Anthropic({ apiKey });
  return cachedClient;
}
