{{--
    Layout der Anwendung und der Anmeldeseiten.

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
        ['route' => 'portal.dashboard', 'label' => 'Übersicht'],
        ['route' => 'portal.objekte.index', 'label' => 'Objekte'],
        ['route' => 'portal.abrechnungen.index', 'label' => 'Abrechnungen'],
        ['route' => 'portal.konto.edit', 'label' => 'Konto'],
    ];

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

    {{-- App-Shell: weisse Kopfleiste mit Kennlinie, Navigation als Pills. --}}
    <header class="border-b border-hvm-linie bg-white">
        <div class="hvm-kennlinie" aria-hidden="true"></div>

        <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
            <div class="flex min-w-0 items-center gap-3">
                {{-- Logo der Hausverwaltung Mueller GmbH aus /public/ci, sonst Textplatzhalter. --}}
                <x-hvm.logo height="h-9" />
                <span class="flex min-w-0 flex-col leading-tight">
                    <span class="text-base font-semibold tracking-tight whitespace-nowrap text-hvm-textschwarz sm:text-lg">{{ $brand['name'] }}</span>
                    <span class="truncate text-xs text-hvm-text-sekundaer">{{ $brand['relation_short'] }}</span>
                </span>
            </div>

            @hasSection('ohne_navigation')
                <a href="{{ route('site.home') }}" class="inline-flex min-h-11 items-center gap-2 rounded-full px-3 text-sm font-medium whitespace-nowrap text-hvm-textschwarz no-underline hover:bg-hvm-canvas">
                    Zur Startseite
                </a>
            @else
                @if ($benutzer !== null)
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="hidden items-center gap-2 text-sm text-hvm-text-sekundaer sm:inline-flex">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-hvm-canvas-deep text-hvm-textschwarz" aria-hidden="true">
                                <x-hvm.icon name="user" class="h-4 w-4" />
                            </span>
                            Angemeldet als {{ $benutzer->name }}
                        </span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-hvm.button type="submit" variant="secondary" size="sm">Abmelden</x-hvm.button>
                        </form>
                    </div>
                @endif
            @endif
        </div>

        @hasSection('ohne_navigation')
        @else
            @if ($benutzer !== null)
                <nav class="mx-auto max-w-7xl px-4 pb-3 sm:px-6 lg:px-8" aria-label="Bereichsnavigation">
                    <ul class="flex flex-wrap gap-1">
                        @foreach ($navigation as $eintrag)
                            <li>
                                <a href="{{ route($eintrag['route']) }}"
                                   @if (request()->routeIs($eintrag['route'])) aria-current="page" @endif
                                   class="block min-h-11 rounded-full px-4 py-2.5 text-sm font-medium whitespace-nowrap no-underline transition-colors {{ request()->routeIs($eintrag['route']) ? 'bg-hvm-textschwarz text-white' : 'text-hvm-textschwarz hover:bg-hvm-canvas-deep' }}">
                                    {{ $eintrag['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        @endif
    </header>

    <main id="hauptinhalt" class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8 lg:py-14">
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
</body>
</html>
