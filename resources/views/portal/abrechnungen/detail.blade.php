@extends('layouts.portal')

@section('titel', 'Abrechnung '.$lauf->billing_year)

@section('content')
    <x-hvm.section-heading
        eyebrow="{{ $objekt->label }}"
        title="Abrechnung {{ $lauf->billing_year }}"
        lead="Zeitraum {{ $lauf->period_start?->format('d.m.Y') }} bis {{ $lauf->period_end?->format('d.m.Y') }}, {{ $lauf->mode->label() }}." />

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Stand der Abrechnung" accent>
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

    {{-- Nutzerbestaetigung vor der Finalisierung ------------------------------ --}}

    <x-hvm.card class="mt-8" title="Bestätigung vor der Finalisierung">
        @if ($lauf->review_confirmed_at !== null && $lauf->responsibility_confirmed_at !== null)
            <x-hvm.alert variant="success" label="Erledigt">
                Sie haben die Prüfung und die Verantwortung am
                {{ $lauf->review_confirmed_at->format('d.m.Y') }} bestätigt.
            </x-hvm.alert>
        @else
            <p class="text-sm text-hvm-textschwarz">
                Vor dem Abschluss bestätigen Sie ausdrücklich, dass Sie alle Werte, Umlageschlüssel und Ergebnisse
                geprüft haben und als Vermieter für die Abrechnung verantwortlich sind. Beide Punkte sind
                erforderlich. Für diesen Schritt muss Ihre E-Mail-Adresse bestätigt sein.
            </p>

            <form method="POST"
                  action="{{ route('portal.abrechnungen.bestaetigen', ['billingRun' => $lauf->getKey()]) }}"
                  class="mt-5 space-y-4">
                @csrf

                <div class="flex items-start gap-3">
                    <input id="werte_geprueft" name="werte_geprueft" type="checkbox" value="1"
                           class="mt-1 h-5 w-5 rounded border-hvm-mittelgrau">
                    <label for="werte_geprueft" class="text-sm text-hvm-textschwarz">
                        Ich habe alle Werte, Umlageschlüssel und Ergebnisse geprüft.
                    </label>
                </div>

                <div class="flex items-start gap-3">
                    <input id="verantwortung_uebernommen" name="verantwortung_uebernommen" type="checkbox" value="1"
                           class="mt-1 h-5 w-5 rounded border-hvm-mittelgrau">
                    <label for="verantwortung_uebernommen" class="text-sm text-hvm-textschwarz">
                        Ich übernehme als Vermieter die Verantwortung für diese Betriebskostenabrechnung.
                    </label>
                </div>

                <x-hvm.button type="submit" variant="primary">Bestätigung speichern</x-hvm.button>
            </form>
        @endif
    </x-hvm.card>

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

    <p class="mt-8 text-sm text-hvm-anthrazit">
        Upload der Unterlagen, Kostenprüfung, Vorschau und Zahlung folgen in den nächsten Ausbaustufen dieses
        Bereichs.
    </p>
@endsection
