import { NextResponse } from "next/server";

/**
 * Stripe-Webhook-Endpunkt (Platzhalter). Signaturprüfung gegen
 * STRIPE_WEBHOOK_SECRET und Event-Verarbeitung folgen mit der
 * Zahlungs-Integration.
 */
export async function POST() {
  return NextResponse.json(
    { error: "Stripe-Webhook ist noch nicht implementiert." },
    { status: 501 },
  );
}
