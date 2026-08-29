export function LegalBanner() {
  return (
    <div className="mb-6 rounded border-2 border-hvm-orange bg-hvm-orange/10 p-4 text-sm font-semibold text-hvm-textschwarz">
      VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN. Dieser Text ist ein
      strukturelles Platzhalter-Gerüst ohne geprüften Rechtsinhalt.
    </div>
  );
}

export function BetreiberAngaben() {
  return (
    <address className="not-italic text-sm text-hvm-anthrazit">
      Hausverwaltung Müller GmbH
      <br />
      Rheinpromenade 13
      <br />
      40789 Monheim am Rhein
      <br />
      Amtsgericht Düsseldorf, HRB 104762
      <br />
      Geschäftsführer: Timo Müller
      <br />
      www.muellerhv.de
    </address>
  );
}
