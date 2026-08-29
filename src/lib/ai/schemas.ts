import { z } from "zod";

/**
 * Zod-Schemata für die strukturierte KI-Extraktion aus Belegen/Rechnungen
 * (siehe Spezifikation Abschnitt 8). Dienen als Zielformat für die Anthropic-
 * Extraktion sowie zur Laufzeitvalidierung der Modellantwort, bevor Daten in
 * CostItem/ExternalHeatingStatement übernommen werden.
 *
 * Platzhalter für Phase 1: Felder werden verfeinert, sobald reale Beleg-
 * Formate (Nebenkostenrechnungen, Techem/Ista-Abrechnungen) vorliegen.
 */

export const extractedCostItemSchema = z.object({
  description: z.string(),
  amountNetCent: z.number().int(),
  amountVatCent: z.number().int().default(0),
  suggestedCategory: z.string().optional(),
  confidence: z.number().min(0).max(1).optional(),
});
export type ExtractedCostItem = z.infer<typeof extractedCostItemSchema>;

export const extractedHeatingStatementSchema = z.object({
  provider: z.string(),
  periodStart: z.string(), // ISO-Datum, wird beim Import in DateTime konvertiert
  periodEnd: z.string(),
  totalCostCent: z.number().int(),
  rawFields: z.record(z.string(), z.unknown()).optional(),
});
export type ExtractedHeatingStatement = z.infer<typeof extractedHeatingStatementSchema>;
