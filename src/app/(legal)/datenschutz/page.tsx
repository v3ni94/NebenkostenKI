import { BetreiberAngaben, LegalBanner } from "../_LegalBanner";

export default function DatenschutzPage() {
  return (
    <main className="mx-auto max-w-3xl p-8">
      <LegalBanner />
      <h1 className="text-xl font-semibold text-hvm-textschwarz">
        Datenschutzerklärung
      </h1>
      <section className="mt-4 space-y-4 text-sm">
        <h2 className="font-semibold">Verantwortlicher (Platzhalter)</h2>
        <BetreiberAngaben />
        <p className="text-hvm-anthrazit">
          Der vollständige, DSGVO-konforme Datenschutztext (Zwecke und
          Rechtsgrundlagen der Verarbeitung, Auftragsverarbeiter wie
          KI-/Zahlungs-/Storage-Dienstleister, Betroffenenrechte,
          Speicherdauern, ggf. Drittlandtransfer) ist vor Livegang durch
          einen Rechtsanwalt zu erstellen bzw. zu prüfen. Kein Inhalt dieser
          Seite darf ungeprüft übernommen werden.
        </p>
      </section>
    </main>
  );
}
