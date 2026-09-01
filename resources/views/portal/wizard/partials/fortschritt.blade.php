{{--
    Fortschrittsleiste des gefuehrten Ablaufs.

    Die Statuskategorie steht immer als Text daneben. Farbe ist nur
    zusaetzliche Information. Die Schritte 1 bis 6 werden verlinkt, sie liegen
    in anderen Bausteinen.
--}}
@props([
    'fortschritt' => [],
    'billingRun' => null,
    'wiedereinstieg' => null,
])

<nav aria-label="Fortschritt der Abrechnung" class="rounded-lg border border-hvm-hellgrau bg-white p-4">
    <p class="text-sm font-semibold text-hvm-anthrazit">Ihr Fortschritt</p>

    @if ($wiedereinstieg !== null)
        <p class="mt-1 text-sm text-hvm-textschwarz">{{ $wiedereinstieg }}</p>
    @endif

    <ol class="mt-3 space-y-2">
        @foreach ($fortschritt as $station)
            <li class="flex flex-wrap items-center gap-2 text-sm">
                <span class="w-6 shrink-0 text-right font-semibold">{{ $station->nummer() }}.</span>

                @if ($station->erreichbar && $billingRun !== null)
                    <a class="underline underline-offset-2"
                       href="{{ route($station->step->routeName(), ['billingRun' => $billingRun->getKey()]) }}"
                       @if ($station->aktuell) aria-current="step" @endif>
                        {{ $station->label() }}
                    </a>
                @else
                    <span class="text-hvm-anthrazit">{{ $station->label() }}</span>
                @endif

                <x-hvm.badge :variant="$station->variante()">{{ $station->kategorie }}</x-hvm.badge>
            </li>
        @endforeach
    </ol>

    <p class="mt-3 text-sm text-hvm-anthrazit">
        Jeder Schritt speichert sofort. Sie können jederzeit unterbrechen und später ohne Datenverlust fortfahren.
    </p>
</nav>
