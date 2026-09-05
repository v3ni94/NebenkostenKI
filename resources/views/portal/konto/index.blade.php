{{--
    Konto: Stammdaten, Rechnungsanschrift, E-Mail-Adresse, Erinnerungen und
    Sicherheit.

    Gestaltung nach docs/designsystem.md: Seitenkopf (4.3), Formularkarten mit
    x-hvm.field (4.6), Meldungen (4.14), ein Primaerbutton (4.12). Feld-IDs,
    Namen, Typen und autocomplete sind unveraendert.
--}}
@php
    use App\Enums\OrganizationType;

    $wert = static fn (string $feld, mixed $vorgabe = null): mixed => old($feld, $vorgabe);
@endphp

@extends('layouts.portal')

@section('titel', 'Konto')

@section('content')
    <x-hvm.page-header
        eyebrow="Konto"
        title="Ihr Konto"
        lead="Hier verwalten Sie Ihre Angaben, Ihre Rechnungsanschrift und Ihre Erinnerungen." />

    @if ($zustellhinweis !== null)
        <div class="mt-8">
            <x-hvm.alert variant="warning" label="Zustellung" title="Hinweis zu Ihrer E-Mail-Adresse">
                {{ $zustellhinweis }}
                Nach einer Adressänderung bestätigen Sie die neue Adresse über den zugesandten Link. Erinnerungen
                aktivieren Sie anschließend im Abschnitt Erinnerungen wieder.
            </x-hvm.alert>
        </div>
    @endif

    @unless ($verifiziert)
        <div class="mt-8">
            <x-hvm.alert variant="warning" label="Fehlt noch" title="E-Mail-Adresse noch nicht bestätigt">
                Zahlung und Download der fertigen Abrechnungen sind erst nach der Bestätigung möglich.
                <a class="font-medium underline underline-offset-4" href="{{ route('verification.notice') }}">
                    Bestätigungslink erneut anfordern
                </a>
            </x-hvm.alert>
        </div>
    @endunless

    {{-- Stammdaten ------------------------------------------------------------- --}}

    <section class="mt-10" aria-labelledby="ueberschrift-stammdaten">
        <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Stammdaten</p>
        <h2 id="ueberschrift-stammdaten" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Angaben und Rechnungsanschrift</h2>

        <form method="POST" action="{{ route('portal.konto.update') }}" class="mt-6 max-w-2xl space-y-6">
            @csrf
            @method('PUT')

            <x-hvm.card title="Name und Kontoart">
                <div class="space-y-6">
                    <x-hvm.field name="name" label="Name" type="text" :required="true"
                                 :value="$wert('name', $benutzer->name)" />

                    <div class="grid gap-6 sm:grid-cols-2">
                        <x-hvm.field name="organization_name" label="Bezeichnung des Kontos" type="text" :required="true"
                                     :value="$wert('organization_name', $organisation->name)" />

                        <x-hvm.field name="organization_type" label="Art des Kontos" type="select" :required="true">
                            @foreach (OrganizationType::cases() as $art)
                                <option value="{{ $art->value }}"
                                        @selected($wert('organization_type', $organisation->type?->value) === $art->value)>
                                    {{ $art->label() }}
                                </option>
                            @endforeach
                        </x-hvm.field>
                    </div>
                </div>
            </x-hvm.card>

            <x-hvm.card title="Rechnungsanschrift">
                <p class="max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                    Diese Angaben erscheinen auf der Rechnung der Hausverwaltung Müller GmbH über die Nutzung des
                    Portals. Sie erscheinen nicht auf den Abrechnungen Ihrer Mieter.
                </p>

                <div class="mt-6 space-y-6">
                    <x-hvm.field name="billing_name" label="Rechnungsempfänger" type="text"
                                 :value="$wert('billing_name', $organisation->billing_name)" />

                    <x-hvm.field name="billing_address_line" label="Straße und Hausnummer" type="text"
                                 :value="$wert('billing_address_line', $organisation->billing_address_line)" />

                    <x-hvm.field name="billing_address_extra" label="Adresszusatz" type="text"
                                 :value="$wert('billing_address_extra', $organisation->billing_address_extra)" />

                    <div class="grid gap-6 sm:grid-cols-3">
                        <x-hvm.field name="billing_postal_code" label="Postleitzahl" type="text" inputmode="numeric"
                                     :value="$wert('billing_postal_code', $organisation->billing_postal_code)" />

                        <div class="min-w-0 sm:col-span-2">
                            <x-hvm.field name="billing_city" label="Ort" type="text"
                                         :value="$wert('billing_city', $organisation->billing_city)" />
                        </div>
                    </div>

                    <x-hvm.field name="vat_id" label="Umsatzsteuer-Identifikationsnummer" type="text"
                                 hint="Freiwillig, nur für Unternehmen. Eine Steuernummer wird nicht erhoben."
                                 :value="$wert('vat_id', $organisation->vat_id)" />
                </div>
            </x-hvm.card>

            <div class="flex flex-wrap gap-3">
                <x-hvm.button type="submit" variant="primary" size="lg">Angaben speichern</x-hvm.button>
            </div>
        </form>
    </section>

    {{-- E-Mail-Adresse --------------------------------------------------------- --}}

    <section class="mt-16" aria-labelledby="ueberschrift-email">
        <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Zugang</p>
        <h2 id="ueberschrift-email" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">E-Mail-Adresse ändern</h2>

        <x-hvm.card class="mt-6 max-w-2xl">
            <p class="max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                Nach der Änderung senden wir einen neuen Bestätigungslink an die neue Adresse. Bis zur Bestätigung sind
                Zahlung und Download gesperrt.
            </p>

            <form method="POST" action="{{ route('portal.konto.email') }}" class="mt-6 space-y-6">
                @csrf
                @method('PUT')

                <x-hvm.field id="konto-email" name="email" label="Neue E-Mail-Adresse" type="email"
                             autocomplete="email" :required="true"
                             :value="old('email', $benutzer->email)" />

                <x-hvm.field name="current_password" label="Aktuelles Passwort" type="password"
                             autocomplete="current-password" :required="true" />

                <div class="flex flex-wrap gap-3">
                    <x-hvm.button type="submit" variant="secondary">E-Mail-Adresse ändern</x-hvm.button>
                </div>
            </form>
        </x-hvm.card>
    </section>

    {{-- Erinnerungen ----------------------------------------------------------- --}}

    <section class="mt-16" aria-labelledby="ueberschrift-erinnerungen">
        <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Fristen</p>
        <h2 id="ueberschrift-erinnerungen" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Erinnerungen</h2>

        <x-hvm.card class="mt-6 max-w-2xl">
            <p class="max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                Wir erinnern Sie an die Fristen Ihrer Betriebskostenabrechnung. Sie können die Erinnerungen insgesamt
                und je Objekt abschalten.
            </p>

            <form method="POST" action="{{ route('portal.konto.erinnerungen') }}" class="mt-6 space-y-6">
                @csrf
                @method('PUT')

                <x-hvm.field name="global_active" label="Erinnerungen insgesamt aktiv" type="checkbox" value="1"
                             :checked="(bool) $global->is_active" />

                <fieldset class="space-y-1">
                    <legend class="text-sm font-semibold text-hvm-textschwarz">Zeitpunkte</legend>

                    <div class="mt-2 flex flex-col gap-1 sm:flex-row sm:flex-wrap sm:gap-x-6">
                        @foreach ([
                            'q1_enabled' => 'Erstes Quartal',
                            'q2_enabled' => 'Zweites Quartal',
                            'q3_enabled' => 'Drittes Quartal',
                            'december_enabled' => 'Dezember',
                        ] as $feld => $beschriftung)
                            <x-hvm.field :name="$feld" :label="$beschriftung" type="checkbox" value="1"
                                         :checked="(bool) $global->getAttribute($feld)" />
                        @endforeach
                    </div>
                </fieldset>

                @if ($objekte !== [])
                    <fieldset class="space-y-1">
                        <legend class="text-sm font-semibold text-hvm-textschwarz">Erinnerungen je Objekt</legend>

                        <div class="mt-2 flex flex-col gap-1">
                            @foreach ($objekte as $objekt)
                                <x-hvm.field :id="'objekt-'.$objekt->getKey()"
                                             :name="'objekte['.$objekt->getKey().']'"
                                             :label="$objekt->label"
                                             type="checkbox" value="1"
                                             :checked="(bool) ($objektErinnerungen[$objekt->getKey()] ?? true)" />
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <div class="flex flex-wrap gap-3">
                    <x-hvm.button type="submit" variant="secondary">Erinnerungen speichern</x-hvm.button>
                </div>
            </form>
        </x-hvm.card>
    </section>

    {{-- Sicherheit ------------------------------------------------------------- --}}

    <section class="mt-16" aria-labelledby="ueberschrift-sicherheit">
        <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Schutz des Kontos</p>
        <h2 id="ueberschrift-sicherheit" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Sicherheit</h2>

        <x-hvm.card class="mt-6 max-w-2xl" tone="canvas">
            <div class="flex gap-4">
                <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
                    <x-hvm.icon name="shield" class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <dl class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <dt class="font-semibold text-hvm-textschwarz">Zwei-Faktor-Authentifizierung</dt>
                        <dd><x-hvm.badge variant="neutral" icon="lock">{{ $zweiFaktorStatus }}</x-hvm.badge></dd>
                    </dl>

                    <p class="mt-3 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                        Mit einem zweiten Faktor verlangen wir bei jeder Anmeldung zusätzlich einen sechsstelligen Code aus
                        Ihrer Authenticator-App. Für Kundenkonten ist das freiwillig, für interne Kennungen verpflichtend.
                    </p>

                    <div class="mt-5">
                        <x-hvm.button href="{{ route('two-factor.setup') }}" variant="secondary">
                            Zwei-Faktor-Authentifizierung verwalten
                            <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                        </x-hvm.button>
                    </div>
                </div>
            </div>
        </x-hvm.card>
    </section>
@endsection
