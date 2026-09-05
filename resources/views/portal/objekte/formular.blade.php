@php
    use App\Enums\PropertyKind;

    $bearbeiten = $objekt !== null;
    $ziel = $bearbeiten
        ? route('portal.objekte.update', ['property' => $objekt->getKey()])
        : route('portal.objekte.store');

    $wert = static fn (string $feld, mixed $vorgabe = null): mixed => old($feld, $vorgabe);

    $legende = 'text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl';
@endphp

@extends('layouts.portal')

@section('titel', $bearbeiten ? 'Objekt bearbeiten' : 'Objekt anlegen')

@section('content')
    <div class="mx-auto max-w-2xl">
        <x-hvm.page-header
            eyebrow="Bestand"
            :title="$bearbeiten ? 'Objekt bearbeiten' : 'Objekt anlegen'"
            lead="Ihre Eingaben werden mit dem Speichern übernommen. Sie können jederzeit unterbrechen und später fortsetzen."
            :back="route('portal.objekte.index')"
            backLabel="Zurück zu den Objekten" />

        <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
            <form method="POST" action="{{ $ziel }}" class="space-y-10 p-6 sm:p-8">
                @csrf
                @if ($bearbeiten)
                    @method('PUT')
                @endif

                {{-- Objekt und Anschrift ------------------------------------------ --}}

                <fieldset>
                    <legend class="{{ $legende }}">Objekt und Anschrift</legend>
                    <div class="mt-6 space-y-6">

                        <x-hvm.field
                            name="label"
                            label="Bezeichnung"
                            hint="Zum Beispiel Rheinpromenade 13 oder Objekt Nord."
                            :required="true"
                            :value="$wert('label', $objekt?->label)" />
    
                        <x-hvm.field
                            name="address_line"
                            label="Straße und Hausnummer"
                            :required="true"
                            :value="$wert('address_line', $objekt?->address_line)" />
    
                        <x-hvm.field
                            name="address_extra"
                            label="Adresszusatz"
                            :optional="true"
                            :value="$wert('address_extra', $objekt?->address_extra)" />
    
                        <div class="grid gap-6 sm:grid-cols-3 sm:items-end">
                            <x-hvm.field
                                name="postal_code"
                                label="Postleitzahl"
                                inputmode="numeric"
                                :required="true"
                                :value="$wert('postal_code', $objekt?->postal_code)" />
                            <x-hvm.field wrapperClass="sm:col-span-2"
                                name="city"
                                label="Ort"
                                :required="true"
                                :value="$wert('city', $objekt?->city)" />
                        </div>
    
                        <x-hvm.field name="kind" label="Objektart" type="select" :required="true">
                            @foreach (PropertyKind::cases() as $art)
                                <option value="{{ $art->value }}"
                                        @selected($wert('kind', $objekt?->kind?->value) === $art->value)>
                                    {{ $art->label() }}
                                </option>
                            @endforeach
                        </x-hvm.field>
    
                        <x-hvm.field
                            name="weg_name"
                            label="Bezeichnung der Eigentümergemeinschaft"
                            hint="Nur ausfüllen, wenn die Angabe aus Ihren Unterlagen hervorgeht."
                            :optional="true"
                            :value="$wert('weg_name', $objekt?->weg_name)" />
                    </div>
                </fieldset>

                {{-- Flaechen und Anteile ------------------------------------------ --}}

                <div class="border-t border-hvm-linie pt-8">
                    <fieldset>
                        <legend class="{{ $legende }}">Flächen und Anteile</legend>
                        <p class="mt-2 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                            Fehlende Werte bleiben leer. Es wird nichts geschätzt. Die Summen werden mit den Einheiten
                            verglichen und als Hinweis ausgegeben.
                        </p>

                        <div class="mt-6 grid gap-6 sm:grid-cols-2 sm:items-end">
                            <x-hvm.field
                                name="total_living_area_sqm"
                                label="Gesamtwohnfläche in Quadratmeter"
                                inputmode="decimal"
                                :value="$wert('total_living_area_sqm', $objekt?->total_living_area_sqm)" />
                            <x-hvm.field
                                name="total_heated_area_sqm"
                                label="Beheizte Gesamtfläche in Quadratmeter"
                                inputmode="decimal"
                                :value="$wert('total_heated_area_sqm', $objekt?->total_heated_area_sqm)" />
                            <x-hvm.field
                                name="mea_denominator"
                                label="Nenner der Miteigentumsanteile"
                                hint="Zum Beispiel 1.000 bei Anteilen wie 87/1.000."
                                inputmode="decimal"
                                :value="$wert('mea_denominator', $objekt?->mea_denominator)" />
                        </div>
                    </fieldset>
                </div>

                {{-- Individuelle Verteilerschluessel ------------------------------ --}}

                <div class="border-t border-hvm-linie pt-8">
                    <fieldset>
                        <legend class="{{ $legende }}">Individuelle Verteilerschlüssel</legend>
                        <p class="mt-2 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                            Sie können bis zu fünf eigene Schlüssel benennen, zum Beispiel Anzahl der Stellplätze. Die Werte
                            je Einheit tragen Sie bei der Einheit ein.
                        </p>

                        <div class="mt-6 grid gap-6 sm:grid-cols-2 sm:items-end">
                            @foreach (range(1, 5) as $nummer)
                                <x-hvm.field
                                    :name="'individual_key_'.$nummer.'_label'"
                                    :label="'Bezeichnung Schlüssel '.$nummer"
                                    :value="$wert('individual_key_'.$nummer.'_label', $objekt?->getAttribute('individual_key_'.$nummer.'_label'))" />
                            @endforeach
                        </div>
                    </fieldset>
                </div>

                <div class="flex flex-wrap gap-3 border-t border-hvm-linie pt-8">
                    <x-hvm.button type="submit" variant="primary" size="lg">Speichern</x-hvm.button>
                    <x-hvm.button href="{{ route('portal.objekte.index') }}" variant="secondary" size="lg">Abbrechen</x-hvm.button>
                </div>
            </form>
        </x-hvm.card>
    </div>
@endsection
