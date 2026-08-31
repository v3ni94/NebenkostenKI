@php
    use App\Enums\BillingMode;

    $wert = static fn (string $feld, mixed $vorgabe = null): mixed => old($feld, $vorgabe);
@endphp

@extends('layouts.portal')

@section('titel', 'Neue Abrechnung')

@section('content')
    <div class="mx-auto max-w-3xl">
        <x-hvm.section-heading
            eyebrow="Schritt 1"
            title="Abrechnungszeitraum und Weg"
            lead="Voreingestellt ist das vollständige Vorjahr. Ein unterjähriger Zeitraum ist möglich." />

        @if ($objekte === [])
            <x-hvm.card class="mt-8">
                <p>
                    Legen Sie zuerst ein Objekt an. Danach können Sie eine Abrechnung starten.
                </p>
                <div class="mt-4">
                    <x-hvm.button href="{{ route('portal.objekte.create') }}" variant="primary">Objekt anlegen</x-hvm.button>
                </div>
            </x-hvm.card>
        @else
            @if ($gewerbehinweis !== null)
                <div class="mt-6">
                    <x-hvm.alert variant="warning" label="Bitte prüfen" title="Gewerbliches Mietverhältnis">
                        {{ $gewerbehinweis }}
                    </x-hvm.alert>
                </div>
            @endif

            <form method="POST" action="{{ route('portal.abrechnungen.store') }}" class="mt-8 space-y-6">
                @csrf

                <x-hvm.card title="Objekt">
                    <label for="property_id" class="block text-sm font-semibold text-hvm-textschwarz">Objekt</label>
                    <select id="property_id" name="property_id" required
                            class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        @foreach ($objekte as $eintrag)
                            <option value="{{ $eintrag->getKey() }}"
                                    @selected($wert('property_id', $objekt?->getKey()) === $eintrag->getKey())>
                                {{ $eintrag->label }}, {{ $eintrag->postal_code }} {{ $eintrag->city }}
                                ({{ $eintrag->units_count }} {{ $eintrag->units_count === 1 ? 'Einheit' : 'Einheiten' }})
                            </option>
                        @endforeach
                    </select>
                    @error('property_id')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                </x-hvm.card>

                <x-hvm.card title="Abrechnungszeitraum">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="period_start" class="block text-sm font-semibold text-hvm-textschwarz">Beginn</label>
                            <input id="period_start" name="period_start" type="date" required
                                   value="{{ $wert('period_start', $zeitraum['start']) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @error('period_start')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="period_end" class="block text-sm font-semibold text-hvm-textschwarz">Ende</label>
                            <input id="period_end" name="period_end" type="date" required
                                   value="{{ $wert('period_end', $zeitraum['end']) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @error('period_end')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <p class="mt-3 text-sm text-hvm-anthrazit">
                        Vorschlag: 01.01.{{ $zeitraum['jahr'] }} bis 31.12.{{ $zeitraum['jahr'] }}.
                    </p>
                </x-hvm.card>

                <x-hvm.card title="Abrechnungsweg">
                    <p class="text-sm text-hvm-anthrazit">
                        Empfehlung für das gewählte Objekt: {{ $empfehlung->label() }}. Sie können den Weg jederzeit
                        wechseln, bereits ausgelesene Inhaltsdaten bleiben dabei erhalten.
                    </p>

                    <div class="mt-4 space-y-3">
                        @foreach (BillingMode::cases() as $modus)
                            <div class="flex items-start gap-3">
                                <input id="mode-{{ $modus->value }}" name="mode" type="radio" value="{{ $modus->value }}"
                                       @checked($wert('mode', $empfehlung->value) === $modus->value)
                                       class="mt-1 h-5 w-5 border-hvm-mittelgrau">
                                <label for="mode-{{ $modus->value }}" class="text-sm text-hvm-textschwarz">
                                    <span class="font-semibold">{{ $modus->label() }}</span><br>
                                    @if ($modus === BillingMode::QUICK_CONDO)
                                        Für eine vermietete Eigentumswohnung mit Hausgeldabrechnung, Grundsteuerbescheid
                                        und gegebenenfalls externer Heizkostenabrechnung.
                                    @else
                                        Für ein Mehrfamilienhaus oder mehrere Einheiten mit allen Rechnungen,
                                        Bescheiden, Zählerdaten und Mietverträgen.
                                    @endif
                                </label>
                            </div>
                        @endforeach
                    </div>
                    @error('mode')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                </x-hvm.card>

                <div class="flex flex-wrap gap-3">
                    <x-hvm.button type="submit" variant="primary">Abrechnung anlegen</x-hvm.button>
                    <x-hvm.button href="{{ route('portal.abrechnungen.index') }}" variant="secondary">Abbrechen</x-hvm.button>
                </div>
            </form>
        @endif
    </div>
@endsection
