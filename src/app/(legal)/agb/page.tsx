import { BetreiberAngaben, LegalBanner } from "../_LegalBanner";

export default function AgbPage() {
  return (
    <main className="mx-auto max-w-3xl p-8">
      <LegalBanner />
      <h1 className="text-xl font-semibold text-hvm-textschwarz">
        Allgemeine Geschäftsbedingungen
      </h1>
      <section className="mt-4 space-y-4 text-sm">
        <h2 className="font-semibold">Vertragspartner (Platzhalter)</h2>
        <BetreiberAngaben />
        <p className="text-hvm-anthrazit">
          Die AGB (Leistungsbeschreibung des Portals, Preise gemäß
          PRICE_BASE_NET_CENT / PRICE_PER_UNIT_STATEMENT_NET_CENT, Zahlungs-
          und Kündigungsbedingungen, Haftung) sind vor Livegang durch einen
          Rechtsanwalt zu erstellen bzw. zu prüfen.
        </p>
      </section>
    </main>
  );
}
