import { BetreiberAngaben, LegalBanner } from "../_LegalBanner";

export default function WiderrufPage() {
  return (
    <main className="mx-auto max-w-3xl p-8">
      <LegalBanner />
      <h1 className="text-xl font-semibold text-hvm-textschwarz">
        Widerrufsbelehrung
      </h1>
      <section className="mt-4 space-y-4 text-sm">
        <h2 className="font-semibold">Unternehmer (Platzhalter)</h2>
        <BetreiberAngaben />
        <p className="text-hvm-anthrazit">
          Die Widerrufsbelehrung inkl. Muster-Widerrufsformular ist vor
          Livegang durch einen Rechtsanwalt zu erstellen bzw. zu prüfen
          (insbesondere Anwendbarkeit gegenüber B2B-/B2C-Nutzern des Portals).
        </p>
      </section>
    </main>
  );
}
