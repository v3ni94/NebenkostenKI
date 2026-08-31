@php
    use App\Enums\OrganizationType;

    $wert = static fn (string $feld, mixed $vorgabe = null): mixed => old($feld, $vorgabe);
@endphp

@extends('layouts.portal')

@section('titel', 'Konto')

@section('content')
    <x-hvm.section-heading
        title="Ihr Konto"
        lead="Hier verwalten Sie Ihre Angaben, Ihre Rechnungsanschrift und Ihre Erinnerungen." />

    @unless ($verifiziert)
        <div class="mt-6">
            <x-hvm.alert variant="warning" label="Fehlt noch" title="E-Mail-Adresse noch nicht bestätigt">
                Zahlung und Download der fertigen Abrechnungen sind erst nach der Bestätigung möglich.
                <a class="font-medium underline underline-offset-2" href="{{ route('verification.notice') }}">
                    Bestätigungslink erneut anfordern
                </a>
            </x-hvm.alert>
        </div>
    @endunless

    {{-- Stammdaten ------------------------------------------------------------- --}}

    <form method="POST" action="{{ route('portal.konto.update') }}" class="mt-8 space-y-6">
        @csrf
        @method('PUT')

        <x-hvm.card title="Name und Kontoart">
            <div class="space-y-5">
                <div>
                    <label for="name" class="block text-sm font-semibold text-hvm-textschwarz">Name</label>
                    <input id="name" name="name" type="text" required
                           value="{{ $wert('name', $benutzer->name) }}"
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    @error('name')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="organization_name" class="block text-sm font-semibold text-hvm-textschwarz">
                            Bezeichnung des Kontos
                        </label>
                        <input id="organization_name" name="organization_name" type="text" required
                               value="{{ $wert('organization_name', $organisation->name) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        @error('organization_name')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="organization_type" class="block text-sm font-semibold text-hvm-textschwarz">Art des Kontos</label>
                        <select id="organization_type" name="organization_type" required
                                class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                            @foreach (OrganizationType::cases() as $art)
                                <option value="{{ $art->value }}"
                                        @selected($wert('organization_type', $organisation->type?->value) === $art->value)>
                                    {{ $art->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </x-hvm.card>

        <x-hvm.card title="Rechnungsanschrift">
            <p class="text-sm text-hvm-anthrazit">
                Diese Angaben erscheinen auf der Rechnung der Hausverwaltung Müller GmbH über die Nutzung des
                Portals. Sie erscheinen nicht auf den Abrechnungen Ihrer Mieter.
            </p>

            <div class="mt-5 space-y-5">
                <div>
                    <label for="billing_name" class="block text-sm font-semibold text-hvm-textschwarz">Rechnungsempfänger</label>
                    <input id="billing_name" name="billing_name" type="text"
                           value="{{ $wert('billing_name', $organisation->billing_name) }}"
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                </div>

                <div>
                    <label for="billing_address_line" class="block text-sm font-semibold text-hvm-textschwarz">
                        Straße und Hausnummer
                    </label>
                    <input id="billing_address_line" name="billing_address_line" type="text"
                           value="{{ $wert('billing_address_line', $organisation->billing_address_line) }}"
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                </div>

                <div>
                    <label for="billing_address_extra" class="block text-sm font-semibold text-hvm-textschwarz">Adresszusatz</label>
                    <input id="billing_address_extra" name="billing_address_extra" type="text"
                           value="{{ $wert('billing_address_extra', $organisation->billing_address_extra) }}"
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                </div>

                <div class="grid gap-5 sm:grid-cols-3">
                    <div>
                        <label for="billing_postal_code" class="block text-sm font-semibold text-hvm-textschwarz">Postleitzahl</label>
                        <input id="billing_postal_code" name="billing_postal_code" type="text" inputmode="numeric"
                               value="{{ $wert('billing_postal_code', $organisation->billing_postal_code) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="billing_city" class="block text-sm font-semibold text-hvm-textschwarz">Ort</label>
                        <input id="billing_city" name="billing_city" type="text"
                               value="{{ $wert('billing_city', $organisation->billing_city) }}"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    </div>
                </div>

                <div>
                    <label for="vat_id" class="block text-sm font-semibold text-hvm-textschwarz">
                        Umsatzsteuer-Identifikationsnummer
                    </label>
                    <input id="vat_id" name="vat_id" type="text"
                           value="{{ $wert('vat_id', $organisation->vat_id) }}"
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    <p class="mt-1 text-sm text-hvm-anthrazit">
                        Freiwillig, nur für Unternehmen. Eine Steuernummer wird nicht erhoben.
                    </p>
                </div>
            </div>
        </x-hvm.card>

        <x-hvm.button type="submit" variant="primary">Angaben speichern</x-hvm.button>
    </form>

    {{-- E-Mail-Adresse --------------------------------------------------------- --}}

    <x-hvm.card class="mt-8" title="E-Mail-Adresse ändern">
        <p class="text-sm text-hvm-anthrazit">
            Nach der Änderung senden wir einen neuen Bestätigungslink an die neue Adresse. Bis zur Bestätigung sind
            Zahlung und Download gesperrt.
        </p>

        <form method="POST" action="{{ route('portal.konto.email') }}" class="mt-5 space-y-5">
            @csrf
            @method('PUT')

            <div>
                <label for="konto-email" class="block text-sm font-semibold text-hvm-textschwarz">Neue E-Mail-Adresse</label>
                <input id="konto-email" name="email" type="email" required autocomplete="email"
                       value="{{ old('email', $benutzer->email) }}"
                       class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                @error('email')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="current_password" class="block text-sm font-semibold text-hvm-textschwarz">Aktuelles Passwort</label>
                <input id="current_password" name="current_password" type="password" required
                       autocomplete="current-password"
                       class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                @error('current_password')<p class="mt-1 text-sm text-status-error">{{ $message }}</p>@enderror
            </div>

            <x-hvm.button type="submit" variant="secondary">E-Mail-Adresse ändern</x-hvm.button>
        </form>
    </x-hvm.card>

    {{-- Erinnerungen ----------------------------------------------------------- --}}

    <x-hvm.card class="mt-8" title="Erinnerungen">
        <p class="text-sm text-hvm-anthrazit">
            Wir erinnern Sie an die Fristen Ihrer Betriebskostenabrechnung. Sie können die Erinnerungen insgesamt
            und je Objekt abschalten.
        </p>

        <form method="POST" action="{{ route('portal.konto.erinnerungen') }}" class="mt-5 space-y-5">
            @csrf
            @method('PUT')

            <div class="flex items-start gap-3">
                <input id="global_active" name="global_active" type="checkbox" value="1"
                       @checked($global->is_active)
                       class="mt-1 h-5 w-5 rounded border-hvm-mittelgrau">
                <label for="global_active" class="text-sm font-semibold text-hvm-textschwarz">
                    Erinnerungen insgesamt aktiv
                </label>
            </div>

            <fieldset class="space-y-3">
                <legend class="text-sm font-semibold text-hvm-textschwarz">Zeitpunkte</legend>

                @foreach ([
                    'q1_enabled' => 'Erstes Quartal',
                    'q2_enabled' => 'Zweites Quartal',
                    'q3_enabled' => 'Drittes Quartal',
                    'december_enabled' => 'Dezember',
                ] as $feld => $beschriftung)
                    <div class="flex items-center gap-3">
                        <input id="{{ $feld }}" name="{{ $feld }}" type="checkbox" value="1"
                               @checked($global->getAttribute($feld))
                               class="h-5 w-5 rounded border-hvm-mittelgrau">
                        <label for="{{ $feld }}" class="text-sm text-hvm-textschwarz">{{ $beschriftung }}</label>
                    </div>
                @endforeach
            </fieldset>

            @if ($objekte !== [])
                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold text-hvm-textschwarz">Erinnerungen je Objekt</legend>

                    @foreach ($objekte as $objekt)
                        <div class="flex items-center gap-3">
                            <input id="objekt-{{ $objekt->getKey() }}" name="objekte[{{ $objekt->getKey() }}]"
                                   type="checkbox" value="1"
                                   @checked($objektErinnerungen[$objekt->getKey()] ?? true)
                                   class="h-5 w-5 rounded border-hvm-mittelgrau">
                            <label for="objekt-{{ $objekt->getKey() }}" class="text-sm text-hvm-textschwarz">
                                {{ $objekt->label }}
                            </label>
                        </div>
                    @endforeach
                </fieldset>
            @endif

            <x-hvm.button type="submit" variant="secondary">Erinnerungen speichern</x-hvm.button>
        </form>
    </x-hvm.card>

    {{-- Sicherheit ------------------------------------------------------------- --}}

    <x-hvm.card class="mt-8" title="Sicherheit">
        <dl class="space-y-3 text-sm">
            <div class="flex flex-wrap justify-between gap-2">
                <dt class="font-semibold text-hvm-textschwarz">Zwei-Faktor-Authentifizierung</dt>
                <dd><x-hvm.badge>{{ $zweiFaktorStatus }}</x-hvm.badge></dd>
            </div>
        </dl>

        <p class="mt-3 text-sm text-hvm-anthrazit">
            Die Anmeldung mit einem zweiten Faktor über eine Authenticator-App wird vorbereitet. Sobald sie
            verfügbar ist, können Sie sie hier aktivieren.
        </p>
    </x-hvm.card>
@endsection
