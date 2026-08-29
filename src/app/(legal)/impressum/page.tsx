import { BetreiberAngaben, LegalBanner } from "../_LegalBanner";

export default function ImpressumPage() {
  return (
    <main className="mx-auto max-w-3xl p-8">
      <LegalBanner />
      <h1 className="text-xl font-semibold text-hvm-textschwarz">Impressum</h1>
      <section className="mt-4 space-y-4 text-sm">
        <h2 className="font-semibold">Angaben gemäß § 5 TMG (Platzhalter, zu ergänzen/prüfen)</h2>
        <BetreiberAngaben />
        <p className="text-hvm-anthrazit">
          Weitere Pflichtangaben (Kontaktdaten, USt-IdNr., ggf. Aufsichtsbehörde,
          Berufsbezeichnung) sind vor Livegang zu ergänzen und rechtlich zu prüfen.
        </p>
      </section>
    </main>
  );
}
