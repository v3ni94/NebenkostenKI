@php
    use App\Enums\PropertyKind;

    $bearbeiten = $objekt !== null;
    $ziel = $bearbeiten
        ? route('portal.objekte.update', ['property' => $objekt->getKey()])
        : route('portal.objekte.store');

    $wert = static fn (string $feld, mixed $vorgabe = null): mixed => old($feld, $vorgabe);
@endphp

@extends('layouts.portal')

@section('titel', $bearbeiten ? 'Objekt bearbeiten' : 'Objekt anlegen')

@section('content')
    <div class="mx-auto max-w-3xl">
        <x-hvm.section-heading
            :title="$bearbeiten ? 'Objekt bearbeiten' : 'Objekt anlegen'"
            lead="Ihre Eingaben werden mit dem Speichern übernommen. Sie können jederzeit unterbrechen und später fortsetzen." />

        <form method="POST" action="{{ $ziel }}" class="mt-8 space-y-6">
            @csrf
            @if ($bearbeiten)
                @method('PUT')
            @endif

            <x-hvm.card title="Objekt und Anschrift">
                <div class="space-y-5">
                    <div>
                        <label for="label" class="block text-sm font-semibold text-hvm-textschwarz">Bezeichnung</label>
                        <input id="label" name="label" type="text" required
                               value="{{ $wert('label', $objekt?->label) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        <p class="mt-1 text-sm text-hvm-anthrazit">Zum Beispiel Rheinpromenade 13 oder Objekt Nord.</p>
                        @error('label')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="address_line" class="block text-sm font-semibold text-hvm-textschwarz">
                            Straße und Hausnummer
                        </label>
                        <input id="address_line" name="address_line" type="text" required
                               value="{{ $wert('address_line', $objekt?->address_line) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        @error('address_line')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="address_extra" class="block text-sm font-semibold text-hvm-textschwarz">Adresszusatz</label>
                        <input id="address_extra" name="address_extra" type="text"
                               value="{{ $wert('address_extra', $objekt?->address_extra) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    </div>

                    <div class="grid gap-5 sm:grid-cols-3">
                        <div>
                            <label for="postal_code" class="block text-sm font-semibold text-hvm-textschwarz">Postleitzahl</label>
                            <input id="postal_code" name="postal_code" type="text" required inputmode="numeric"
                                   value="{{ $wert('postal_code', $objekt?->postal_code) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @error('postal_code')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="city" class="block text-sm font-semibold text-hvm-textschwarz">Ort</label>
                            <input id="city" name="city" type="text" required
                                   value="{{ $wert('city', $objekt?->city) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @error('city')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <label for="kind" class="block text-sm font-semibold text-hvm-textschwarz">Objektart</label>
                        <select id="kind" name="kind" required
                                class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @foreach (PropertyKind::cases() as $art)
                                <option value="{{ $art->value }}"
                                        @selected($wert('kind', $objekt?->kind?->value) === $art->value)>
                                    {{ $art->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('kind')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="weg_name" class="block text-sm font-semibold text-hvm-textschwarz">
                            Bezeichnung der Eigentümergemeinschaft
                        </label>
                        <input id="weg_name" name="weg_name" type="text"
                               value="{{ $wert('weg_name', $objekt?->weg_name) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        <p class="mt-1 text-sm text-hvm-anthrazit">Nur ausfüllen, wenn die Angabe aus Ihren Unterlagen hervorgeht.</p>
                    </div>
                </div>
            </x-hvm.card>

            <x-hvm.card title="Flächen und Anteile">
                <p class="text-sm text-hvm-anthrazit">
                    Fehlende Werte bleiben leer. Es wird nichts geschätzt. Die Summen werden mit den Einheiten
                    verglichen und als Hinweis ausgegeben.
                </p>

                <div class="mt-5 grid gap-5 sm:grid-cols-3">
                    <div>
                        <label for="total_living_area_sqm" class="block text-sm font-semibold text-hvm-textschwarz">
                            Gesamtwohnfläche in Quadratmeter
                        </label>
                        <input id="total_living_area_sqm" name="total_living_area_sqm" type="text" inputmode="decimal"
                               value="{{ $wert('total_living_area_sqm', $objekt?->total_living_area_sqm) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        @error('total_living_area_sqm')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="total_heated_area_sqm" class="block text-sm font-semibold text-hvm-textschwarz">
                            Beheizte Gesamtfläche in Quadratmeter
                        </label>
                        <input id="total_heated_area_sqm" name="total_heated_area_sqm" type="text" inputmode="decimal"
                               value="{{ $wert('total_heated_area_sqm', $objekt?->total_heated_area_sqm) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    </div>
                    <div>
                        <label for="mea_denominator" class="block text-sm font-semibold text-hvm-textschwarz">
                            Nenner der Miteigentumsanteile
                        </label>
                        <input id="mea_denominator" name="mea_denominator" type="text" inputmode="decimal"
                               value="{{ $wert('mea_denominator', $objekt?->mea_denominator) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        <p class="mt-1 text-sm text-hvm-anthrazit">Zum Beispiel 1.000 bei Anteilen wie 87/1.000.</p>
                    </div>
                </div>
            </x-hvm.card>

            <x-hvm.card title="Individuelle Verteilerschlüssel">
                <p class="text-sm text-hvm-anthrazit">
                    Sie können bis zu fünf eigene Schlüssel benennen, zum Beispiel Anzahl der Stellplätze. Die Werte
                    je Einheit tragen Sie bei der Einheit ein.
                </p>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    @foreach (range(1, 5) as $nummer)
                        <div>
                            <label for="individual_key_{{ $nummer }}_label" class="block text-sm font-semibold text-hvm-textschwarz">
                                Bezeichnung Schlüssel {{ $nummer }}
                            </label>
                            <input id="individual_key_{{ $nummer }}_label" name="individual_key_{{ $nummer }}_label" type="text"
                                   value="{{ $wert('individual_key_'.$nummer.'_label', $objekt?->getAttribute('individual_key_'.$nummer.'_label')) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        </div>
                    @endforeach
                </div>
            </x-hvm.card>

            <div class="flex flex-wrap gap-3">
                <x-hvm.button type="submit" variant="primary">Speichern</x-hvm.button>
                <x-hvm.button href="{{ route('portal.objekte.index') }}" variant="secondary">Abbrechen</x-hvm.button>
            </div>
        </form>
    </div>
@endsection
