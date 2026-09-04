@php
    $wert = static fn (string $feld, mixed $vorgabe = null): mixed => old($feld, $vorgabe);
    $bankAnzeigen = (bool) old('show_bank_details_on_statement', $vermieter?->show_bank_details_on_statement ?? false);
@endphp

@extends('layouts.portal')

@section('titel', 'Vermieter bearbeiten')

@section('content')
    <div class="mx-auto max-w-3xl">
        <x-hvm.section-heading
            eyebrow="{{ $objekt->label }}"
            title="Vermieter bearbeiten"
            lead="Der Vermieter ist Absender und Verantwortlicher der Mieterabrechnung. Name und Anschrift erscheinen im Absenderfeld jeder Abrechnung." />

        <form method="POST" action="{{ route('portal.objekte.vermieter.update', ['property' => $objekt->getKey()]) }}" class="mt-8 space-y-6">
            @csrf
            @method('PUT')

            <x-hvm.card title="Absender">
                <div class="space-y-5">
                    <div>
                        <label for="sender_name" class="block text-sm font-semibold text-hvm-textschwarz">Name des Vermieters</label>
                        <input id="sender_name" name="sender_name" type="text" required
                               value="{{ $wert('sender_name', $vermieter?->sender_name) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        <p class="mt-1 text-sm text-hvm-anthrazit">Vor- und Nachname der Person, die als Vermieter auftritt.</p>
                        @error('sender_name')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="company_name" class="block text-sm font-semibold text-hvm-textschwarz">Firma (optional)</label>
                        <input id="company_name" name="company_name" type="text"
                               value="{{ $wert('company_name', $vermieter?->company_name) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        <p class="mt-1 text-sm text-hvm-anthrazit">Nur ausfüllen, wenn eine Gesellschaft vermietet.</p>
                        @error('company_name')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="address_line" class="block text-sm font-semibold text-hvm-textschwarz">Straße und Hausnummer</label>
                        <input id="address_line" name="address_line" type="text" required
                               value="{{ $wert('address_line', $vermieter?->address_line) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        @error('address_line')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="address_extra" class="block text-sm font-semibold text-hvm-textschwarz">Adresszusatz</label>
                        <input id="address_extra" name="address_extra" type="text"
                               value="{{ $wert('address_extra', $vermieter?->address_extra) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    </div>

                    <div class="grid gap-5 sm:grid-cols-3">
                        <div>
                            <label for="postal_code" class="block text-sm font-semibold text-hvm-textschwarz">Postleitzahl</label>
                            <input id="postal_code" name="postal_code" type="text" required inputmode="numeric"
                                   value="{{ $wert('postal_code', $vermieter?->postal_code) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @error('postal_code')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="city" class="block text-sm font-semibold text-hvm-textschwarz">Ort</label>
                            <input id="city" name="city" type="text" required
                                   value="{{ $wert('city', $vermieter?->city) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @error('city')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
            </x-hvm.card>

            <x-hvm.card title="Kontakt (optional)">
                <p class="text-sm text-hvm-anthrazit">
                    Die Angaben erscheinen als Kontaktzeile in der Mieterabrechnung, damit der Mieter Rückfragen
                    und die Belegeinsicht an Sie richten kann.
                </p>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="email" class="block text-sm font-semibold text-hvm-textschwarz">E-Mail-Adresse</label>
                        <input id="email" name="email" type="email"
                               value="{{ $wert('email', $vermieter?->email) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        @error('email')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="phone" class="block text-sm font-semibold text-hvm-textschwarz">Telefon</label>
                        <input id="phone" name="phone" type="text" inputmode="tel"
                               value="{{ $wert('phone', $vermieter?->phone) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        @error('phone')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </x-hvm.card>

            <x-hvm.card title="Bankverbindung (optional)">
                <p class="text-sm text-hvm-anthrazit">
                    IBAN und BIC werden verschlüsselt gespeichert. Sie erscheinen nur dann in der Mieterabrechnung,
                    wenn Sie das unten ausdrücklich wählen.
                </p>
                <div class="mt-5 space-y-5">
                    <div>
                        <label for="account_holder" class="block text-sm font-semibold text-hvm-textschwarz">Kontoinhaber</label>
                        <input id="account_holder" name="account_holder" type="text"
                               value="{{ $wert('account_holder', $vermieter?->account_holder) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        <p class="mt-1 text-sm text-hvm-anthrazit">Leer lassen, wenn der Kontoinhaber der Vermieter selbst ist.</p>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <label for="iban" class="block text-sm font-semibold text-hvm-textschwarz">IBAN</label>
                            <input id="iban" name="iban" type="text" autocomplete="off" spellcheck="false"
                                   value="{{ $wert('iban', $vermieter?->iban) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @error('iban')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="bic" class="block text-sm font-semibold text-hvm-textschwarz">BIC</label>
                            <input id="bic" name="bic" type="text" autocomplete="off" spellcheck="false"
                                   value="{{ $wert('bic', $vermieter?->bic) }}"
                                   class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @error('bic')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <input id="show_bank_details_on_statement" name="show_bank_details_on_statement" type="checkbox" value="1"
                               @checked($bankAnzeigen)
                               class="mt-1 h-5 w-5 rounded border-hvm-mittelgrau">
                        <label for="show_bank_details_on_statement" class="text-sm text-hvm-textschwarz">
                            Bankverbindung in der Abrechnung anzeigen. Der Mieter sieht dann Zahlungsempfänger, IBAN
                            und BIC unter dem Nachzahlungsbetrag.
                        </label>
                    </div>
                </div>
            </x-hvm.card>

            <div class="flex flex-wrap gap-3">
                <x-hvm.button type="submit" variant="primary">Speichern</x-hvm.button>
                <x-hvm.button href="{{ route('portal.objekte.index') }}" variant="secondary">Abbrechen</x-hvm.button>
            </div>
        </form>
    </div>
@endsection
