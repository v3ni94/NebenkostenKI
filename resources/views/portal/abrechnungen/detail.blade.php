@extends('layouts.portal')

@section('titel', 'Abrechnung '.$lauf->billing_year)

@section('content')
    <x-hvm.section-heading
        eyebrow="{{ $objekt->label }}"
        title="Abrechnung {{ $lauf->billing_year }}"
        lead="Zeitraum {{ $lauf->period_start?->format('d.m.Y') }} bis {{ $lauf->period_end?->format('d.m.Y') }}, {{ $lauf->mode->label() }}." />

    {{-- Naechster Schritt des gefuehrten Ablaufs ------------------------------ --}}

    @if ($naechsterSchritt !== null)
        <x-hvm.card class="mt-8" title="Nächster Schritt" accent>
            <p class="text-base font-semibold text-hvm-anthrazit">{{ $naechsterSchritt['titel'] }}</p>
            <p class="mt-2 text-sm text-hvm-textschwarz">{{ $naechsterSchritt['hinweis'] }}</p>

            <div class="mt-5">
                <x-hvm.button href="{{ $naechsterSchritt['url'] }}" variant="primary">
                    {{ $naechsterSchritt['schaltflaeche'] }}
                </x-hvm.button>
            </div>
        </x-hvm.card>
    @endif

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Stand der Abrechnung">
            @include('portal.partials.status', ['status' => $hinweis])

            <p class="mt-4 text-sm text-hvm-anthrazit">
                Sie können jederzeit unterbrechen. Ihre Angaben bleiben gespeichert.
            </p>
        </x-hvm.card>

        <x-hvm.card title="Objektdaten">
            @include('portal.partials.status', ['status' => $objektHinweis])

            <div class="mt-4 flex flex-wrap gap-2">
                <x-hvm.button href="{{ route('portal.einheiten.index', ['property' => $objekt->getKey()]) }}"
                              variant="secondary" size="sm">Einheiten bearbeiten</x-hvm.button>
                <x-hvm.button href="{{ route('portal.objekte.vermieter.edit', ['property' => $objekt->getKey()]) }}"
                              variant="secondary" size="sm">Vermieter bearbeiten</x-hvm.button>
            </div>
        </x-hvm.card>
    </div>

    @if ($gewerbehinweis !== null)
        <div class="mt-6">
            <x-hvm.alert variant="warning" label="Bitte prüfen" title="Gewerbliches Mietverhältnis">
                {{ $gewerbehinweis }}
            </x-hvm.alert>
        </div>
    @endif

    {{-- Fortschrittsleiste ueber alle Schritte -------------------------------- --}}

    <div class="mt-8">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $fortschritt,
            'billingRun' => $lauf,
            'wiedereinstieg' => null,
        ])
    </div>

    {{-- Weitere Handlungen ---------------------------------------------------- --}}

    <div class="mt-8 flex flex-wrap gap-3">
        <x-hvm.button href="{{ route('portal.abrechnungen.index') }}" variant="secondary">
            Zurück zur Liste
        </x-hvm.button>

        @if ($abbrechbar)
            <form method="POST" action="{{ route('portal.abrechnungen.abbrechen', ['billingRun' => $lauf->getKey()]) }}">
                @csrf
                <x-hvm.button type="submit" variant="ghost">Abrechnung abbrechen</x-hvm.button>
            </form>
        @endif
    </div>
@endsection
