/**
 * Berechnungs-Engine für die Nebenkostenumlage (Phase 1, Kernstück mit
 * Unit-Tests). Dies ist ein Platzhalter mit einer ersten, bewusst einfachen
 * Funktion (Umlage nach Anteil, z.B. Wohnfläche oder Miteigentumsanteile),
 * um die Teststruktur (tests/) und das Rundungsverhalten (Cent-genau, kein
 * Betrag geht durch Rundung verloren) von Anfang an korrekt zu verankern.
 *
 * Die vollständige Engine (alle AllocationMethod-Varianten, taggenaue
 * Zeitanteile über TenancyOccupancy, Verbrauchsdaten) folgt in Phase 1 als
 * eigener Arbeitsschritt.
 */

export interface ShareInput {
  /** Eindeutiger Bezeichner, z.B. unitId. */
  id: string;
  /** Anteilsgröße, z.B. Wohnfläche in m² oder Miteigentumsanteile. */
  share: number;
}

/**
 * Verteilt einen Gesamtbetrag (in Cent) proportional zu den übergebenen
 * Anteilen. Rundungsdifferenzen werden der Einheit mit dem größten Anteil
 * zugeschlagen, damit die Summe der Einzelbeträge exakt dem Gesamtbetrag
 * entspricht (kein Cent geht verloren oder wird doppelt vergeben).
 */
export function allocateByShare(
  totalAmountCent: number,
  shares: ShareInput[],
): Record<string, number> {
  if (shares.length === 0) return {};
  const totalShare = shares.reduce((sum, s) => sum + s.share, 0);
  if (totalShare <= 0) {
    throw new Error("Summe der Anteile muss größer als 0 sein.");
  }

  const result: Record<string, number> = {};
  let allocated = 0;
  let largestId = shares[0].id;
  let largestShare = -Infinity;

  for (const { id, share } of shares) {
    const amount = Math.floor((totalAmountCent * share) / totalShare);
    result[id] = amount;
    allocated += amount;
    if (share > largestShare) {
      largestShare = share;
      largestId = id;
    }
  }

  const remainder = totalAmountCent - allocated;
  result[largestId] += remainder;

  return result;
}
