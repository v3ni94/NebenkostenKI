/**
 * PayPal-Integration für Portalgebühren (siehe .env: PAYPAL_CLIENT_ID,
 * PAYPAL_CLIENT_SECRET, PAYPAL_WEBHOOK_ID). Platzhalter für Phase 1.
 *
 * Paket: @paypal/paypal-server-sdk. Client-Initialisierung folgt, sobald der
 * Zahlungs-Flow implementiert wird.
 */
export function getPaypalConfig() {
  const clientId = process.env.PAYPAL_CLIENT_ID;
  const clientSecret = process.env.PAYPAL_CLIENT_SECRET;
  if (!clientId || !clientSecret) {
    throw new Error("PAYPAL_CLIENT_ID/PAYPAL_CLIENT_SECRET sind nicht gesetzt (.env).");
  }
  return { clientId, clientSecret };
}
