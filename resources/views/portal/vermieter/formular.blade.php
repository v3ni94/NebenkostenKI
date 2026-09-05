@php
    $wert = static fn (string $feld, mixed $vorgabe = null): mixed => old($feld, $vorgabe);
    $bankAnzeigen = (bool) old('show_bank_details_on_statement', $vermieter?->show_bank_details_on_statement ?? false);

    $legende = 'text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl';
    $erlaeuterung = 'mt-2 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer';
@endphp

@extends('layouts.portal')

@section('titel', 'Vermieter bearbeiten')

@section('content')
    <div class="mx-auto max-w-2xl">
        <x-hvm.page-header
            eyebrow="{{ $objekt->label }}"
            title="Vermieter bearbeiten"
            lead="Der Vermieter ist Absender und Verantwortlicher der Mieterabrechnung. Name und Anschrift erscheinen im Absenderfeld jeder Abrechnung."
            :back="route('portal.objekte.index')"
            backLabel="Zurück zu den Objekten" />

        <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
            <form method="POST" action="{{ route('portal.objekte.vermieter.update', ['property' => $objekt->getKey()]) }}" class="space-y-10 p-6 sm:p-8">
                @csrf
                @method('PUT')

                {{-- Absender ------------------------------------------------------ --}}

                <fieldset>
                    <legend class="{{ $legende }}">Absender</legend>
                    <div class="mt-6 space-y-6">
                        <x-hvm.field
                            name="sender_name"
                            label="Name des Vermieters"
                            hint="Vor- und Nachname der Person, die als Vermieter auftritt."
                            :required="true"
                            :value="$wert('sender_name', $vermieter?->sender_name)" />

                        <x-hvm.field
                            name="company_name"
                            label="Firma (optional)"
                            hint="Nur ausfüllen, wenn eine Gesellschaft vermietet."
                            :value="$wert('company_name', $vermieter?->company_name)" />

                        <x-hvm.field
                            name="address_line"
                            label="Straße und Hausnummer"
                            :required="true"
                            :value="$wert('address_line', $vermieter?->address_line)" />

                        <x-hvm.field
                            name="address_extra"
                            label="Adresszusatz"
                            :optional="true"
                            :value="$wert('address_extra', $vermieter?->address_extra)" />

                        <div class="grid gap-6 sm:grid-cols-3 sm:items-end">
                            <x-hvm.field
                                name="postal_code"
                                label="Postleitzahl"
                                inputmode="numeric"
                                :required="true"
                                :value="$wert('postal_code', $vermieter?->postal_code)" />
                            <x-hvm.field wrapperClass="sm:col-span-2"
                                name="city"
                                label="Ort"
                                :required="true"
                                :value="$wert('city', $vermieter?->city)" />
                        </div>
                    </div>
                </fieldset>

                {{-- Kontakt ------------------------------------------------------- --}}

                <div class="border-t border-hvm-linie pt-8">
                    <fieldset>
                        <legend class="{{ $legende }}">Kontakt (optional)</legend>
                        <p class="{{ $erlaeuterung }}">
                            Die Angaben erscheinen als Kontaktzeile in der Mieterabrechnung, damit der Mieter Rückfragen
                            und die Belegeinsicht an Sie richten kann.
                        </p>

                        <div class="mt-6 grid gap-6 sm:grid-cols-2 sm:items-end">
                            <x-hvm.field
                                name="email"
                                label="E-Mail-Adresse"
                                type="email"
                                :value="$wert('email', $vermieter?->email)" />
                            <x-hvm.field
                                name="phone"
                                label="Telefon"
                                inputmode="tel"
                                :value="$wert('phone', $vermieter?->phone)" />
                        </div>
                    </fieldset>
                </div>

                {{-- Bankverbindung ------------------------------------------------ --}}

                <div class="border-t border-hvm-linie pt-8">
                    <fieldset>
                        <legend class="{{ $legende }}">Bankverbindung (optional)</legend>
                        <p class="{{ $erlaeuterung }}">
                            IBAN und BIC werden verschlüsselt gespeichert. Sie erscheinen nur dann in der Mieterabrechnung,
                            wenn Sie das unten ausdrücklich wählen.
                        </p>

                        <div class="mt-6 space-y-6">
                            <x-hvm.field
                                name="account_holder"
                                label="Kontoinhaber"
                                hint="Leer lassen, wenn der Kontoinhaber der Vermieter selbst ist."
                                :value="$wert('account_holder', $vermieter?->account_holder)" />

                            <div class="grid gap-6 sm:grid-cols-3 sm:items-end">
                                <x-hvm.field wrapperClass="sm:col-span-2"
                                    name="iban"
                                    label="IBAN"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :value="$wert('iban', $vermieter?->iban)" />
                                <x-hvm.field
                                    name="bic"
                                    label="BIC"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :value="$wert('bic', $vermieter?->bic)" />
                            </div>

                            <x-hvm.field
                                name="show_bank_details_on_statement"
                                label="Bankverbindung in der Abrechnung anzeigen. Der Mieter sieht dann Zahlungsempfänger, IBAN und BIC unter dem Nachzahlungsbetrag."
                                type="checkbox"
                                value="1"
                                :checked="$bankAnzeigen" />
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
