{{--
    Numerierter Ablaufschritt des HVM-Designsystems (Konzept A).

    Die Nummer ist ein Fortschrittselement und darf daher HVM Orange tragen:
    ein orangefarbener Kreis mit der Ziffer in Textschwarz.

    Props:
      number  Schrittnummer
      title   Kurztitel des Schritts
      level   Ueberschriftenebene, Standard h3
      note    kurze Zusatzangabe rechts, zum Beispiel ein Status
--}}
@props([
    'number',
    'title',
    'level' => 'h3',
    'note' => null,
])

<div {{ $attributes->class('flex gap-5') }}>
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-hvm-orange text-base font-bold text-hvm-textschwarz tabular"
          aria-hidden="true">
        {{ $number }}
    </span>

    <div class="min-w-0 pt-1.5">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <{{ $level }} class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">
                <span class="sr-only">Schritt {{ $number }}: </span>{{ $title }}
            </{{ $level }}>
            @if ($note !== null)
                <x-hvm.badge>{{ $note }}</x-hvm.badge>
            @endif
        </div>

        <div class="mt-2 max-w-prose text-base leading-relaxed text-hvm-text-sekundaer">
            {{ $slot }}
        </div>
    </div>
</div>
