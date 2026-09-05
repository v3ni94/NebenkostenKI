{{--
    Layout der Anwendung und der Anmeldeseiten (Anwendungs-Shell).

    Aufbau mit Navigation: ab lg eine linke Seitenleiste (Marke, Navigation
    mit Icons, Kontoblock mit Abmelden), darunter eine Kopfleiste mit der
    umbrechenden Pill-Navigation (alle Eintraege sichtbar, kein horizontales
    Scrollen). Ohne Navigation (Anmelden, Registrieren): schmale Kopfleiste
    und zentrierter Inhalt. Die Kennlinie liegt als 3 px Linie ueber der
    ganzen Seite.

    Bereichstrennung nach ARCHITECTURE.md, ADR-002: Das oeffentliche Frontend
    und die Anwendung teilen sich weder Layout noch Navigation, damit ein
    oeffentlicher View niemals versehentlich Kundendaten rendert. Es gibt hier
    deshalb kein Marketingmenue.

    Die Seiten sind bewusst nicht indexierbar (noindex), weil hinter dem Login
    ausschliesslich Kundendaten liegen.

    Sektionen:
      @section('titel')             Seitentitel
      @section('content')           Seiteninhalt
      @section('ohne_navigation')   beliebiger nicht leerer Wert blendet die
                                    Portalnavigation aus, verwendet von den
                                    Anmelde- und Registrierungsseiten
--}}
@php
    $benutzer = auth()->user();

    $navigation = [
        ['route' => 'portal.dashboard', 'label' => 'Übersicht', 'icon' => 'grid'],
        ['route' => 'portal.objekte.index', 'label' => 'Objekte', 'icon' => 'building'],
        ['route' => 'portal.abrechnungen.index', 'label' => 'Abrechnungen', 'icon' => 'receipt'],
        ['route' => 'portal.konto.edit', 'label' => 'Konto', 'icon' => 'user'],
    ];

    $mitNavigation = $benutzer !== null && ! View::hasSection('ohne_navigation');

    $operator = config('smartabrechnen.operator');
    $brand = config('smartabrechnen.brand');
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('titel', 'Anwendung') | Smart Abrechnen</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

    {{--
        Progressive enhancement. Der Inhalt dieses Bausteins ist mit dem des
        oeffentlichen Layouts identisch, damit ein einziger CSP-Hash beide
        Seiten abdeckt (siehe App\Http\Middleware\SecurityHeaders).
    --}}
    <script>document.documentElement.classList.add('js');</script>
    <style>
        .js [x-cloak] { display: none !important; }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-hvm-canvas text-hvm-textschwarz antialiased">
    <a href="#hauptinhalt"
       class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:rounded-full focus:bg-hvm-orange focus:px-5 focus:py-3 focus:font-semibold focus:text-hvm-textschwarz">
        Zum Hauptinhalt springen
    </a>

    {{-- HVM-Kennlinie als feine Linie an der Oberkante jeder Seite. --}}
    <div class="hvm-kennlinie" aria-hidden="true"></div>

    @if ($mitNavigation)
        <div class="lg:grid lg:min-h-[calc(100vh-3px)] lg:grid-cols-[17rem_minmax(0,1fr)]">
            {{-- Seitenleiste ab lg (Uebernahme aus Konzept B). --}}
            <aside class="hidden border-r border-hvm-linie bg-white lg:sticky lg:top-0 lg:flex lg:h-screen lg:flex-col">
                <div class="flex items-center gap-3 px-5 pt-5 pb-4">
                    {{-- Logo der Hausverwaltung Mueller GmbH aus /public/ci, sonst Textplatzhalter. --}}
                    <x-hvm.logo height="h-9" />
                    <span class="flex min-w-0 flex-col leading-tight">
                        <span class="text-base font-semibold tracking-tight text-hvm-textschwarz">{{ $brand['name'] }}</span>
                        <span class="text-[11px] leading-[1.25] text-hvm-text-sekundaer">{{ $brand['relation_short'] }}</span>
                    </span>
                </div>

                <nav class="flex-1 px-3 py-2" aria-label="Bereichsnavigation">
                    <ul class="space-y-1">
                        @foreach ($navigation as $eintrag)
                            <li>
                                <a href="{{ route($eintrag['route']) }}"
                                   @if (request()->routeIs($eintrag['route'])) aria-current="page" @endif
                                   class="hvm-nav-item">
                                    <x-hvm.icon :name="$eintrag['icon']" class="h-5 w-5" />
                                    {{ $eintrag['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>

                <div class="border-t border-hvm-linie p-4">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
                            <x-hvm.icon name="user" class="h-5 w-5" />
                        </span>
                        <span class="min-w-0 text-sm">
                            <span class="block text-xs text-hvm-text-sekundaer">Angemeldet als</span>
                            <span class="block font-semibold text-hvm-textschwarz">{{ $benutzer->name }}</span>
                        </span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="mt-3">
                        @csrf
                        <x-hvm.button type="submit" variant="secondary" size="sm" class="w-full">
                            <x-hvm.icon name="logout" class="h-4 w-4" />
                            Abmelden
                        </x-hvm.button>
                    </form>
                </div>
            </aside>

            <div class="flex min-w-0 flex-col">
                {{-- Kopfleiste unterhalb lg: Marke, Abmelden, umbrechende Pill-Navigation. --}}
                <header class="border-b border-hvm-linie bg-white lg:hidden">
                    <div class="flex items-center justify-between gap-3 px-4 py-3 sm:px-6">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-hvm.logo height="h-9" />
                            <span class="flex min-w-0 flex-col leading-tight">
                                <span class="text-base font-semibold tracking-tight text-hvm-textschwarz">{{ $brand['name'] }}</span>
                                <span class="text-[11px] leading-[1.25] text-hvm-text-sekundaer sm:text-xs">{{ $brand['relation_short'] }}</span>
                            </span>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="hidden text-sm text-hvm-text-sekundaer md:inline">Angemeldet als {{ $benutzer->name }}</span>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-hvm.button type="submit" variant="secondary" size="sm">Abmelden</x-hvm.button>
                            </form>
                        </div>
                    </div>

                    <nav class="px-4 pb-3 sm:px-6" aria-label="Bereichsnavigation mobil">
                        <ul class="flex flex-wrap gap-1">
                            @foreach ($navigation as $eintrag)
                                <li>
                                    <a href="{{ route($eintrag['route']) }}"
                                       @if (request()->routeIs($eintrag['route'])) aria-current="page" @endif
                                       class="flex min-h-11 items-center gap-2 rounded-full px-4 py-2.5 text-sm font-medium whitespace-nowrap no-underline transition-colors {{ request()->routeIs($eintrag['route']) ? 'bg-hvm-textschwarz text-white' : 'text-hvm-textschwarz hover:bg-hvm-canvas-deep' }}">
                                        <x-hvm.icon :name="$eintrag['icon']" class="h-4 w-4 {{ request()->routeIs($eintrag['route']) ? 'text-hvm-orange' : 'text-hvm-text-sekundaer' }}" />
                                        {{ $eintrag['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                </header>

                <main id="hauptinhalt" class="w-full max-w-6xl flex-1 px-4 py-10 sm:px-6 lg:px-10 lg:py-12">
                    @if (session('status'))
                        <div class="mb-8">
                            <x-hvm.alert variant="success" label="Erledigt">
                                {{ session('status') }}
                            </x-hvm.alert>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-8">
                            <x-hvm.alert variant="error" label="Bitte prüfen" title="Ihre Eingabe konnte nicht gespeichert werden">
                                <ul class="list-disc space-y-1 pl-5">
                                    @foreach ($errors->all() as $fehler)
                                        <li>{{ $fehler }}</li>
                                    @endforeach
                                </ul>
                            </x-hvm.alert>
                        </div>
                    @endif

                    @yield('content')
                </main>

                <footer class="mt-16 border-t border-hvm-linie bg-white">
                    <div class="w-full max-w-6xl px-4 py-10 text-sm leading-relaxed text-hvm-text-sekundaer sm:px-6 lg:px-10">
                        <div class="grid gap-6 lg:grid-cols-2 lg:gap-10">
                            <p>
                                {{ $brand['relation'] }}
                                Absender und inhaltlich verantwortlich für die Betriebskostenabrechnung bleibt der Vermieter.
                            </p>
                            <p>
                                Bitte bewahren Sie Ihre Originalbelege selbst auf. Hochgeladene Dateien werden nach der
                                Auswertung automatisch gelöscht.
                            </p>
                        </div>
                        <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-2 border-t border-hvm-linie pt-6">
                            <li><a class="text-hvm-textschwarz no-underline underline-offset-4 hover:underline" href="{{ route('legal.impressum') }}">Impressum</a></li>
                            <li><a class="text-hvm-textschwarz no-underline underline-offset-4 hover:underline" href="{{ route('legal.datenschutz') }}">Datenschutzerklärung</a></li>
                            <li><a class="text-hvm-textschwarz no-underline underline-offset-4 hover:underline" href="{{ route('legal.agb') }}">AGB</a></li>
                            <li><a class="text-hvm-textschwarz no-underline underline-offset-4 hover:underline" href="{{ route('legal.widerruf') }}">Widerrufsbelehrung</a></li>
                        </ul>
                    </div>
                </footer>
            </div>
        </div>
    @else
        {{-- Schmale Kopfleiste fuer Anmeldung, Registrierung und Seiten ohne Navigation. --}}
        <header class="border-b border-hvm-linie bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    {{-- Logo der Hausverwaltung Mueller GmbH aus /public/ci, sonst Textplatzhalter. --}}
                    <x-hvm.logo height="h-9" />
                    <span class="flex min-w-0 flex-col leading-tight">
                        <span class="text-base font-semibold tracking-tight text-hvm-textschwarz sm:text-lg">{{ $brand['name'] }}</span>
                        <span class="text-[11px] leading-[1.25] text-hvm-text-sekundaer sm:text-xs">{{ $brand['relation_short'] }}</span>
                    </span>
                </div>

                @hasSection('ohne_navigation')
                    <a href="{{ route('site.home') }}" class="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-full px-3 text-sm font-medium whitespace-nowrap text-hvm-textschwarz no-underline hover:bg-hvm-canvas">
                        Zur Startseite
                    </a>
                @elseif ($benutzer !== null)
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-hvm.button type="submit" variant="secondary" size="sm">Abmelden</x-hvm.button>
                    </form>
                @endif
            </div>
        </header>

        <main id="hauptinhalt" class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8 lg:py-14">
            @if (session('status'))
                <div class="mx-auto mb-8 max-w-md">
                    <x-hvm.alert variant="success" label="Erledigt">
                        {{ session('status') }}
                    </x-hvm.alert>
                </div>
            @endif

            @if ($errors->any())
                <div class="mx-auto mb-8 max-w-md">
                    <x-hvm.alert variant="error" label="Bitte prüfen" title="Ihre Eingabe konnte nicht gespeichert werden">
                        <ul class="list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $fehler)
                                <li>{{ $fehler }}</li>
                            @endforeach
                        </ul>
                    </x-hvm.alert>
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="mt-16 border-t border-hvm-linie bg-white">
            <div class="mx-auto max-w-7xl px-4 py-10 text-sm leading-relaxed text-hvm-text-sekundaer sm:px-6 lg:px-8">
                <div class="grid gap-6 lg:grid-cols-2 lg:gap-10">
                    <p>
                        {{ $brand['relation'] }}
                        Absender und inhaltlich verantwortlich für die Betriebskostenabrechnung bleibt der Vermieter.
                    </p>
                    <p>
                        Bitte bewahren Sie Ihre Originalbelege selbst auf. Hochgeladene Dateien werden nach der
                        Auswertung automatisch gelöscht.
                    </p>
                </div>
                <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-2 border-t border-hvm-linie pt-6">
                    <li><a class="text-hvm-textschwarz no-underline underline-offset-4 hover:underline" href="{{ route('legal.impressum') }}">Impressum</a></li>
                    <li><a class="text-hvm-textschwarz no-underline underline-offset-4 hover:underline" href="{{ route('legal.datenschutz') }}">Datenschutzerklärung</a></li>
                    <li><a class="text-hvm-textschwarz no-underline underline-offset-4 hover:underline" href="{{ route('legal.agb') }}">AGB</a></li>
                    <li><a class="text-hvm-textschwarz no-underline underline-offset-4 hover:underline" href="{{ route('legal.widerruf') }}">Widerrufsbelehrung</a></li>
                </ul>
            </div>
        </footer>
    @endif
</body>
</html>
