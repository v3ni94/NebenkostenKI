import Stripe from "stripe";

/**
 * Stripe-Integration für Portalgebühren (siehe .env: STRIPE_SECRET_KEY,
 * STRIPE_WEBHOOK_SECRET). Platzhalter für Phase 1.
 */
let cachedClient: Stripe | null = null;

export function getStripeClient(): Stripe {
  if (cachedClient) return cachedClient;
  const secretKey = process.env.STRIPE_SECRET_KEY;
  if (!secretKey) {
    throw new Error("STRIPE_SECRET_KEY ist nicht gesetzt (.env).");
  }
  cachedClient = new Stripe(secretKey);
  return cachedClient;
}
