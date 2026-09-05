{{--
    Listenzeile des HVM-Designsystems.

    Fuer Listen als Karten (Objekte, Abrechnungen, Einheiten). Zwei Layouts:

      Standard   links Titel, Untertitel und Slot, rechts die Handlungen
                 (ab lg nebeneinander, darunter gestapelt). Fuer kurze Zeilen.
      stacked    Titelzeile mit Handlungen rechts, darunter der Slot ueber die
                 volle Kartenbreite. Fuer Eintraege mit viel Inhalt (Objekte
                 mit Status und Details), damit rechts keine Leerflaeche bleibt.

    Mehrere Zeilen werden in einer x-hvm.card padding="none" mit
    divide-y divide-hvm-linie gestapelt.

    Props:
      title     Titel (Pflicht)
      subtitle  Untertitel, z. B. Anschrift oder Zeitraum
      level     Ueberschriftenebene, Standard h3
      href      macht den Titel zum Link
      stacked   true schaltet auf das gestapelte Layout

    Slots:
      default   weiterer Inhalt (Status, Hinweise)
      actions   Schaltflaechen
--}}
@props([
    'title',
    'subtitle' => null,
    'level' => 'h3',
    'href' => null,
    'stacked' => false,
])

@php
    $titelKlassen = 'text-lg font-semibold tracking-tight text-hvm-textschwarz [.hvm-dark_&]:text-white';
    $untertitelKlassen = 'mt-1 text-sm text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau';
@endphp

@if ($stacked)
    <div {{ $attributes->class('p-5 sm:p-6') }}>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 flex-1">
                <{{ $level }} class="{{ $titelKlassen }}">
                    @if ($href !== null)
                        <a href="{{ $href }}" class="no-underline hover:underline underline-offset-4">{{ $title }}</a>
                    @else
                        {{ $title }}
                    @endif
                </{{ $level }}>
                @if ($subtitle !== null)
                    <p class="{{ $untertitelKlassen }}">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex flex-wrap gap-2 sm:shrink-0 sm:justify-end">
                    {{ $actions }}
                </div>
            @endisset
        </div>

        @if ($slot->isNotEmpty())
            <div class="mt-5">{{ $slot }}</div>
        @endif
    </div>
@else
    <div {{ $attributes->class('flex flex-col gap-5 p-5 sm:p-6 lg:flex-row lg:items-start lg:justify-between') }}>
        <div class="min-w-0 flex-1">
            <{{ $level }} class="{{ $titelKlassen }}">
                @if ($href !== null)
                    <a href="{{ $href }}" class="no-underline hover:underline underline-offset-4">{{ $title }}</a>
                @else
                    {{ $title }}
                @endif
            </{{ $level }}>
            @if ($subtitle !== null)
                <p class="{{ $untertitelKlassen }}">{{ $subtitle }}</p>
            @endif
            @if ($slot->isNotEmpty())
                <div class="mt-4">{{ $slot }}</div>
            @endif
        </div>

        @isset($actions)
            <div class="flex flex-wrap gap-2 lg:shrink-0 lg:justify-end">
                {{ $actions }}
            </div>
        @endisset
    </div>
@endif
