@extends('layouts.portal')

@section('titel', 'Abrechnung '.$lauf->billing_year)

@section('content')
    <x-hvm.page-header
        eyebrow="{{ $objekt->label }}"
        title="Abrechnung {{ $lauf->billing_year }}"
        lead="Zeitraum {{ $lauf->period_start?->format('d.m.Y') }} bis {{ $lauf->period_end?->format('d.m.Y') }}, {{ $lauf->mode->label() }}."
        :back="route('portal.abrechnungen.index')"
        backLabel="Zurück zur Liste" />

    {{--
        Fortschritt ueber alle zwoelf Schritte, direkt unter dem Seitenkopf wie
        auf jeder Wizard-Seite (4.3). Aktuell markiert ist der Schritt, den die
        Karte "Naechster Schritt" nennt: eine Fortschrittsaussage je Seite.
    --}}
    <div class="mt-8">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $fortschritt,
            'billingRun' => $lauf,
            'wiedereinstieg' => null,
        ])
    </div>

    {{-- Naechster Schritt des gefuehrten Ablaufs ------------------------------ --}}

    @if ($naechsterSchritt !== null)
        <x-hvm.card class="mt-10 rounded-3xl" :kennlinie="true" eyebrow="Nächster Schritt" :title="$naechsterSchritt['titel']">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <p class="max-w-prose text-base leading-relaxed text-hvm-text-sekundaer">{{ $naechsterSchritt['hinweis'] }}</p>
                <div class="lg:shrink-0">
                    <x-hvm.button href="{{ $naechsterSchritt['url'] }}" variant="primary" size="lg">
                        {{ $naechsterSchritt['schaltflaeche'] }}
                        <x-hvm.icon name="arrow-right" class="h-5 w-5" />
                    </x-hvm.button>
                </div>
            </div>
        </x-hvm.card>
    @endif

    {{-- Stand und Objektdaten ------------------------------------------------- --}}

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2 lg:items-start">
        <x-hvm.card class="min-w-0" title="Stand der Abrechnung">
            @include('portal.partials.status', ['status' => $hinweis])

            <p class="mt-5 border-t border-hvm-linie pt-4 text-sm leading-relaxed text-hvm-text-sekundaer">
                Sie können jederzeit unterbrechen. Ihre Angaben bleiben gespeichert.
            </p>
        </x-hvm.card>

        <x-hvm.card class="min-w-0" title="Objektdaten">
            @include('portal.partials.status', ['status' => $objektHinweis])

            <div class="mt-5 flex flex-wrap gap-2 border-t border-hvm-linie pt-4">
                <x-hvm.button href="{{ route('portal.einheiten.index', ['property' => $objekt->getKey()]) }}"
                              variant="secondary" size="sm">Einheiten bearbeiten</x-hvm.button>
                <x-hvm.button href="{{ route('portal.objekte.vermieter.edit', ['property' => $objekt->getKey()]) }}"
                              variant="secondary" size="sm">Vermieter bearbeiten</x-hvm.button>
            </div>
        </x-hvm.card>
    </div>

    @if ($gewerbehinweis !== null)
        <x-hvm.alert class="mt-6" variant="warning" label="Bitte prüfen" title="Gewerbliches Mietverhältnis">
            {{ $gewerbehinweis }}
        </x-hvm.alert>
    @endif

    {{-- Weitere Handlungen ---------------------------------------------------- --}}

    @if ($abbrechbar)
        <div class="mt-10 flex flex-wrap gap-3">
            <form method="POST" action="{{ route('portal.abrechnungen.abbrechen', ['billingRun' => $lauf->getKey()]) }}">
                @csrf
                <x-hvm.button type="submit" variant="danger">
                    <x-hvm.icon name="x-circle" class="h-4 w-4" />
                    Abrechnung abbrechen
                </x-hvm.button>
            </form>
        </div>
    @endif
@endsection
