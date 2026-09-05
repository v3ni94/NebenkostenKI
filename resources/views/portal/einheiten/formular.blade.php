@php
    $bearbeiten = $einheit !== null;
    $ziel = $bearbeiten
        ? route('portal.einheiten.update', ['unit' => $einheit->getKey()])
        : route('portal.einheiten.store', ['property' => $objekt->getKey()]);

    $wert = static fn (string $feld, mixed $vorgabe = null): mixed => old($feld, $vorgabe);

    $legende = 'text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl';
@endphp

@extends('layouts.portal')

@section('titel', $bearbeiten ? 'Einheit bearbeiten' : 'Einheit anlegen')

@section('content')
    <div class="mx-auto max-w-2xl">
        <x-hvm.page-header
            eyebrow="{{ $objekt->label }}"
            :title="$bearbeiten ? 'Einheit bearbeiten' : 'Einheit anlegen'"
            lead="Fehlende Werte bleiben leer und erzeugen einen Hinweis. Es wird nichts geschätzt."
            :back="route('portal.einheiten.index', ['property' => $objekt->getKey()])"
            backLabel="Zurück zu den Einheiten" />

        <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
            <form method="POST" action="{{ $ziel }}" class="space-y-10 p-6 sm:p-8">
                @csrf
                @if ($bearbeiten)
                    @method('PUT')
                @endif

                {{-- Bezeichnung und Lage ------------------------------------------ --}}

                <fieldset>
                    <legend class="{{ $legende }}">Bezeichnung und Lage</legend>
                    <div class="mt-6 space-y-6">
                        <x-hvm.field
                            name="label"
                            label="Bezeichnung"
                            hint="Zum Beispiel WE 3."
                            :required="true"
                            :value="$wert('label', $einheit?->label)" />

                        <div class="grid gap-6 sm:grid-cols-2 sm:items-end">
                            <x-hvm.field
                                name="location"
                                label="Lage"
                                hint="Zum Beispiel 2. OG links."
                                :value="$wert('location', $einheit?->location)" />
                            <x-hvm.field
                                name="unit_number"
                                label="Wohnungsnummer"
                                :value="$wert('unit_number', $einheit?->unit_number)" />
                        </div>
                    </div>
                </fieldset>

                {{-- Flaechen und Anteile ------------------------------------------ --}}

                <div class="border-t border-hvm-linie pt-8">
                    <fieldset>
                        <legend class="{{ $legende }}">Flächen und Anteile</legend>
                        <div class="mt-6 grid gap-6 sm:grid-cols-2 sm:items-end">
                            <x-hvm.field
                                name="living_area_sqm"
                                label="Wohnfläche in Quadratmeter"
                                inputmode="decimal"
                                :value="$wert('living_area_sqm', $einheit?->living_area_sqm)" />
                            <x-hvm.field
                                name="heated_area_sqm"
                                label="Beheizte Fläche in Quadratmeter"
                                inputmode="decimal"
                                :value="$wert('heated_area_sqm', $einheit?->heated_area_sqm)" />
                            <x-hvm.field
                                name="mea"
                                label="Miteigentumsanteil, Zähler"
                                hint="Zum Nenner des Objekts, zum Beispiel 87 bei 87/1.000."
                                inputmode="decimal"
                                :value="$wert('mea', $einheit?->mea)" />
                            <x-hvm.field
                                name="room_count"
                                label="Anzahl der Zimmer"
                                type="number"
                                min="0"
                                max="99"
                                :value="$wert('room_count', $einheit?->room_count)" />
                        </div>
                    </fieldset>
                </div>

                {{-- Individuelle Schluesselwerte ---------------------------------- --}}

                <div class="border-t border-hvm-linie pt-8">
                    <fieldset>
                        <legend class="{{ $legende }}">Individuelle Schlüsselwerte</legend>
                        <div class="mt-6 grid gap-6 sm:grid-cols-2 sm:items-end">
                            @foreach (range(1, 5) as $nummer)
                                @php
                                    $bezeichnung = $objekt->getAttribute('individual_key_'.$nummer.'_label');
                                    $ohneBezeichnung = $bezeichnung === null || $bezeichnung === '';
                                @endphp
                                <x-hvm.field
                                    :name="'individual_key_'.$nummer.'_value'"
                                    :label="$ohneBezeichnung ? 'Schlüssel '.$nummer : $bezeichnung"
                                    :hint="$ohneBezeichnung ? 'Für diesen Schlüssel ist am Objekt noch keine Bezeichnung hinterlegt.' : null"
                                    inputmode="decimal"
                                    :value="$wert('individual_key_'.$nummer.'_value', $einheit?->getAttribute('individual_key_'.$nummer.'_value'))" />
                            @endforeach
                        </div>
                    </fieldset>
                </div>

                {{-- Nutzung ------------------------------------------------------- --}}

                <div class="border-t border-hvm-linie pt-8">
                    <fieldset>
                        <legend class="{{ $legende }}">Nutzung</legend>
                        <div class="mt-4 space-y-1">
                            <x-hvm.field
                                name="is_commercial"
                                label="Gewerbliche Nutzung. Gewerbliche Einheiten werden nicht automatisch finalisiert."
                                type="checkbox"
                                value="1"
                                :checked="(bool) $wert('is_commercial', $einheit?->is_commercial)" />
                            <x-hvm.field
                                name="is_owner_occupied"
                                label="Vom Eigentümer selbst genutzt."
                                type="checkbox"
                                value="1"
                                :checked="(bool) $wert('is_owner_occupied', $einheit?->is_owner_occupied)" />
                        </div>
                    </fieldset>
                </div>

                <div class="flex flex-wrap gap-3 border-t border-hvm-linie pt-8">
                    <x-hvm.button type="submit" variant="primary" size="lg">Speichern</x-hvm.button>
                    <x-hvm.button href="{{ route('portal.einheiten.index', ['property' => $objekt->getKey()]) }}"
                                  variant="secondary" size="lg">Abbrechen</x-hvm.button>
                </div>
            </form>
        </x-hvm.card>
    </div>
@endsection
