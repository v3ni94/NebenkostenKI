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

    $legende = 'text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl';
    $erlaeuterung = 'mt-2 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer';
@endphp

@extends('layouts.portal')

@section('titel', $bearbeiten ? 'Mietverhältnis bearbeiten' : 'Mietverhältnis anlegen')

@section('content')
    <div class="mx-auto max-w-2xl">
        <x-hvm.page-header
            eyebrow="{{ $objekt->label }}, Einheit {{ $einheit->label }}"
            :title="$bearbeiten ? 'Mietverhältnis bearbeiten' : 'Mietverhältnis anlegen'"
            lead="Für eine Einheit darf zu jedem Tag nur ein Mietverhältnis oder ein Leerstand gelten."
            :back="route('portal.mietverhaeltnisse.index', ['unit' => $einheit->getKey()])"
            backLabel="Zurück zu den Mietverhältnissen" />

        <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
            <form method="POST" action="{{ $ziel }}" class="space-y-10 p-6 sm:p-8">
                @csrf
                @if ($bearbeiten)
                    @method('PUT')
                @endif

                {{-- Mieter und Zeitraum ------------------------------------------- --}}

                <fieldset>
                    <legend class="{{ $legende }}">Mieter und Zeitraum</legend>
                    <div class="mt-6 space-y-6">
                        <x-hvm.field
                            name="tenant_display_name"
                            label="Name des Mieters"
                            hint="So erscheint der Name im Anschriftfeld der Abrechnung."
                            :required="true"
                            :value="$wert('tenant_display_name', $mietverhaeltnis?->tenant_display_name)" />

                        <x-hvm.field
                            name="kind"
                            label="Art des Mietverhältnisses"
                            type="select"
                            hint="Bei Gewerbe erfolgt keine automatische Finalisierung. Umlagevereinbarung und umsatzsteuerliche Behandlung sind gesondert zu prüfen."
                            :required="true">
                            @foreach (TenancyKind::cases() as $art)
                                <option value="{{ $art->value }}"
                                        @selected($wert('kind', $mietverhaeltnis?->kind?->value) === $art->value)>
                                    {{ $art->label() }}
                                </option>
                            @endforeach
                        </x-hvm.field>

                        <div class="grid gap-6 sm:grid-cols-2">
                            <x-hvm.field
                                name="starts_on"
                                label="Einzug"
                                type="date"
                                :required="true"
                                :value="$wert('starts_on', $mietverhaeltnis?->starts_on?->toDateString())" />
                            <x-hvm.field
                                name="ends_on"
                                label="Auszug"
                                type="date"
                                hint="Leer lassen, solange das Mietverhältnis läuft."
                                :value="$wert('ends_on', $mietverhaeltnis?->ends_on?->toDateString())" />
                        </div>
                    </div>
                </fieldset>

                {{-- Zustellanschrift ---------------------------------------------- --}}

                <div class="border-t border-hvm-linie pt-8">
                    <fieldset>
                        <legend class="{{ $legende }}">Zustellanschrift</legend>
                        <p class="{{ $erlaeuterung }}">
                            Bei einem beendeten Mietverhältnis ist die Zustellanschrift erforderlich. Ohne sie kann die
                            Abrechnung nicht zugestellt werden.
                        </p>

                        <div class="mt-6 space-y-6">
                            <x-hvm.field
                                name="delivery_address_line"
                                label="Straße und Hausnummer"
                                :value="$wert('delivery_address_line', $mietverhaeltnis?->delivery_address_line)" />

                            <x-hvm.field
                                name="delivery_address_extra"
                                label="Adresszusatz"
                                :optional="true"
                                :value="$wert('delivery_address_extra', $mietverhaeltnis?->delivery_address_extra)" />

                            <div class="grid gap-6 sm:grid-cols-3">
                                <x-hvm.field
                                    name="delivery_postal_code"
                                    label="Postleitzahl"
                                    inputmode="numeric"
                                    :value="$wert('delivery_postal_code', $mietverhaeltnis?->delivery_postal_code)" />
                                <x-hvm.field wrapperClass="sm:col-span-2"
                                    name="delivery_city"
                                    label="Ort"
                                    :value="$wert('delivery_city', $mietverhaeltnis?->delivery_city)" />
                            </div>
                        </div>
                    </fieldset>
                </div>

                {{-- Vereinbarte Vorauszahlungen ----------------------------------- --}}

                <div class="border-t border-hvm-linie pt-8">
                    <fieldset>
                        <legend class="{{ $legende }}">Vereinbarte Vorauszahlungen</legend>
                        <p class="{{ $erlaeuterung }}">
                            Die vertraglich vereinbarten Monatsbeträge. Die tatsächlich geleisteten Zahlungen erfassen Sie
                            später im Abrechnungslauf. Beträge in Euro, zum Beispiel 120,50.
                        </p>

                        <div class="mt-6 grid gap-6 sm:grid-cols-2">
                            <x-hvm.field
                                name="monthly_operating_prepayment_eur"
                                label="Betriebskosten je Monat in EUR"
                                inputmode="decimal"
                                :value="$wert('monthly_operating_prepayment_eur', $euro($mietverhaeltnis?->monthly_operating_prepayment_cent))" />
                            <x-hvm.field
                                name="monthly_heating_prepayment_eur"
                                label="Heizkosten je Monat in EUR"
                                inputmode="decimal"
                                :value="$wert('monthly_heating_prepayment_eur', $euro($mietverhaeltnis?->monthly_heating_prepayment_cent))" />
                        </div>

                        <div class="mt-4">
                            <x-hvm.field
                                name="heating_prepayment_separate"
                                label="Heizkostenvorauszahlung ist getrennt vereinbart."
                                type="checkbox"
                                value="1"
                                :checked="(bool) $wert('heating_prepayment_separate', $mietverhaeltnis?->heating_prepayment_separate)" />
                        </div>
                    </fieldset>
                </div>

                {{-- Vertragsgrundlagen -------------------------------------------- --}}

                <div class="border-t border-hvm-linie pt-8">
                    <fieldset>
                        <legend class="{{ $legende }}">Vertragsgrundlagen</legend>
                        <p class="{{ $erlaeuterung }}">
                            Ohne Angabe bleibt der Wert unbekannt und erzeugt später eine Prüfaufgabe. Es wird keine
                            Vereinbarung unterstellt.
                        </p>

                        <div class="mt-6 grid gap-6 sm:grid-cols-2">
                            <x-hvm.field name="operating_costs_apportionment_agreed" label="Umlage der Betriebskosten vereinbart" type="select">
                                <option value="">Unbekannt</option>
                                <option value="1" @selected($wert('operating_costs_apportionment_agreed', $mietverhaeltnis?->operating_costs_apportionment_agreed) === true)>Ja</option>
                                <option value="0" @selected($wert('operating_costs_apportionment_agreed', $mietverhaeltnis?->operating_costs_apportionment_agreed) === false)>Nein</option>
                            </x-hvm.field>

                            <x-hvm.field name="other_operating_costs_agreed" label="Sonstige Betriebskosten vereinbart" type="select">
                                <option value="">Unbekannt</option>
                                <option value="1" @selected($wert('other_operating_costs_agreed', $mietverhaeltnis?->other_operating_costs_agreed) === true)>Ja</option>
                                <option value="0" @selected($wert('other_operating_costs_agreed', $mietverhaeltnis?->other_operating_costs_agreed) === false)>Nein</option>
                            </x-hvm.field>
                        </div>
                    </fieldset>
                </div>

                <div class="flex flex-wrap gap-3 border-t border-hvm-linie pt-8">
                    <x-hvm.button type="submit" variant="primary" size="lg">Speichern</x-hvm.button>
                    <x-hvm.button href="{{ route('portal.mietverhaeltnisse.index', ['unit' => $einheit->getKey()]) }}"
                                  variant="secondary" size="lg">Abbrechen</x-hvm.button>
                </div>
            </form>
        </x-hvm.card>
    </div>
@endsection
