import { HvmKennlinieBand } from "@/components/HvmKennlinieBand";

export default function Home() {
  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-8 bg-white px-6 py-24 text-hvm-textschwarz">
      <div className="w-full max-w-xl">
        <HvmKennlinieBand />
        <h1 className="mt-8 text-2xl font-semibold">
          Nebenkostenabrechnungs-Portal
        </h1>
        <p className="mt-2 text-sm text-hvm-anthrazit">
          Hausverwaltung Müller GmbH. Diese Projektgrundlage befindet sich im
          Aufbau (Phase 1, Fundament). Siehe ARCHITECTURE.md im Repository für
          Details.
        </p>
      </div>
    </main>
  );
}
