{{--
    Schrittanzeige des gefuehrten Ablaufs (Wizard), Uebernahme aus Konzept B.

    Ein Segment je Schritt: erledigt und aktuell in Orange, offen in Canvas
    deep. Der Zustand steht zusaetzlich im Text ("Erledigt:", "Aktuell:"),
    Farbe ist nur zusaetzliche Information. Erreichbare Schritte sind Links.
    Darunter eine Zeile "Schritt X von N: Titel".

    Props:
      steps    Liste von Schritten (Pflicht). Je Schritt ein Array mit
                 label   Kurztitel (Pflicht)
                 state   done | current | open (Standard open)
                 href    Link, wenn der Schritt erreichbar ist (optional)
                 note    kurzer Zusatz, z. B. Statuskategorie (optional)
      label    aria-label der Navigation, Standard "Ihr Fortschritt"
      compact  true zeigt nur die Segmente und die Zeile "Schritt X von N",
               ohne die Beschriftung unter jedem Segment (fuer schmale
               Karten oder viele Schritte)

    Slot: Hinweistext unter der Anzeige (z. B. Wiedereinstieg).
--}}
@props([
    'steps',
    'label' => 'Ihr Fortschritt',
    'compact' => false,
])

@php
    $anzahl = count($steps);
    $aktuell = 0;
    foreach (array_values($steps) as $i => $schritt) {
        if (($schritt['state'] ?? 'open') === 'current') {
            $aktuell = $i + 1;
        }
    }
    $aktuellerTitel = $aktuell > 0 ? (array_values($steps)[$aktuell - 1]['label'] ?? '') : '';

    // Spaltenklassen als Literale, damit Tailwind sie beim Build findet
    // (keine Inline-Styles noetig).
    $spalten = [
        1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3', 4 => 'grid-cols-4',
        5 => 'grid-cols-5', 6 => 'grid-cols-6', 7 => 'grid-cols-7', 8 => 'grid-cols-8',
        9 => 'grid-cols-9', 10 => 'grid-cols-10', 11 => 'grid-cols-11', 12 => 'grid-cols-12',
    ];
    $spaltenKlasse = $spalten[min(max($anzahl, 1), 12)];
@endphp

<nav {{ $attributes->class('rounded-2xl border border-hvm-linie bg-white p-5 sm:p-6 [.hvm-dark_&]:border-hvm-graphit-soft [.hvm-dark_&]:bg-hvm-graphit-soft/40') }} aria-label="{{ $label }}">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase [.hvm-dark_&]:text-hvm-hellgrau">{{ $label }}</p>
        @if ($aktuell > 0)
            <p class="text-sm text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">
                Schritt <span class="font-semibold text-hvm-textschwarz tabular [.hvm-dark_&]:text-white">{{ $aktuell }}</span> von {{ $anzahl }}@if ($aktuellerTitel !== ''): <span class="font-semibold text-hvm-textschwarz [.hvm-dark_&]:text-white">{{ $aktuellerTitel }}</span>@endif
            </p>
        @endif
    </div>

    <ol class="mt-4 grid gap-2 {{ $spaltenKlasse }}">
        @foreach (array_values($steps) as $i => $schritt)
            @php
                $zustand = $schritt['state'] ?? 'open';
                $segment = match ($zustand) {
                    'done' => 'bg-hvm-orange',
                    'current' => 'bg-hvm-orange',
                    default => 'bg-hvm-canvas-deep [.hvm-dark_&]:bg-hvm-graphit',
                };
                $zustandswort = match ($zustand) {
                    'done' => 'Erledigt: ',
                    'current' => 'Aktuell: ',
                    default => 'Offen: ',
                };
                $textfarbe = $zustand === 'current'
                    ? 'font-semibold text-hvm-textschwarz [.hvm-dark_&]:text-white'
                    : 'text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau';
            @endphp
            <li class="min-w-0" @if ($zustand === 'current') aria-current="step" @endif>
                <span class="block h-1.5 rounded-full {{ $segment }}" aria-hidden="true"></span>
                @if (isset($schritt['href']))
                    <a href="{{ $schritt['href'] }}" class="mt-2 block min-h-6 text-xs leading-5 no-underline hover:underline underline-offset-4 {{ $textfarbe }} {{ $compact ? 'sr-only' : 'hidden sm:block' }}">
                        <span class="sr-only">{{ $zustandswort }}</span>{{ $i + 1 }}. {{ $schritt['label'] }}
                    </a>
                @else
                    <span class="mt-2 block min-h-6 text-xs leading-5 {{ $textfarbe }} {{ $compact ? 'sr-only' : 'hidden sm:block' }}">
                        <span class="sr-only">{{ $zustandswort }}</span>{{ $i + 1 }}. {{ $schritt['label'] }}
                    </span>
                @endif
                @if (isset($schritt['note']) && ! $compact)
                    <span class="mt-1 hidden text-xs text-hvm-text-sekundaer sm:block [.hvm-dark_&]:text-hvm-hellgrau">{{ $schritt['note'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>

    @if ($slot->isNotEmpty())
        <div class="mt-4 text-sm leading-relaxed text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $slot }}</div>
    @endif
</nav>
