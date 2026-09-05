@php
    use App\Application\BillingRun\PortalStatusCategory;

    // Schrittanzeige aus der Fortschrittsleiste des gefuehrten Ablaufs:
    // aktuell = current, Kategorie Erledigt = done, sonst open. Erreichbare
    // Schritte werden verlinkt, die Kategorie steht als Zusatz im Text.
    $schritte = [];
    foreach ($fortschritt as $station) {
        $schritte[] = [
            'label' => $station->label(),
            'state' => $station->aktuell
                ? 'current'
                : ($station->kategorie === PortalStatusCategory::ERLEDIGT ? 'done' : 'open'),
            'href' => $station->erreichbar
                ? route($station->step->routeName(), ['billingRun' => $lauf->getKey()])
                : null,
            'note' => $station->kategorie,
        ];
    }
    $schritte = array_map(static fn (array $s): array => array_filter($s, static fn ($v) => $v !== null), $schritte);
@endphp

@extends('layouts.portal')

@section('titel', 'Abrechnung '.$lauf->billing_year)

@section('content')
    <x-hvm.page-header
        eyebrow="{{ $objekt->label }}"
        title="Abrechnung {{ $lauf->billing_year }}"
        lead="Zeitraum {{ $lauf->period_start?->format('d.m.Y') }} bis {{ $lauf->period_end?->format('d.m.Y') }}, {{ $lauf->mode->label() }}."
        :back="route('portal.abrechnungen.index')"
        backLabel="Zurück zur Liste" />

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

    {{-- Fortschritt ueber alle Schritte --------------------------------------- --}}

    <x-hvm.stepper class="mt-10" :steps="$schritte">
        Jeder Schritt speichert sofort. Sie können jederzeit unterbrechen und später ohne Datenverlust fortfahren.
    </x-hvm.stepper>

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
