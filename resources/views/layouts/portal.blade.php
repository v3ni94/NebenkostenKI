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
<body class="min-h-screen bg-hvm-umrissgrau text-hvm-textschwarz antialiased">
    <a href="#hauptinhalt"
       class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-hvm-orange focus:px-4 focus:py-2 focus:font-semibold focus:text-hvm-textschwarz">
        Zum Hauptinhalt springen
    </a>

    <header class="bg-white shadow-sm">
        {{-- HVM-Kennlinie direkt unter der Oberkante. --}}
        <div class="hvm-kennlinie" aria-hidden="true"></div>

        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <div class="flex items-center gap-3">
                {{-- Logo der Hausverwaltung Mueller GmbH aus /public/ci, sonst Textplatzhalter. --}}
                <x-hvm.logo />
                <span class="flex flex-col leading-tight">
                    <span class="text-lg font-bold text-hvm-textschwarz">{{ $brand['name'] }}</span>
                    <span class="text-xs text-hvm-anthrazit">{{ $brand['relation_short'] }}</span>
                </span>
            </div>

            @hasSection('ohne_navigation')
                <a href="{{ route('site.home') }}" class="text-sm font-medium text-hvm-textschwarz underline underline-offset-2">
                    Zur Startseite
                </a>
            @else
                @if ($benutzer !== null)
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-sm text-hvm-anthrazit">
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
                <nav class="border-t border-hvm-umrissgrau" aria-label="Bereichsnavigation">
                    <ul class="mx-auto flex max-w-6xl flex-wrap gap-1 px-4 sm:px-6">
                        @foreach ($navigation as $eintrag)
                            <li>
                                <a href="{{ route($eintrag['route']) }}"
                                   @if (request()->routeIs($eintrag['route'])) aria-current="page" @endif
                                   class="block px-3 py-3 text-sm font-medium text-hvm-textschwarz hover:bg-hvm-umrissgrau {{ request()->routeIs($eintrag['route']) ? 'border-b-2 border-hvm-orange' : '' }}">
                                    {{ $eintrag['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        @endif
    </header>

    <main id="hauptinhalt" class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        @if (session('status'))
            <div class="mb-6">
                <x-hvm.alert variant="success" label="Erledigt">
                    {{ session('status') }}
                </x-hvm.alert>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6">
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

    <footer class="mt-12 border-t border-hvm-mittelgrau bg-white">
        <div class="mx-auto max-w-6xl px-4 py-8 text-sm text-hvm-anthrazit sm:px-6">
            <p>
                {{ $brand['relation'] }}
                Absender und inhaltlich verantwortlich für die Betriebskostenabrechnung bleibt der Vermieter.
            </p>
            <p class="mt-2">
                Bitte bewahren Sie Ihre Originalbelege selbst auf. Hochgeladene Dateien werden nach der
                Auswertung automatisch gelöscht.
            </p>
            <ul class="mt-4 flex flex-wrap gap-4">
                <li><a class="underline underline-offset-2" href="{{ route('legal.impressum') }}">Impressum</a></li>
                <li><a class="underline underline-offset-2" href="{{ route('legal.datenschutz') }}">Datenschutzerklärung</a></li>
                <li><a class="underline underline-offset-2" href="{{ route('legal.agb') }}">AGB</a></li>
                <li><a class="underline underline-offset-2" href="{{ route('legal.widerruf') }}">Widerrufsbelehrung</a></li>
            </ul>
        </div>
    </footer>
</body>
</html>
