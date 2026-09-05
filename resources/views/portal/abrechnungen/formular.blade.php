@php
    use App\Enums\BillingMode;

    $wert = static fn (string $feld, mixed $vorgabe = null): mixed => old($feld, $vorgabe);

    $legende = 'text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl';
    $erlaeuterung = 'mt-2 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer';

    // Weicher Trennstrich (U+00AD) als Zeichen statt &shy;, weil die
    // Ueberschriftenkomponente den Titel escaped. Sichtbarer Text bleibt gleich.
    $seitentitel = "Abrechnungs\u{00AD}zeitraum und Weg";
@endphp

@extends('layouts.portal')

@section('titel', 'Neue Abrechnung')

@section('content')
    <div class="mx-auto max-w-2xl">
        <x-hvm.page-header
            eyebrow="Schritt 1"
            :title="$seitentitel"
            lead="Voreingestellt ist das vollständige Vorjahr. Ein unterjähriger Zeitraum ist möglich."
            :back="route('portal.abrechnungen.index')"
            backLabel="Zurück zu den Abrechnungen" />

        @if ($objekte === [])
            <x-hvm.empty-state class="mt-10" icon="house" title="Noch kein Objekt">
                <p>Legen Sie zuerst ein Objekt an. Danach können Sie eine Abrechnung starten.</p>
                <x-slot:action>
                    <x-hvm.button href="{{ route('portal.objekte.create') }}" variant="primary">Objekt anlegen</x-hvm.button>
                </x-slot:action>
            </x-hvm.empty-state>
        @else
            @if ($gewerbehinweis !== null)
                <x-hvm.alert class="mt-8" variant="warning" label="Bitte prüfen" title="Gewerbliches Mietverhältnis">
                    {{ $gewerbehinweis }}
                </x-hvm.alert>
            @endif

            <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
                <form method="POST" action="{{ route('portal.abrechnungen.store') }}" class="space-y-10 p-6 sm:p-8">
                    @csrf

                    {{-- Objekt -------------------------------------------------------- --}}

                    <fieldset>
                        <legend class="{{ $legende }}">Objekt</legend>
                        <div class="mt-6">
                            <x-hvm.field name="property_id" label="Objekt" type="select" :required="true">
                                @foreach ($objekte as $eintrag)
                                    <option value="{{ $eintrag->getKey() }}"
                                            @selected($wert('property_id', $objekt?->getKey()) === $eintrag->getKey())>
                                        {{ $eintrag->label }}, {{ $eintrag->postal_code }} {{ $eintrag->city }}
                                        ({{ $eintrag->units_count }} {{ $eintrag->units_count === 1 ? 'Einheit' : 'Einheiten' }})
                                    </option>
                                @endforeach
                            </x-hvm.field>
                        </div>
                    </fieldset>

                    {{-- Abrechnungszeitraum ------------------------------------------- --}}

                    <div class="border-t border-hvm-linie pt-8">
                        <fieldset>
                            <legend class="{{ $legende }}">Abrechnungszeitraum</legend>
                            <p class="{{ $erlaeuterung }}">
                                Vorschlag: 01.01.{{ $zeitraum['jahr'] }} bis 31.12.{{ $zeitraum['jahr'] }}.
                            </p>

                            <div class="mt-6 grid gap-6 sm:grid-cols-2">
                                <x-hvm.field
                                    name="period_start"
                                    label="Beginn"
                                    type="date"
                                    :required="true"
                                    :value="$wert('period_start', $zeitraum['start'])" />
                                <x-hvm.field
                                    name="period_end"
                                    label="Ende"
                                    type="date"
                                    :required="true"
                                    :value="$wert('period_end', $zeitraum['end'])" />
                            </div>
                        </fieldset>
                    </div>

                    {{-- Abrechnungsweg ------------------------------------------------ --}}
                    {{--
                        Die Optionen behalten ihre bisherigen IDs (mode-<wert>), deshalb
                        werden sie nach dem Rezept .hvm-choice/.hvm-check direkt gesetzt.
                    --}}

                    <div class="border-t border-hvm-linie pt-8">
                        <fieldset @if ($errors->has('mode')) aria-invalid="true" aria-describedby="mode-fehler" @endif>
                            <legend class="{{ $legende }}">Abrechnungsweg</legend>
                            <p class="{{ $erlaeuterung }}">
                                Empfehlung für das gewählte Objekt: {{ $empfehlung->label() }}. Sie können den Weg jederzeit
                                wechseln, bereits ausgelesene Inhaltsdaten bleiben dabei erhalten.
                            </p>

                            <div class="mt-4 flex flex-col gap-1">
                                @foreach (BillingMode::cases() as $modus)
                                    <label for="mode-{{ $modus->value }}" class="hvm-choice items-start">
                                        <input id="mode-{{ $modus->value }}" name="mode" type="radio" value="{{ $modus->value }}"
                                               @checked($wert('mode', $empfehlung->value) === $modus->value)
                                               class="hvm-check mt-0.5">
                                        <span class="min-w-0">
                                            <span class="block font-semibold">{{ $modus->label() }}</span>
                                            <span class="block text-xs leading-relaxed text-hvm-text-sekundaer">
                                                @if ($modus === BillingMode::QUICK_CONDO)
                                                    Für eine vermietete Eigentumswohnung mit Hausgeldabrechnung, Grundsteuerbescheid
                                                    und gegebenenfalls externer Heizkostenabrechnung.
                                                @else
                                                    Für ein Mehrfamilienhaus oder mehrere Einheiten mit allen Rechnungen,
                                                    Bescheiden, Zählerdaten und Mietverträgen.
                                                @endif
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            @error('mode')
                                <p id="mode-fehler" class="mt-2 flex items-start gap-1.5 text-sm font-medium text-status-error">
                                    <x-hvm.icon name="warning" class="mt-0.5 h-4 w-4" />
                                    <span>{{ $message }}</span>
                                </p>
                            @enderror
                        </fieldset>
                    </div>

                    <div class="flex flex-wrap gap-3 border-t border-hvm-linie pt-8">
                        <x-hvm.button type="submit" variant="primary" size="lg">Abrechnung anlegen</x-hvm.button>
                        <x-hvm.button href="{{ route('portal.abrechnungen.index') }}" variant="secondary" size="lg">Abbrechen</x-hvm.button>
                    </div>
                </form>
            </x-hvm.card>
        @endif
    </div>
@endsection
