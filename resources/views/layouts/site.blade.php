{{--
    Oeffentliches Layout von smart-abrechnen.de.

    Designsystem der Hausverwaltung Mueller GmbH. Die Farb- und
    Komponententokens liegen ausschliesslich in resources/css/app.css.
    HVM Orange wird nur fuer Akzente, primaere Buttons, Fortschritt und
    Tabellenkoepfe verwendet, niemals fuer Fliesstext. Anthrazit tragen
    Ueberschriften und die Fusszeile. Status wird nie allein ueber Farbe
    kommuniziert.

    Je Seite zu setzen:
      @section('meta_title')        Seitentitel ohne Markenzusatz
      @section('meta_description')  Meta-Description, ein Satz
      @section('content')           Seiteninhalt
--}}
@php
    $navigation = [
        ['route' => 'site.home', 'label' => 'Start'],
        ['route' => 'site.ablauf', 'label' => 'So funktioniert es'],
        ['route' => 'site.preise', 'label' => 'Preise'],
        ['route' => 'site.datenschutz-konzept', 'label' => 'Datenschutz und Löschung'],
        ['route' => 'site.faq', 'label' => 'Häufige Fragen'],
        ['route' => 'site.kontakt', 'label' => 'Kontakt'],
    ];

    $legalNavigation = [
        ['route' => 'legal.impressum', 'label' => 'Impressum'],
        ['route' => 'legal.datenschutz', 'label' => 'Datenschutzerklärung'],
        ['route' => 'legal.agb', 'label' => 'AGB'],
        ['route' => 'legal.widerruf', 'label' => 'Widerrufsbelehrung'],
    ];

    $operator = config('smartabrechnen.operator');
    $appUrl = url('/app');
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">

    <title>@yield('meta_title', 'Betriebskostenabrechnung erstellen') | Smart Abrechnen</title>
    <meta name="description" content="@yield('meta_description', 'Smart Abrechnen erstellt aus Ihren vorhandenen Unterlagen eine strukturierte Betriebskostenabrechnung. Konto und Entwürfe sind kostenlos, bezahlt wird erst nach der Vorschau.')">

    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Smart Abrechnen">
    <meta property="og:locale" content="de_DE">
    <meta property="og:title" content="@yield('meta_title', 'Betriebskostenabrechnung erstellen')">
    <meta property="og:description" content="@yield('meta_description', 'Smart Abrechnen erstellt aus Ihren vorhandenen Unterlagen eine strukturierte Betriebskostenabrechnung.')">
    <meta property="og:url" content="{{ url()->current() }}">

    {{--
        Progressive enhancement: erst wenn JavaScript verfuegbar ist, darf
        Alpine Inhalte einklappen. Ohne JavaScript bleiben alle Texte sichtbar
        und lesbar.
    --}}
    <script>document.documentElement.classList.add('js');</script>
    <style>
        .js [x-cloak] { display: none !important; }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white text-hvm-textschwarz antialiased">
    <a href="#hauptinhalt"
       class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-hvm-orange focus:px-4 focus:py-2 focus:font-semibold focus:text-hvm-textschwarz">
        Zum Hauptinhalt springen
    </a>

    <header class="sticky top-0 z-40 bg-white shadow-sm" x-data="{ menuOffen: false }">
        {{-- HVM-Kennlinie direkt unter der Oberkante. --}}
        <div class="hvm-kennlinie" aria-hidden="true"></div>

        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
            {{--
                Logo-Platzhalter. Das Logo der Hausverwaltung Mueller GmbH wird
                vom Auftraggeber als /public/ci/Logo_HVM.jpg beziehungsweise
                Logo_HVM.svg bereitgestellt und erst dann hier eingebunden
                (siehe public/ci/README.md). Es wird kein Logo erzeugt,
                generiert oder nachgezeichnet.
            --}}
            <a href="{{ route('site.home') }}" class="flex items-center gap-3 rounded py-1">
                <span class="flex h-10 w-10 items-center justify-center rounded border border-hvm-hellgrau bg-hvm-umrissgrau text-[10px] leading-tight font-semibold text-hvm-anthrazit"
                      aria-hidden="true">
                    HVM
                </span>
                <span class="flex flex-col leading-tight">
                    <span class="text-lg font-bold text-hvm-textschwarz">Smart Abrechnen</span>
                    <span class="text-xs text-hvm-textschwarz">Ein Angebot der {{ $operator['legal_name'] }}</span>
                </span>
            </a>

            <nav class="hidden lg:block" aria-label="Hauptnavigation">
                <ul class="flex items-center gap-1">
                    @foreach ($navigation as $item)
                        <li>
                            <a href="{{ route($item['route']) }}"
                               @if (request()->routeIs($item['route'])) aria-current="page" @endif
                               class="block rounded px-3 py-2 text-sm font-medium text-hvm-textschwarz hover:bg-hvm-umrissgrau {{ request()->routeIs($item['route']) ? 'border-b-2 border-hvm-orange' : '' }}">
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="hidden items-center gap-2 lg:flex">
                <x-hvm.button href="{{ $appUrl }}" variant="secondary" size="sm">Anmelden</x-hvm.button>
                <x-hvm.button href="{{ $appUrl }}" variant="primary" size="sm">Kostenlos starten</x-hvm.button>
            </div>

            <button type="button"
                    class="inline-flex min-h-11 items-center gap-2 rounded border border-hvm-mittelgrau px-3 py-2 text-sm font-semibold text-hvm-textschwarz lg:hidden"
                    x-on:click="menuOffen = !menuOffen"
                    x-bind:aria-expanded="menuOffen ? 'true' : 'false'"
                    aria-expanded="false"
                    aria-controls="hauptnavigation-mobil">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M2 5h16v2H2V5Zm0 4h16v2H2V9Zm0 4h16v2H2v-2Z" />
                </svg>
                Menü
            </button>
        </div>

        <div id="hauptnavigation-mobil"
             class="border-t border-hvm-umrissgrau lg:hidden"
             x-show="menuOffen"
             x-cloak>
            <nav class="mx-auto max-w-6xl px-4 py-3 sm:px-6" aria-label="Hauptnavigation mobil">
                <ul class="flex flex-col">
                    @foreach ($navigation as $item)
                        <li>
                            <a href="{{ route($item['route']) }}"
                               @if (request()->routeIs($item['route'])) aria-current="page" @endif
                               class="block border-b border-hvm-umrissgrau py-3 text-base font-medium text-hvm-textschwarz">
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4 flex flex-col gap-2 pb-2">
                    <x-hvm.button href="{{ $appUrl }}" variant="primary">Kostenlos starten</x-hvm.button>
                    <x-hvm.button href="{{ $appUrl }}" variant="secondary">Anmelden</x-hvm.button>
                </div>
            </nav>
        </div>
    </header>

    <main id="hauptinhalt">
        @if (session('status'))
            <div class="mx-auto max-w-6xl px-4 pt-6 sm:px-6">
                <x-hvm.alert variant="success" label="Erledigt">
                    {{ session('status') }}
                </x-hvm.alert>
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="mt-20 border-t border-hvm-mittelgrau bg-hvm-umrissgrau">
        <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
            <div class="grid gap-10 md:grid-cols-3">
                <div>
                    <p class="text-xl font-bold text-hvm-anthrazit">Die digitalste Hausverwaltung</p>
                    <p class="mt-3 text-sm text-hvm-textschwarz">
                        Smart Abrechnen ist ein Angebot der {{ $operator['legal_name'] }}.
                    </p>
                    {{-- Betreiberangaben in Kurzform. Nicht ergaenzen und nicht abwandeln. --}}
                    <address class="mt-3 text-sm not-italic text-hvm-textschwarz">
                        {{ $operator['legal_name'] }}<br>
                        {{ $operator['address_line'] }}<br>
                        {{ $operator['postal_code'] }} {{ $operator['city'] }}<br>
                        {{ $operator['register_court'] }}, {{ $operator['register_number'] }}<br>
                        Geschäftsführer: {{ $operator['managing_director'] }}
                    </address>
                    <p class="mt-3 text-sm text-hvm-textschwarz">
                        <a class="font-medium underline underline-offset-2" href="mailto:kontakt@smart-abrechnen.de">kontakt@smart-abrechnen.de</a>
                    </p>
                </div>

                <div>
                    <p class="text-sm font-semibold tracking-wide text-hvm-textschwarz uppercase">Portal</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($navigation as $item)
                            <li>
                                <a href="{{ route($item['route']) }}" class="text-hvm-textschwarz underline-offset-2 hover:underline">
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <p class="text-sm font-semibold tracking-wide text-hvm-textschwarz uppercase">Rechtliches</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($legalNavigation as $item)
                            <li>
                                <a href="{{ route($item['route']) }}" class="text-hvm-textschwarz underline-offset-2 hover:underline">
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-4 text-sm text-hvm-textschwarz">
                        <a href="{{ $operator['website'] }}" class="underline underline-offset-2">Website der Hausverwaltung</a>
                    </p>
                </div>
            </div>

            <div class="mt-10 border-t border-hvm-mittelgrau pt-6 text-sm text-hvm-textschwarz">
                <p>
                    Smart Abrechnen ist ein Software-Werkzeug. Absender und inhaltlich verantwortlich für die
                    Betriebskostenabrechnung bleibt der Vermieter. Eine Rechtsberatung im Einzelfall erfolgt nicht.
                </p>
                <p class="mt-2">
                    Ihre Originaldateien werden nur zur Auswertung kurzfristig verarbeitet und anschließend automatisch
                    gelöscht. Bitte bewahren Sie Ihre Originalbelege selbst auf.
                </p>
            </div>
        </div>
    </footer>
</body>
</html>
