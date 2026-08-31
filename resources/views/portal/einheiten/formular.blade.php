@php
    $bearbeiten = $einheit !== null;
    $ziel = $bearbeiten
        ? route('portal.einheiten.update', ['unit' => $einheit->getKey()])
        : route('portal.einheiten.store', ['property' => $objekt->getKey()]);

    $wert = static fn (string $feld, mixed $vorgabe = null): mixed => old($feld, $vorgabe);
@endphp

@extends('layouts.portal')

@section('titel', $bearbeiten ? 'Einheit bearbeiten' : 'Einheit anlegen')

@section('content')
    <div class="mx-auto max-w-3xl">
        <x-hvm.section-heading
            eyebrow="{{ $objekt->label }}"
            :title="$bearbeiten ? 'Einheit bearbeiten' : 'Einheit anlegen'"
            lead="Fehlende Werte bleiben leer und erzeugen einen Hinweis. Es wird nichts geschätzt." />

        <form method="POST" action="{{ $ziel }}" class="mt-8 space-y-6">
            @csrf
            @if ($bearbeiten)
                @method('PUT')
            @endif

            <x-hvm.card title="Bezeichnung und Lage">
                <div class="space-y-5">
                    <div>
                        <label for="label" class="block text-sm font-semibold text-hvm-textschwarz">Bezeichnung</label>
                        <input id="label" name="label" type="text" required
                               value="{{ $wert('label', $einheit?->label) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        <p class="mt-1 text-sm text-hvm-anthrazit">Zum Beispiel WE 3.</p>
                        @error('label')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="location" class="block text-sm font-semibold text-hvm-textschwarz">Lage</label>
                            <input id="location" name="location" type="text"
                                   value="{{ $wert('location', $einheit?->location) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            <p class="mt-1 text-sm text-hvm-anthrazit">Zum Beispiel 2. OG links.</p>
                        </div>
                        <div>
                            <label for="unit_number" class="block text-sm font-semibold text-hvm-textschwarz">Wohnungsnummer</label>
                            <input id="unit_number" name="unit_number" type="text"
                                   value="{{ $wert('unit_number', $einheit?->unit_number) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        </div>
                    </div>
                </div>
            </x-hvm.card>

            <x-hvm.card title="Flächen und Anteile">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="living_area_sqm" class="block text-sm font-semibold text-hvm-textschwarz">
                            Wohnfläche in Quadratmeter
                        </label>
                        <input id="living_area_sqm" name="living_area_sqm" type="text" inputmode="decimal"
                               value="{{ $wert('living_area_sqm', $einheit?->living_area_sqm) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        @error('living_area_sqm')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="heated_area_sqm" class="block text-sm font-semibold text-hvm-textschwarz">
                            Beheizte Fläche in Quadratmeter
                        </label>
                        <input id="heated_area_sqm" name="heated_area_sqm" type="text" inputmode="decimal"
                               value="{{ $wert('heated_area_sqm', $einheit?->heated_area_sqm) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    </div>
                    <div>
                        <label for="mea" class="block text-sm font-semibold text-hvm-textschwarz">
                            Miteigentumsanteil, Zähler
                        </label>
                        <input id="mea" name="mea" type="text" inputmode="decimal"
                               value="{{ $wert('mea', $einheit?->mea) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        <p class="mt-1 text-sm text-hvm-anthrazit">
                            Zum Nenner des Objekts, zum Beispiel 87 bei 87/1.000.
                        </p>
                    </div>
                    <div>
                        <label for="room_count" class="block text-sm font-semibold text-hvm-textschwarz">Anzahl der Zimmer</label>
                        <input id="room_count" name="room_count" type="number" min="0" max="99"
                               value="{{ $wert('room_count', $einheit?->room_count) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    </div>
                </div>
            </x-hvm.card>

            <x-hvm.card title="Individuelle Schlüsselwerte">
                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach (range(1, 5) as $nummer)
                        @php
                            $bezeichnung = $objekt->getAttribute('individual_key_'.$nummer.'_label');
                        @endphp
                        <div>
                            <label for="individual_key_{{ $nummer }}_value" class="block text-sm font-semibold text-hvm-textschwarz">
                                {{ $bezeichnung !== null && $bezeichnung !== '' ? $bezeichnung : 'Schlüssel '.$nummer }}
                            </label>
                            <input id="individual_key_{{ $nummer }}_value" name="individual_key_{{ $nummer }}_value"
                                   type="text" inputmode="decimal"
                                   value="{{ $wert('individual_key_'.$nummer.'_value', $einheit?->getAttribute('individual_key_'.$nummer.'_value')) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @if ($bezeichnung === null || $bezeichnung === '')
                                <p class="mt-1 text-sm text-hvm-anthrazit">
                                    Für diesen Schlüssel ist am Objekt noch keine Bezeichnung hinterlegt.
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-hvm.card>

            <x-hvm.card title="Nutzung">
                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <input id="is_commercial" name="is_commercial" type="checkbox" value="1"
                               @checked($wert('is_commercial', $einheit?->is_commercial))
                               class="mt-1 h-5 w-5 rounded border-hvm-mittelgrau">
                        <label for="is_commercial" class="text-sm text-hvm-textschwarz">
                            Gewerbliche Nutzung. Gewerbliche Einheiten werden nicht automatisch finalisiert.
                        </label>
                    </div>
                    <div class="flex items-start gap-3">
                        <input id="is_owner_occupied" name="is_owner_occupied" type="checkbox" value="1"
                               @checked($wert('is_owner_occupied', $einheit?->is_owner_occupied))
                               class="mt-1 h-5 w-5 rounded border-hvm-mittelgrau">
                        <label for="is_owner_occupied" class="text-sm text-hvm-textschwarz">
                            Vom Eigentümer selbst genutzt.
                        </label>
                    </div>
                </div>
            </x-hvm.card>

            <div class="flex flex-wrap gap-3">
                <x-hvm.button type="submit" variant="primary">Speichern</x-hvm.button>
                <x-hvm.button href="{{ route('portal.einheiten.index', ['property' => $objekt->getKey()]) }}"
                              variant="secondary">Abbrechen</x-hvm.button>
            </div>
        </form>
    </div>
@endsection
