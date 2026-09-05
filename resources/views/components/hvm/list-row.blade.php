{{--
    Listenzeile des HVM-Designsystems (Konzept A).

    Fuer Listen als Karten (Objekte, Abrechnungen, Einheiten): links Titel,
    Untertitel und Status, rechts die Handlungen. Auf schmalen Bildschirmen
    stapeln sich beide Bereiche. Mehrere Zeilen werden in einer
    x-hvm.card padding="none" mit divide-y divide-hvm-linie gestapelt.

    Props:
      title     Titel (Pflicht)
      subtitle  Untertitel, z. B. Anschrift oder Zeitraum
      level     Ueberschriftenebene, Standard h3
      href      macht den Titel zum Link

    Slots:
      default   weiterer Inhalt (Status, Hinweise)
      actions   Schaltflaechen rechts
--}}
@props([
    'title',
    'subtitle' => null,
    'level' => 'h3',
    'href' => null,
])

<div {{ $attributes->class('flex flex-col gap-5 p-5 sm:p-6 lg:flex-row lg:items-start lg:justify-between') }}>
    <div class="min-w-0 flex-1">
        <{{ $level }} class="text-lg font-semibold tracking-tight text-hvm-textschwarz">
            @if ($href !== null)
                <a href="{{ $href }}" class="no-underline hover:underline underline-offset-4">{{ $title }}</a>
            @else
                {{ $title }}
            @endif
        </{{ $level }}>
        @if ($subtitle !== null)
            <p class="mt-1 text-sm text-hvm-text-sekundaer">{{ $subtitle }}</p>
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
