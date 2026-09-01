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
    <div class="mx-auto max-w-2xl">
        <x-hvm.section-heading
            eyebrow="Konto"
            title="Zwei-Faktor-Authentifizierung"
            lead="Der zweite Faktor schützt Ihr Konto auch dann, wenn Ihr Passwort einmal bekannt wird." />

        @if ($wiederherstellungscodes !== [])
            <x-hvm.card class="mt-8" accent>
                <h2 class="text-lg font-semibold text-hvm-textschwarz">Ihre Wiederherstellungscodes</h2>
                <p class="mt-2 text-sm text-hvm-textschwarz">
                    Bitte notieren Sie diese acht Codes jetzt und verwahren Sie sie sicher, getrennt von Ihrem
                    Telefon. Wir zeigen sie genau einmal an. Jeder Code gilt für eine einzige Anmeldung und ist
                    danach verbraucht.
                </p>
                <ul class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($wiederherstellungscodes as $code)
                        <li class="rounded-md border border-hvm-mittelgrau px-3 py-2 font-mono tracking-widest">
                            {{ $code }}
                        </li>
                    @endforeach
                </ul>
            </x-hvm.card>
        @endif

        <x-hvm.card class="mt-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-hvm-textschwarz">Status</h2>
                <x-hvm.badge>{{ $aktiv ? 'Aktiv' : ($begonnen ? 'Einrichtung begonnen' : 'Nicht aktiv') }}</x-hvm.badge>
            </div>

            @if ($aktiv)
                <p class="mt-3 text-sm text-hvm-textschwarz">
                    Bei jeder Anmeldung verlangen wir nach dem Passwort einen sechsstelligen Code aus Ihrer
                    Authenticator-App. Ihnen stehen noch {{ $verbleibendeCodes }} Wiederherstellungscodes zur
                    Verfügung.
                </p>
            @else
                <p class="mt-3 text-sm text-hvm-textschwarz">
                    Für Kundenkonten ist der zweite Faktor freiwillig. Für interne Kennungen ist er verpflichtend,
                    der interne Bereich bleibt ohne ihn geschlossen.
                </p>
            @endif
        </x-hvm.card>

        @if (! $aktiv && ! $begonnen)
            <x-hvm.card class="mt-6">
                <h2 class="text-lg font-semibold text-hvm-textschwarz">Einrichtung starten</h2>
                <p class="mt-2 text-sm text-hvm-textschwarz">
                    Sie benötigen eine Authenticator-App auf Ihrem Telefon, zum Beispiel eine App, die
                    zeitbasierte Einmalcodes nach dem Standard TOTP unterstützt.
                </p>
                <form method="POST" action="{{ route('two-factor.setup.start') }}" class="mt-4">
                    @csrf
                    <x-hvm.button type="submit" variant="primary">Schlüssel erzeugen</x-hvm.button>
                </form>
            </x-hvm.card>
        @endif

        @if ($begonnen)
            <x-hvm.card class="mt-6">
                <h2 class="text-lg font-semibold text-hvm-textschwarz">Schlüssel in die App übernehmen</h2>

                <p class="mt-2 text-sm text-hvm-textschwarz">
                    Sie haben zwei Wege. Öffnen Sie diese Seite auf dem Telefon, dann genügt ein Antippen der
                    folgenden Adresse, Ihre Authenticator-App übernimmt den Schlüssel selbst. Arbeiten Sie am
                    Rechner, tippen Sie den Schlüssel in der App unter "Schlüssel manuell eingeben" ab.
                </p>

                <dl class="mt-4 space-y-4 text-sm">
                    <div>
                        <dt class="font-semibold text-hvm-textschwarz">Adresse für die App</dt>
                        <dd class="mt-1 break-all">
                            <a class="underline underline-offset-2" href="{{ $otpauthUri }}">{{ $otpauthUri }}</a>
                        </dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-hvm-textschwarz">Schlüssel zum Abtippen</dt>
                        <dd class="mt-1 font-mono tracking-widest">{{ $schluessel }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-hvm-textschwarz">Einstellungen in der App</dt>
                        <dd class="mt-1">
                            Zeitbasiert, 6 Stellen, 30 Sekunden, Algorithmus SHA1. Diese Werte sind bei den
                            verbreiteten Apps voreingestellt.
                        </dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-6 space-y-4">
                    @csrf
                    <div>
                        <label for="code" class="block text-sm font-semibold text-hvm-textschwarz">
                            Code aus der App
                        </label>
                        <input id="code" name="code" type="text" required inputmode="numeric"
                               autocomplete="one-time-code" spellcheck="false"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2 tracking-widest">
                        @error('code')
                            <p class="mt-1 text-sm text-status-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <x-hvm.button type="submit" variant="primary">Zweitfaktor aktivieren</x-hvm.button>
                </form>
            </x-hvm.card>
        @endif

        @if ($aktiv)
            <x-hvm.card class="mt-6">
                <h2 class="text-lg font-semibold text-hvm-textschwarz">Zweitfaktor abschalten</h2>
                <p class="mt-2 text-sm text-hvm-textschwarz">
                    Die Abschaltung verlangt Ihr Passwort und zusätzlich einen gültigen Code oder einen
                    Wiederherstellungscode. Für interne Kennungen ist die Abschaltung nicht sinnvoll, der interne
                    Bereich ist danach geschlossen.
                </p>

                <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="current_password" class="block text-sm font-semibold text-hvm-textschwarz">
                            Aktuelles Passwort
                        </label>
                        <input id="current_password" name="current_password" type="password" required
                               autocomplete="current-password"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                        @error('current_password')
                            <p class="mt-1 text-sm text-status-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="abschalt_code" class="block text-sm font-semibold text-hvm-textschwarz">
                            Code oder Wiederherstellungscode
                        </label>
                        <input id="abschalt_code" name="code" type="text" required spellcheck="false"
                               autocomplete="one-time-code"
                               class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2 tracking-widest">
                        @error('code')
                            <p class="mt-1 text-sm text-status-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <x-hvm.button type="submit" variant="secondary">Zweitfaktor abschalten</x-hvm.button>
                </form>
            </x-hvm.card>
        @endif

        <p class="mt-6 text-sm text-hvm-anthrazit">
            <a class="font-medium underline underline-offset-2" href="{{ route('portal.konto.edit') }}">
                Zurück zum Konto
            </a>
        </p>
    </div>
@endsection
