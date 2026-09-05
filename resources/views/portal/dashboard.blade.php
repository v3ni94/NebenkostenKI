@extends('layouts.portal')

@section('titel', 'Übersicht')

@section('content')
    <x-hvm.page-header
        eyebrow="Portal"
        title="Ihre Übersicht"
        lead="Hier sehen Sie auf einen Blick, was erledigt ist und was noch fehlt.">
        <x-slot:actions>
            <x-hvm.button href="{{ route('portal.objekte.create') }}" variant="primary">
                <x-hvm.icon name="plus" class="h-4 w-4" />
                Objekt anlegen
            </x-hvm.button>
        </x-slot:actions>
    </x-hvm.page-header>

    {{-- Zaehler je Statuskategorie. Die vier Begriffe sind verbindlich. --}}
    <div class="mt-10 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        @foreach ($zaehler as $kategorie => $anzahl)
            <x-hvm.stat
                :label="$kategorie"
                :value="$anzahl"
                :variant="\App\Application\BillingRun\PortalStatusCategory::variant($kategorie)" />
        @endforeach
    </div>

    {{-- Objekte -------------------------------------------------------------- --}}

    <section class="mt-16" aria-labelledby="ueberschrift-objekte">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Bestand</p>
                <h2 id="ueberschrift-objekte" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Objekte</h2>
            </div>
            <x-hvm.button href="{{ route('portal.objekte.index') }}" variant="ghost" size="sm">Alle Objekte</x-hvm.button>
        </div>

        @if ($objekte === [])
            <x-hvm.empty-state class="mt-6" icon="house" title="Noch kein Objekt">
                <p>Sie haben noch kein Objekt angelegt. Beginnen Sie mit der Anschrift des Objekts.</p>
                <x-slot:action>
                    <x-hvm.button href="{{ route('portal.objekte.create') }}" variant="primary">Objekt anlegen</x-hvm.button>
                </x-slot:action>
            </x-hvm.empty-state>
        @else
            <x-hvm.card class="mt-6 divide-y divide-hvm-linie" padding="none">
                @foreach ($objekte as $objekt)
                    <x-hvm.list-row
                        :title="$objekt->label"
                        :subtitle="$objekt->address_line.', '.$objekt->postal_code.' '.$objekt->city">
                        @include('portal.partials.status', ['status' => $objektStatus[$objekt->getKey()]])

                        <x-slot:actions>
                            <x-hvm.button href="{{ route('portal.einheiten.index', ['property' => $objekt->getKey()]) }}"
                                          variant="secondary" size="sm">Einheiten</x-hvm.button>
                            {{-- Genau ein Primaerbutton je Ansicht (Seitenkopf), Zeilenhandlungen sind secondary. --}}
                            <x-hvm.button href="{{ route('portal.abrechnungen.create', ['property' => $objekt->getKey()]) }}"
                                          variant="secondary" size="sm">
                                Abrechnung starten
                                <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                            </x-hvm.button>
                        </x-slot:actions>
                    </x-hvm.list-row>
                @endforeach
            </x-hvm.card>
        @endif
    </section>

    {{-- Abrechnungslaeufe ----------------------------------------------------- --}}

    <section class="mt-16" aria-labelledby="ueberschrift-abrechnungen">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Laufende Vorgänge</p>
                <h2 id="ueberschrift-abrechnungen" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Abrechnungen</h2>
            </div>
            <x-hvm.button href="{{ route('portal.abrechnungen.create') }}" variant="secondary" size="sm">
                <x-hvm.icon name="plus" class="h-4 w-4" />
                Neue Abrechnung
            </x-hvm.button>
        </div>

        @if ($laeufe === [])
            <x-hvm.empty-state class="mt-6" icon="document" title="Noch keine Abrechnung">
                <p>Es ist noch keine Abrechnung angelegt. Legen Sie zuerst ein Objekt mit Einheiten an.</p>
            </x-hvm.empty-state>
        @else
            <x-hvm.card class="mt-6 divide-y divide-hvm-linie" padding="none">
                @foreach ($laeufe as $lauf)
                    @php
                        $laufTitel = 'Abrechnung '.$lauf->billing_year.($lauf->property !== null ? ' für '.$lauf->property->label : '');
                    @endphp
                    <x-hvm.list-row
                        :title="$laufTitel"
                        :href="route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()])"
                        :subtitle="'Zeitraum '.$lauf->period_start?->format('d.m.Y').' bis '.$lauf->period_end?->format('d.m.Y')">
                        @include('portal.partials.status', ['status' => $laufStatus[$lauf->getKey()]])

                        <x-slot:actions>
                            <x-hvm.button href="{{ route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]) }}"
                                          variant="secondary" size="sm">
                                Öffnen
                                <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                            </x-hvm.button>
                        </x-slot:actions>
                    </x-hvm.list-row>
                @endforeach
            </x-hvm.card>
        @endif
    </section>
@endsection
