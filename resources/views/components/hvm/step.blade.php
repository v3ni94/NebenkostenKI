{{--
    Numerierter Ablaufschritt des HVM-Designsystems.

    Die Nummer ist ein Fortschrittselement und darf daher HVM Orange tragen.

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

<div {{ $attributes->class('flex gap-4') }}>
    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange text-base font-bold text-hvm-textschwarz"
          aria-hidden="true">
        {{ $number }}
    </span>

    <div class="min-w-0 pt-1">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <{{ $level }} class="text-lg font-semibold text-hvm-anthrazit">
                <span class="sr-only">Schritt {{ $number }}: </span>{{ $title }}
            </{{ $level }}>
            @if ($note !== null)
                <x-hvm.badge>{{ $note }}</x-hvm.badge>
            @endif
        </div>

        <div class="mt-2 text-base leading-relaxed text-hvm-textschwarz">
            {{ $slot }}
        </div>
    </div>
</div>
