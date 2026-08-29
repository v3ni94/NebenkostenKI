import { HvmKennlinieBand } from "@/components/HvmKennlinieBand";

export default function ObjektePage() {
  return (
    <main className="mx-auto max-w-5xl p-8">
      <HvmKennlinieBand />
      <h1 className="mt-6 text-xl font-semibold text-hvm-textschwarz">
        Objekte (Platzhalter)
      </h1>
      <p className="mt-2 text-sm text-hvm-anthrazit">
        Übersicht der verwalteten Objekte folgt in Phase 1.
      </p>
    </main>
  );
}
