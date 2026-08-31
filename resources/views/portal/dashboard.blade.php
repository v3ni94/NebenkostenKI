@extends('layouts.portal')

@section('titel', 'Übersicht')

@section('content')
    <x-hvm.section-heading
        title="Ihre Übersicht"
        lead="Hier sehen Sie auf einen Blick, was erledigt ist und was noch fehlt." />

    {{-- Zaehler je Statuskategorie. Die vier Begriffe sind verbindlich. --}}
    <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($zaehler as $kategorie => $anzahl)
            <x-hvm.card>
                <p class="text-sm font-semibold text-hvm-anthrazit">{{ $kategorie }}</p>
                <p class="mt-1 text-3xl font-bold text-hvm-textschwarz">{{ $anzahl }}</p>
            </x-hvm.card>
        @endforeach
    </div>

    {{-- Objekte -------------------------------------------------------------- --}}

    <div class="mt-12 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-bold text-hvm-anthrazit">Objekte</h2>
        <x-hvm.button href="{{ route('portal.objekte.create') }}" variant="primary" size="sm">
            Objekt anlegen
        </x-hvm.button>
    </div>

    @if ($objekte === [])
        <x-hvm.card class="mt-4">
            <p>Sie haben noch kein Objekt angelegt. Beginnen Sie mit der Anschrift des Objekts.</p>
        </x-hvm.card>
    @else
        <div class="mt-4 space-y-4">
            @foreach ($objekte as $objekt)
                <x-hvm.card>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold text-hvm-anthrazit">{{ $objekt->label }}</h3>
                            <p class="mt-1 text-sm text-hvm-textschwarz">
                                {{ $objekt->address_line }}, {{ $objekt->postal_code }} {{ $objekt->city }}
                            </p>
                            <div class="mt-3">
                                @include('portal.partials.status', ['status' => $objektStatus[$objekt->getKey()]])
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-hvm.button href="{{ route('portal.einheiten.index', ['property' => $objekt->getKey()]) }}"
                                          variant="secondary" size="sm">Einheiten</x-hvm.button>
                            <x-hvm.button href="{{ route('portal.abrechnungen.create', ['property' => $objekt->getKey()]) }}"
                                          variant="primary" size="sm">Abrechnung starten</x-hvm.button>
                        </div>
                    </div>
                </x-hvm.card>
            @endforeach
        </div>
    @endif

    {{-- Abrechnungslaeufe ----------------------------------------------------- --}}

    <div class="mt-12 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-bold text-hvm-anthrazit">Abrechnungen</h2>
        <x-hvm.button href="{{ route('portal.abrechnungen.create') }}" variant="secondary" size="sm">
            Neue Abrechnung
        </x-hvm.button>
    </div>

    @if ($laeufe === [])
        <x-hvm.card class="mt-4">
            <p>Es ist noch keine Abrechnung angelegt. Legen Sie zuerst ein Objekt mit Einheiten an.</p>
        </x-hvm.card>
    @else
        <div class="mt-4 space-y-4">
            @foreach ($laeufe as $lauf)
                <x-hvm.card>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold text-hvm-anthrazit">
                                Abrechnung {{ $lauf->billing_year }}
                                @if ($lauf->property !== null)
                                    für {{ $lauf->property->label }}
                                @endif
                            </h3>
                            <p class="mt-1 text-sm text-hvm-textschwarz">
                                Zeitraum {{ $lauf->period_start?->format('d.m.Y') }}
                                bis {{ $lauf->period_end?->format('d.m.Y') }}
                            </p>
                            <div class="mt-3">
                                @include('portal.partials.status', ['status' => $laufStatus[$lauf->getKey()]])
                            </div>
                        </div>
                        <x-hvm.button href="{{ route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]) }}"
                                      variant="secondary" size="sm">Öffnen</x-hvm.button>
                    </div>
                </x-hvm.card>
            @endforeach
        </div>
    @endif
@endsection
