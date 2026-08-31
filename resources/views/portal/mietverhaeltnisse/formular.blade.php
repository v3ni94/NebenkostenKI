@php
    use App\Enums\TenancyKind;

    $bearbeiten = $mietverhaeltnis !== null;
    $ziel = $bearbeiten
        ? route('portal.mietverhaeltnisse.update', ['tenancy' => $mietverhaeltnis->getKey()])
        : route('portal.mietverhaeltnisse.store', ['unit' => $einheit->getKey()]);

    $wert = static fn (string $feld, mixed $vorgabe = null): mixed => old($feld, $vorgabe);

    $euro = static function (mixed $cent): string {
        if (! is_int($cent)) {
            return '';
        }

        return number_format($cent / 100, 2, ',', '.');
    };
@endphp

@extends('layouts.portal')

@section('titel', $bearbeiten ? 'Mietverhältnis bearbeiten' : 'Mietverhältnis anlegen')

@section('content')
    <div class="mx-auto max-w-3xl">
        <x-hvm.section-heading
            eyebrow="{{ $objekt->label }}, Einheit {{ $einheit->label }}"
            :title="$bearbeiten ? 'Mietverhältnis bearbeiten' : 'Mietverhältnis anlegen'"
            lead="Für eine Einheit darf zu jedem Tag nur ein Mietverhältnis oder ein Leerstand gelten." />

        <form method="POST" action="{{ $ziel }}" class="mt-8 space-y-6">
            @csrf
            @if ($bearbeiten)
                @method('PUT')
            @endif

            <x-hvm.card title="Mieter und Zeitraum">
                <div class="space-y-5">
                    <div>
                        <label for="tenant_display_name" class="block text-sm font-semibold text-hvm-textschwarz">
                            Name des Mieters
                        </label>
                        <input id="tenant_display_name" name="tenant_display_name" type="text" required
                               value="{{ $wert('tenant_display_name', $mietverhaeltnis?->tenant_display_name) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        <p class="mt-1 text-sm text-hvm-anthrazit">So erscheint der Name im Anschriftfeld der Abrechnung.</p>
                        @error('tenant_display_name')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="kind" class="block text-sm font-semibold text-hvm-textschwarz">Art des Mietverhältnisses</label>
                        <select id="kind" name="kind" required
                                class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @foreach (TenancyKind::cases() as $art)
                                <option value="{{ $art->value }}"
                                        @selected($wert('kind', $mietverhaeltnis?->kind?->value) === $art->value)>
                                    {{ $art->label() }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-sm text-hvm-anthrazit">
                            Bei Gewerbe erfolgt keine automatische Finalisierung. Umlagevereinbarung und
                            umsatzsteuerliche Behandlung sind gesondert zu prüfen.
                        </p>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="starts_on" class="block text-sm font-semibold text-hvm-textschwarz">Einzug</label>
                            <input id="starts_on" name="starts_on" type="date" required
                                   value="{{ $wert('starts_on', $mietverhaeltnis?->starts_on?->toDateString()) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @error('starts_on')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="ends_on" class="block text-sm font-semibold text-hvm-textschwarz">Auszug</label>
                            <input id="ends_on" name="ends_on" type="date"
                                   value="{{ $wert('ends_on', $mietverhaeltnis?->ends_on?->toDateString()) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            <p class="mt-1 text-sm text-hvm-anthrazit">Leer lassen, solange das Mietverhältnis läuft.</p>
                            @error('ends_on')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
            </x-hvm.card>

            <x-hvm.card title="Zustellanschrift">
                <p class="text-sm text-hvm-anthrazit">
                    Bei einem beendeten Mietverhältnis ist die Zustellanschrift erforderlich. Ohne sie kann die
                    Abrechnung nicht zugestellt werden.
                </p>

                <div class="mt-5 space-y-5">
                    <div>
                        <label for="delivery_address_line" class="block text-sm font-semibold text-hvm-textschwarz">
                            Straße und Hausnummer
                        </label>
                        <input id="delivery_address_line" name="delivery_address_line" type="text"
                               value="{{ $wert('delivery_address_line', $mietverhaeltnis?->delivery_address_line) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        @error('delivery_address_line')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="delivery_address_extra" class="block text-sm font-semibold text-hvm-textschwarz">Adresszusatz</label>
                        <input id="delivery_address_extra" name="delivery_address_extra" type="text"
                               value="{{ $wert('delivery_address_extra', $mietverhaeltnis?->delivery_address_extra) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    </div>

                    <div class="grid gap-5 sm:grid-cols-3">
                        <div>
                            <label for="delivery_postal_code" class="block text-sm font-semibold text-hvm-textschwarz">Postleitzahl</label>
                            <input id="delivery_postal_code" name="delivery_postal_code" type="text" inputmode="numeric"
                                   value="{{ $wert('delivery_postal_code', $mietverhaeltnis?->delivery_postal_code) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @error('delivery_postal_code')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="delivery_city" class="block text-sm font-semibold text-hvm-textschwarz">Ort</label>
                            <input id="delivery_city" name="delivery_city" type="text"
                                   value="{{ $wert('delivery_city', $mietverhaeltnis?->delivery_city) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @error('delivery_city')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
            </x-hvm.card>

            <x-hvm.card title="Vereinbarte Vorauszahlungen">
                <p class="text-sm text-hvm-anthrazit">
                    Die vertraglich vereinbarten Monatsbeträge. Die tatsächlich geleisteten Zahlungen erfassen Sie
                    später im Abrechnungslauf. Beträge in Euro, zum Beispiel 120,50.
                </p>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="monthly_operating_prepayment_eur" class="block text-sm font-semibold text-hvm-textschwarz">
                            Betriebskosten je Monat in EUR
                        </label>
                        <input id="monthly_operating_prepayment_eur" name="monthly_operating_prepayment_eur"
                               type="text" inputmode="decimal"
                               value="{{ $wert('monthly_operating_prepayment_eur', $euro($mietverhaeltnis?->monthly_operating_prepayment_cent)) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    </div>
                    <div>
                        <label for="monthly_heating_prepayment_eur" class="block text-sm font-semibold text-hvm-textschwarz">
                            Heizkosten je Monat in EUR
                        </label>
                        <input id="monthly_heating_prepayment_eur" name="monthly_heating_prepayment_eur"
                               type="text" inputmode="decimal"
                               value="{{ $wert('monthly_heating_prepayment_eur', $euro($mietverhaeltnis?->monthly_heating_prepayment_cent)) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    </div>
                </div>

                <div class="mt-4 flex items-start gap-3">
                    <input id="heating_prepayment_separate" name="heating_prepayment_separate" type="checkbox" value="1"
                           @checked($wert('heating_prepayment_separate', $mietverhaeltnis?->heating_prepayment_separate))
                           class="mt-1 h-5 w-5 rounded border-hvm-mittelgrau">
                    <label for="heating_prepayment_separate" class="text-sm text-hvm-textschwarz">
                        Heizkostenvorauszahlung ist getrennt vereinbart.
                    </label>
                </div>
            </x-hvm.card>

            <x-hvm.card title="Vertragsgrundlagen">
                <p class="text-sm text-hvm-anthrazit">
                    Ohne Angabe bleibt der Wert unbekannt und erzeugt später eine Prüfaufgabe. Es wird keine
                    Vereinbarung unterstellt.
                </p>

                <div class="mt-5 space-y-5">
                    <div>
                        <label for="operating_costs_apportionment_agreed" class="block text-sm font-semibold text-hvm-textschwarz">
                            Umlage der Betriebskosten vereinbart
                        </label>
                        <select id="operating_costs_apportionment_agreed" name="operating_costs_apportionment_agreed"
                                class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            <option value="">Unbekannt</option>
                            <option value="1" @selected($wert('operating_costs_apportionment_agreed', $mietverhaeltnis?->operating_costs_apportionment_agreed) === true)>Ja</option>
                            <option value="0" @selected($wert('operating_costs_apportionment_agreed', $mietverhaeltnis?->operating_costs_apportionment_agreed) === false)>Nein</option>
                        </select>
                    </div>

                    <div>
                        <label for="other_operating_costs_agreed" class="block text-sm font-semibold text-hvm-textschwarz">
                            Sonstige Betriebskosten vereinbart
                        </label>
                        <select id="other_operating_costs_agreed" name="other_operating_costs_agreed"
                                class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            <option value="">Unbekannt</option>
                            <option value="1" @selected($wert('other_operating_costs_agreed', $mietverhaeltnis?->other_operating_costs_agreed) === true)>Ja</option>
                            <option value="0" @selected($wert('other_operating_costs_agreed', $mietverhaeltnis?->other_operating_costs_agreed) === false)>Nein</option>
                        </select>
                    </div>
                </div>
            </x-hvm.card>

            <div class="flex flex-wrap gap-3">
                <x-hvm.button type="submit" variant="primary">Speichern</x-hvm.button>
                <x-hvm.button href="{{ route('portal.mietverhaeltnisse.index', ['unit' => $einheit->getKey()]) }}"
                              variant="secondary">Abbrechen</x-hvm.button>
            </div>
        </form>
    </div>
@endsection
