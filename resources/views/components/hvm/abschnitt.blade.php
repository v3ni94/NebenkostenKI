{{--
    Abschnitt der Anwendung (x-hvm.abschnitt) mit Unterabschnittskopf (Muster 4.3) und
    wahlweise einem weissen Rahmen fuer Tabellen (Muster 4.7) oder einem
    Leerzustand (Muster 4.11).

    Props:
      title     Ueberschrift (Pflicht)
      level     Ueberschriftenebene, Standard h2
      eyebrow   kurze Einordnung ueber der Ueberschrift
      lead      erklaerender Satz unter der Ueberschrift
      leer      true zeigt statt des Inhalts einen Leerzustand
      leertext  Text des Leerzustands, Standard "Kein Eintrag."
      leerIcon  Icon des Leerzustands, Standard inbox
      rahmen    true (Standard) legt den Inhalt in einen weissen, runden
                Rahmen (fuer Tabellen); false rendert den Slot direkt

    Slots:
      default  Inhalt (Tabelle oder Karten)
      actions  Schaltflaechen rechts neben der Ueberschrift
      footer   Inhalt unter dem Rahmen (z. B. ein Formular)
--}}
@props([
    'title',
    'level' => 'h2',
    'eyebrow' => null,
    'lead' => null,
    'leer' => false,
    'leertext' => 'Kein Eintrag.',
    'leerIcon' => 'inbox',
    'rahmen' => true,
])

@php
    $ueberschriftId = 'abschnitt-'.substr(md5($title), 0, 10);
@endphp

<section {{ $attributes }} aria-labelledby="{{ $ueberschriftId }}">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0 max-w-3xl">
            @if ($eyebrow !== null)
                <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">{{ $eyebrow }}</p>
            @endif
            <{{ $level }} id="{{ $ueberschriftId }}" class="{{ $eyebrow !== null ? 'mt-1 ' : '' }}text-2xl font-semibold tracking-tight text-hvm-textschwarz">{{ $title }}</{{ $level }}>
            @if ($lead !== null)
                <p class="mt-2 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">{{ $lead }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex flex-wrap gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>

    @if ($leer)
        <x-hvm.empty-state class="mt-6" :icon="$leerIcon">
            <p>{{ $leertext }}</p>
        </x-hvm.empty-state>
    @elseif ($rahmen)
        <div class="mt-6 overflow-hidden rounded-3xl border border-hvm-linie bg-white">
            {{ $slot }}
        </div>
    @else
        <div class="mt-6">
            {{ $slot }}
        </div>
    @endif

    @isset($footer)
        <div class="mt-4">
            {{ $footer }}
        </div>
    @endisset
</section>
