{{--
    Einrichtung und Abschaltung des Zweitfaktors.

    Es wird bewusst KEIN QR-Code-Bild erzeugt, weil dafuer eine zusaetzliche
    Abhaengigkeit noetig waere. Angeboten werden die otpauth-Adresse zum
    Antippen auf dem Telefon und derselbe Schluessel in Vierergruppen zum
    Abtippen. Der Text erklaert beide Wege.

    Erwartete Variablen:
      $aktiv                    bool, Faktor bestaetigt
      $begonnen                 bool, Einrichtung begonnen, nicht bestaetigt
      $otpauthUri               string|null
      $schluessel               string|null, Base32 in Vierergruppen
      $wiederherstellungscodes  list<string>, genau einmal nach der Bestaetigung
      $verbleibendeCodes        int
--}}
@extends('layouts.portal')

@section('titel', 'Zwei-Faktor-Authentifizierung')

@section('content')
    @php
        // Status immer als Text plus Symbol, nie nur ueber Farbe (Designsystem 4.9).
        [$statusVariante, $statusSymbol, $statusText] = $aktiv
            ? ['success', 'check-circle', 'Aktiv']
            : ($begonnen
                ? ['warning', 'clock', 'Einrichtung begonnen']
                : ['neutral', 'lock', 'Nicht aktiv']);
    @endphp

    <x-hvm.page-header
        eyebrow="Konto"
        title="Zwei-Faktor-Authentifizierung"
        lead="Der zweite Faktor schützt Ihr Konto auch dann, wenn Ihr Passwort einmal bekannt wird."
        :back="route('portal.konto.edit')"
        backLabel="Zurück zum Konto" />

    <div class="mt-10 max-w-2xl space-y-6">
        @if ($wiederherstellungscodes !== [])
            <x-hvm.card :kennlinie="true" padding="none" class="rounded-3xl">
                <div class="p-6 sm:p-8">
                    <div class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
                            <x-hvm.icon name="key" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Ihre Wiederherstellungscodes</h2>
                            <p class="mt-2 text-base leading-relaxed text-hvm-textschwarz">
                                Bitte notieren Sie diese acht Codes jetzt und verwahren Sie sie sicher, getrennt von Ihrem
                                Telefon. Wir zeigen sie genau einmal an. Jeder Code gilt für eine einzige Anmeldung und ist
                                danach verbraucht.
                            </p>
                        </div>
                    </div>
                    <ul class="mt-6 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach ($wiederherstellungscodes as $code)
                            <li class="min-w-0 rounded-xl bg-hvm-canvas px-4 py-3 font-mono text-base tracking-widest text-hvm-textschwarz tabular">
                                {{ $code }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            </x-hvm.card>
        @endif

        <x-hvm.card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Status</h2>
                <x-hvm.badge :variant="$statusVariante" :icon="$statusSymbol">{{ $statusText }}</x-hvm.badge>
            </div>

            @if ($aktiv)
                <p class="mt-3 text-base leading-relaxed text-hvm-textschwarz">
                    Bei jeder Anmeldung verlangen wir nach dem Passwort einen sechsstelligen Code aus Ihrer
                    Authenticator-App. Ihnen stehen noch {{ $verbleibendeCodes }} Wiederherstellungscodes zur
                    Verfügung.
                </p>
            @else
                <p class="mt-3 text-base leading-relaxed text-hvm-textschwarz">
                    Für Kundenkonten ist der zweite Faktor freiwillig. Für interne Kennungen ist er verpflichtend,
                    der interne Bereich bleibt ohne ihn geschlossen.
                </p>
            @endif
        </x-hvm.card>

        @if (! $aktiv && ! $begonnen)
            <x-hvm.card title="Einrichtung starten" level="h2">
                <p>
                    Sie benötigen eine Authenticator-App auf Ihrem Telefon, zum Beispiel eine App, die
                    zeitbasierte Einmalcodes nach dem Standard TOTP unterstützt.
                </p>
                <form method="POST" action="{{ route('two-factor.setup.start') }}" class="mt-6">
                    @csrf
                    <x-hvm.button type="submit" variant="primary">
                        <x-hvm.icon name="key" class="h-4 w-4" />
                        Schlüssel erzeugen
                    </x-hvm.button>
                </form>
            </x-hvm.card>
        @endif

        @if ($begonnen)
            <x-hvm.card title="Schlüssel in die App übernehmen" level="h2">
                <p>
                    Sie haben zwei Wege. Öffnen Sie diese Seite auf dem Telefon, dann genügt ein Antippen der
                    folgenden Adresse, Ihre Authenticator-App übernimmt den Schlüssel selbst. Arbeiten Sie am
                    Rechner, tippen Sie den Schlüssel in der App unter "Schlüssel manuell eingeben" ab.
                </p>

                <dl class="mt-6 divide-y divide-hvm-linie rounded-2xl border border-hvm-linie">
                    <div class="min-w-0 p-4 sm:p-5">
                        <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Adresse für die App</dt>
                        <dd class="mt-2 text-sm break-all">
                            <a class="font-medium text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz" href="{{ $otpauthUri }}">{{ $otpauthUri }}</a>
                        </dd>
                    </div>
                    <div class="min-w-0 p-4 sm:p-5">
                        <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Schlüssel zum Abtippen</dt>
                        <dd class="mt-2 font-mono text-base tracking-widest break-all text-hvm-textschwarz tabular">{{ $schluessel }}</dd>
                    </div>
                    <div class="min-w-0 p-4 sm:p-5">
                        <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Einstellungen in der App</dt>
                        <dd class="mt-2 text-sm leading-relaxed text-hvm-textschwarz">
                            Zeitbasiert, 6 Stellen, 30 Sekunden, Algorithmus SHA1. Diese Werte sind bei den
                            verbreiteten Apps voreingestellt.
                        </dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-8 space-y-6">
                    @csrf
                    <x-hvm.field
                        name="code"
                        label="Code aus der App"
                        type="text"
                        autocomplete="one-time-code"
                        inputmode="numeric"
                        spellcheck="false"
                        class="tracking-widest"
                        :required="true" />
                    <x-hvm.button type="submit" variant="primary">Zweitfaktor aktivieren</x-hvm.button>
                </form>
            </x-hvm.card>
        @endif

        @if ($aktiv)
            <x-hvm.card title="Zweitfaktor abschalten" level="h2">
                <p>
                    Die Abschaltung verlangt Ihr Passwort und zusätzlich einen gültigen Code oder einen
                    Wiederherstellungscode. Für interne Kennungen ist die Abschaltung nicht sinnvoll, der interne
                    Bereich ist danach geschlossen.
                </p>

                <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-6 space-y-6">
                    @csrf
                    <x-hvm.field
                        name="current_password"
                        label="Aktuelles Passwort"
                        type="password"
                        autocomplete="current-password"
                        :required="true" />
                    <x-hvm.field
                        id="abschalt_code"
                        name="code"
                        label="Code oder Wiederherstellungscode"
                        type="text"
                        autocomplete="one-time-code"
                        spellcheck="false"
                        class="tracking-widest"
                        :required="true" />
                    <x-hvm.button type="submit" variant="danger">
                        <x-hvm.icon name="x-circle" class="h-4 w-4" />
                        Zweitfaktor abschalten
                    </x-hvm.button>
                </form>
            </x-hvm.card>
        @endif
    </div>
@endsection
