import { NextResponse } from "next/server";

/**
 * PayPal-Webhook-Endpunkt (Platzhalter). Verifizierung gegen
 * PAYPAL_WEBHOOK_ID und Event-Verarbeitung folgen mit der
 * Zahlungs-Integration.
 */
export async function POST() {
  return NextResponse.json(
    { error: "PayPal-Webhook ist noch nicht implementiert." },
    { status: 501 },
  );
}
