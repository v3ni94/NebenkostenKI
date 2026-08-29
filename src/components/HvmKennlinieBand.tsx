/**
 * Beispielkomponente für die HVM-CI-Design-Tokens: das "Kennlinie"-Band, wie
 * es im HVM-Briefbogen als grafisches Element verwendet wird (siehe Skill
 * hvm-ci). Dient hier als Referenz, wie die Tailwind-Tokens (bg-hvm-*) im
 * Portal-UI eingesetzt werden; kein finales Layout.
 */
export function HvmKennlinieBand() {
  return (
    <div
      role="presentation"
      aria-hidden="true"
      className="flex h-2 w-full overflow-hidden rounded-full"
    >
      <div className="w-1/6 bg-hvm-orange" />
      <div className="w-1/6 bg-hvm-anthrazit" />
      <div className="w-1/6 bg-hvm-mittelgrau" />
      <div className="w-1/6 bg-hvm-hellgrau" />
      <div className="w-1/6 bg-hvm-umrissgrau" />
      <div className="w-1/6 bg-hvm-textschwarz" />
    </div>
  );
}
