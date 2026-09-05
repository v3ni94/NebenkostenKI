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
    $brand = config('smartabrechnen.brand');
    $appUrl = url('/app');
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="@yield('meta_robots', 'index, follow')">

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
<body class="bg-hvm-canvas text-hvm-textschwarz antialiased">
    <a href="#hauptinhalt"
       class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:rounded-full focus:bg-hvm-orange focus:px-5 focus:py-3 focus:font-semibold focus:text-hvm-textschwarz">
        Zum Hauptinhalt springen
    </a>

    <header class="sticky top-0 z-40 border-b border-hvm-linie bg-hvm-canvas/90 backdrop-blur" x-data="{ menuOffen: false }">
        {{-- HVM-Kennlinie als feine Linie ueber dem Header. --}}
        <div class="hvm-kennlinie" aria-hidden="true"></div>

        <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
            {{--
                Logo der Hausverwaltung Mueller GmbH aus /public/ci (siehe
                public/ci/README.md). Fehlt die Datei, erscheint ein neutraler
                Textplatzhalter. Es wird kein Logo erzeugt oder nachgezeichnet.
            --}}
            <a href="{{ route('site.home') }}" class="flex min-w-0 items-center gap-3 rounded-xl py-1 no-underline">
                <x-hvm.logo height="h-9" />
                <span class="flex min-w-0 flex-col leading-tight">
                    <span class="text-base font-semibold tracking-tight whitespace-nowrap text-hvm-textschwarz sm:text-lg">{{ $brand['name'] }}</span>
                    <span class="truncate text-[11px] text-hvm-text-sekundaer sm:text-xs xl:text-[11px]">{{ $brand['relation_short'] }}</span>
                </span>
            </a>

            <nav class="hidden xl:block" aria-label="Hauptnavigation">
                <ul class="flex items-center gap-0.5 rounded-full border border-hvm-linie bg-white/70 p-0.5">
                    @foreach ($navigation as $item)
                        <li>
                            <a href="{{ route($item['route']) }}"
                               @if (request()->routeIs($item['route'])) aria-current="page" @endif
                               class="block rounded-full px-2.5 py-2.5 text-xs font-medium whitespace-nowrap no-underline transition-colors {{ request()->routeIs($item['route']) ? 'bg-hvm-textschwarz text-white' : 'text-hvm-textschwarz hover:bg-hvm-canvas-deep' }}">
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="hidden items-center gap-2 xl:flex">
                <x-hvm.button href="{{ $appUrl }}" variant="ghost" size="sm" class="px-3!">Anmelden</x-hvm.button>
                <x-hvm.button href="{{ $appUrl }}" variant="primary" size="sm">Kostenlos starten</x-hvm.button>
            </div>

            <button type="button"
                    class="inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-hvm-hellgrau bg-white px-4 py-2 text-sm font-semibold text-hvm-textschwarz xl:hidden"
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
             class="border-t border-hvm-linie bg-hvm-canvas xl:hidden"
             x-show="menuOffen"
             x-cloak>
            <nav class="mx-auto max-w-7xl px-4 py-4 sm:px-6" aria-label="Hauptnavigation mobil">
                <ul class="flex flex-col gap-1">
                    @foreach ($navigation as $item)
                        <li>
                            <a href="{{ route($item['route']) }}"
                               @if (request()->routeIs($item['route'])) aria-current="page" @endif
                               class="block min-h-11 rounded-xl px-4 py-3 text-base font-medium no-underline {{ request()->routeIs($item['route']) ? 'bg-white text-hvm-textschwarz shadow-hairline' : 'text-hvm-textschwarz hover:bg-white' }}">
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4 flex flex-col gap-2 border-t border-hvm-linie pt-4 pb-2">
                    <x-hvm.button href="{{ $appUrl }}" variant="primary">Kostenlos starten</x-hvm.button>
                    <x-hvm.button href="{{ $appUrl }}" variant="secondary">Anmelden</x-hvm.button>
                </div>
            </nav>
        </div>
    </header>

    <main id="hauptinhalt">
        @if (session('status'))
            <div class="mx-auto max-w-7xl px-4 pt-6 sm:px-6 lg:px-8">
                <x-hvm.alert variant="success" label="Erledigt">
                    {{ session('status') }}
                </x-hvm.alert>
            </div>
        @endif

        @yield('content')
    </main>

    {{--
        Fusszeile als dunkle Graphitflaeche. Text darauf ist Weiss oder
        Hellgrau, die Kennlinie schliesst die Seite als Fussakzent ab.
    --}}
    <footer class="bg-hvm-graphit text-hvm-hellgrau">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20">
            <div class="grid gap-12 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    <p class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">Die digitalste Hausverwaltung</p>
                    <p class="mt-4 max-w-md text-sm leading-relaxed">
                        {{ $brand['relation'] }}
                    </p>
                    {{-- Betreiberangaben in Kurzform. Nicht ergaenzen und nicht abwandeln. --}}
                    <address class="mt-6 text-sm leading-relaxed not-italic">
                        {{ $operator['legal_name'] }}<br>
                        {{ $operator['address_line'] }}<br>
                        {{ $operator['postal_code'] }} {{ $operator['city'] }}<br>
                        {{ $operator['register_court'] }}, {{ $operator['register_number'] }}<br>
                        Geschäftsführer: {{ $operator['managing_director'] }}
                    </address>
                    <p class="mt-4 text-sm">
                        <a class="font-medium text-white underline decoration-hvm-anthrazit underline-offset-4 hover:decoration-white" href="mailto:kontakt@smart-abrechnen.de">kontakt@smart-abrechnen.de</a>
                    </p>
                </div>

                <div class="lg:col-span-3 lg:col-start-7">
                    <p class="text-xs font-semibold tracking-[0.12em] text-hvm-anthrazit uppercase">Portal</p>
                    <ul class="mt-4 space-y-3 text-sm">
                        @foreach ($navigation as $item)
                            <li>
                                <a href="{{ route($item['route']) }}" class="text-hvm-hellgrau no-underline underline-offset-4 hover:text-white hover:underline">
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="lg:col-span-3">
                    <p class="text-xs font-semibold tracking-[0.12em] text-hvm-anthrazit uppercase">Rechtliches</p>
                    <ul class="mt-4 space-y-3 text-sm">
                        @foreach ($legalNavigation as $item)
                            <li>
                                <a href="{{ route($item['route']) }}" class="text-hvm-hellgrau no-underline underline-offset-4 hover:text-white hover:underline">
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-6 text-sm">
                        <a href="{{ $operator['website'] }}" class="text-white underline decoration-hvm-anthrazit underline-offset-4 hover:decoration-white">Website der Hausverwaltung</a>
                    </p>
                </div>
            </div>

            <div class="mt-14 grid gap-4 border-t border-hvm-graphit-soft pt-8 text-sm leading-relaxed text-hvm-hellgrau lg:grid-cols-2 lg:gap-10">
                <p>
                    Smart Abrechnen ist ein Software-Werkzeug. Absender und inhaltlich verantwortlich für die
                    Betriebskostenabrechnung bleibt der Vermieter. Eine Rechtsberatung im Einzelfall erfolgt nicht.
                </p>
                <p>
                    Ihre Originaldateien werden nur zur Auswertung kurzfristig verarbeitet und anschließend automatisch
                    gelöscht. Bitte bewahren Sie Ihre Originalbelege selbst auf.
                </p>
            </div>
        </div>
        <div class="hvm-kennlinie" aria-hidden="true"></div>
    </footer>
</body>
</html>
