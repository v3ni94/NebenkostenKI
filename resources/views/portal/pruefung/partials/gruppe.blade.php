{{--
    Eine Kostengruppe der Prüfung.

    Die Gruppe zeigt Kostenart und Summe. Sie ist auf die einzelnen Belege
    aufklappbar. Zwei Gärtnerrechnungen erscheinen damit als eine Zeile
    "Gartenpflege" mit Summe und lassen sich auf beide Belege aufklappen.
    Ohne JavaScript bleiben die Belege sichtbar (x-cloak greift nur mit js).
--}}
<x-hvm.card padding="none" x-data="{ offen: {{ $gruppe->openCount > 0 ? 'true' : 'false' }} }">
    <div class="p-5 sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 flex-1">
                <h3 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">{{ $gruppe->name }}</h3>
                <p class="mt-1 text-sm text-hvm-text-sekundaer">
                    <span class="font-semibold text-hvm-textschwarz tabular whitespace-nowrap">{{ $gruppe->sumLabel }}</span>
                    aus {{ $gruppe->positionCount() }}
                    {{ $gruppe->positionCount() === 1 ? 'Beleg' : 'Belegen' }}
                </p>

                <div class="mt-3 flex flex-wrap gap-2">
                    <x-hvm.badge variant="{{ $gruppe->notAllocable ? 'warning' : 'neutral' }}">
                        {{ $gruppe->apportionmentLabel }}
                    </x-hvm.badge>

                    @if ($gruppe->openCount > 0)
                        <x-hvm.badge variant="info" icon="eye">{{ $gruppe->openCount }} offen</x-hvm.badge>
                    @else
                        <x-hvm.badge variant="success">Vollständig entschieden</x-hvm.badge>
                    @endif

                    @if ($gruppe->duplicateCount > 0)
                        <x-hvm.badge variant="warning" icon="layers">{{ $gruppe->duplicateCount }} Dublettenverdacht</x-hvm.badge>
                    @endif

                    @if ($gruppe->hasPeriodWarning)
                        <x-hvm.badge variant="warning" icon="calendar">Zeitraum prüfen</x-hvm.badge>
                    @endif
                </div>
            </div>

            <div class="sm:shrink-0">
                <x-hvm.button type="button" variant="secondary" size="sm"
                              x-on:click="offen = !offen"
                              x-bind:aria-expanded="offen ? 'true' : 'false'"
                              aria-expanded="{{ $gruppe->openCount > 0 ? 'true' : 'false' }}">
                    <span x-text="offen ? 'Belege zuklappen' : 'Belege aufklappen'">Belege aufklappen</span>
                    <x-hvm.icon name="chevron-down" class="h-4 w-4 transition-transform duration-150" x-bind:class="offen ? 'rotate-180' : ''" />
                </x-hvm.button>
            </div>
        </div>

        @if ($gruppe->notAllocable)
            <x-hvm.alert variant="warning" class="mt-5">
                <p>
                    Kosten dieser Art sind regelmäßig nicht auf Wohnraummieter umlegbar. Diese Angabe ist eine
                    allgemeine Information und keine Rechtsberatung im Einzelfall.
                </p>
            </x-hvm.alert>
        @endif
    </div>

    <div class="space-y-4 border-t border-hvm-linie bg-hvm-canvas p-4 sm:p-5" x-show="offen" x-cloak>
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
