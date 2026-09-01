{{--
    Eine Kostengruppe der Prüfung.

    Die Gruppe zeigt Kostenart und Summe. Sie ist auf die einzelnen Belege
    aufklappbar. Zwei Gärtnerrechnungen erscheinen damit als eine Zeile
    "Gartenpflege" mit Summe und lassen sich auf beide Belege aufklappen.
--}}
<x-hvm.card x-data="{ offen: {{ $gruppe->openCount > 0 ? 'true' : 'false' }} }">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h3 class="text-lg font-semibold text-hvm-anthrazit">{{ $gruppe->name }}</h3>
            <p class="mt-1 text-sm">
                {{ $gruppe->sumLabel }} aus {{ $gruppe->positionCount() }}
                {{ $gruppe->positionCount() === 1 ? 'Beleg' : 'Belegen' }}
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
                <x-hvm.badge variant="{{ $gruppe->notAllocable ? 'warning' : 'neutral' }}">
                    {{ $gruppe->apportionmentLabel }}
                </x-hvm.badge>

                @if ($gruppe->openCount > 0)
                    <x-hvm.badge variant="info">{{ $gruppe->openCount }} offen</x-hvm.badge>
                @else
                    <x-hvm.badge variant="success">Vollständig entschieden</x-hvm.badge>
                @endif

                @if ($gruppe->duplicateCount > 0)
                    <x-hvm.badge variant="warning">{{ $gruppe->duplicateCount }} Dublettenverdacht</x-hvm.badge>
                @endif

                @if ($gruppe->hasPeriodWarning)
                    <x-hvm.badge variant="warning">Zeitraum prüfen</x-hvm.badge>
                @endif
            </div>
        </div>

        <button type="button"
                class="min-h-11 rounded-md border border-hvm-anthrazit px-4 py-2 text-sm font-semibold"
                x-on:click="offen = !offen"
                x-bind:aria-expanded="offen ? 'true' : 'false'"
                aria-expanded="{{ $gruppe->openCount > 0 ? 'true' : 'false' }}">
            <span x-text="offen ? 'Belege zuklappen' : 'Belege aufklappen'">Belege aufklappen</span>
        </button>
    </div>

    @if ($gruppe->notAllocable)
        <x-hvm.alert variant="warning" class="mt-4">
            <p>
                Kosten dieser Art sind regelmäßig nicht auf Wohnraummieter umlegbar. Diese Angabe ist eine
                allgemeine Information und keine Rechtsberatung im Einzelfall.
            </p>
        </x-hvm.alert>
    @endif

    <div class="mt-4 space-y-4" x-show="offen" x-cloak>
        @foreach ($gruppe->positions as $position)
            @include('portal.pruefung.partials.position', [
                'position' => $position,
                'billingRun' => $billingRun,
                'kategorien' => $kategorien,
                'einheiten' => $einheiten,
            ])
        @endforeach
    </div>
</x-hvm.card>
