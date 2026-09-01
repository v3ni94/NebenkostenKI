{{--
    Layout des internen Adminbereichs.

    Bereichstrennung nach ARCHITECTURE.md, ADR-002: Der Adminbereich teilt sich
    weder Layout noch Navigation mit der Kundenoberflaeche. Es gibt hier
    ausdruecklich kein Kundenmenue, keinen Marketingtext und keinen Verweis in
    den Portalbereich, damit ein Adminview niemals versehentlich wie eine
    Kundenseite aussieht.

    Die Kopfzeile ist dunkel und traegt den Hinweis "Interner Bereich", damit
    eine Verwechslung mit der Kundenoberflaeche ausgeschlossen ist.

    Die Seiten sind nicht indexierbar.

    Sektionen:
      @section('titel')    Seitentitel
      @section('content')  Seiteninhalt
--}}
@php
    $benutzer = auth()->user();
    $blockerbericht = app(\App\Application\Admin\LaunchBlockerCheck::class)->report();
    $zweitfaktorFehlt = $benutzer !== null && $benutzer->getAttribute('two_factor_confirmed_at') === null;

    $navigation = [
        ['route' => 'admin.dashboard', 'label' => 'Übersicht'],
        ['route' => 'admin.livegang', 'label' => 'Livegang-Blocker'],
        ['route' => 'admin.datenschutz', 'label' => 'Datenschutz'],
        ['route' => 'admin.verarbeitung', 'label' => 'Verarbeitung'],
        ['route' => 'admin.ki', 'label' => 'KI'],
        ['route' => 'admin.zahlungen', 'label' => 'Zahlungen'],
        ['route' => 'admin.preise', 'label' => 'Preise'],
        ['route' => 'admin.nutzer', 'label' => 'Nutzer'],
        ['route' => 'admin.organisationen', 'label' => 'Organisationen'],
        ['route' => 'admin.kommunikation', 'label' => 'Kommunikation'],
        ['route' => 'admin.versionen', 'label' => 'Versionen'],
        ['route' => 'admin.technik', 'label' => 'Technik'],
        ['route' => 'admin.kennzahlen', 'label' => 'Kennzahlen'],
        ['route' => 'admin.protokoll', 'label' => 'Protokoll'],
    ];
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('titel', 'Interner Bereich') | Verwaltung Smart Abrechnen</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

    {{--
        Der Inhalt dieses Bausteins ist mit dem des Portal- und des
        oeffentlichen Layouts identisch, damit ein einziger CSP-Hash alle
        Bereiche abdeckt (siehe App\Http\Middleware\SecurityHeaders).
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

    <header class="bg-hvm-textschwarz">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <div class="flex flex-col leading-tight">
                <span class="text-xs font-semibold tracking-widest text-hvm-orange uppercase">Interner Bereich</span>
                <span class="text-lg font-bold text-white">Verwaltung Smart Abrechnen</span>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if ($benutzer !== null)
                    <span class="text-sm text-hvm-hellgrau">
                        Angemeldet als {{ $benutzer->name }}
                    </span>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-hvm.button type="submit" variant="secondary" size="sm">Abmelden</x-hvm.button>
                </form>
            </div>
        </div>

        <nav class="border-t border-hvm-anthrazit" aria-label="Bereichsnavigation">
            <ul class="mx-auto flex max-w-7xl flex-wrap gap-1 px-4 sm:px-6">
                @foreach ($navigation as $eintrag)
                    <li>
                        <a href="{{ route($eintrag['route']) }}"
                           @if (request()->routeIs($eintrag['route'])) aria-current="page" @endif
                           class="block px-3 py-3 text-sm font-medium text-hvm-hellgrau hover:bg-hvm-anthrazit hover:text-white {{ request()->routeIs($eintrag['route']) ? 'border-b-2 border-hvm-orange text-white' : '' }}">
                            {{ $eintrag['label'] }}
                            @if ($eintrag['route'] === 'admin.livegang' && ! $blockerbericht->isClear())
                                <span class="ml-1 rounded-full bg-hvm-orange px-2 py-0.5 text-xs font-bold text-hvm-textschwarz">{{ $blockerbericht->count() }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </header>

    <main id="hauptinhalt" class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        @if ($zweitfaktorFehlt)
            <div class="mb-6">
                <x-hvm.alert variant="warning" label="Achtung" title="Zweiter Faktor nicht eingerichtet">
                    {{ \App\Http\Middleware\RequireAdminTwoFactor::MELDUNG_ZWEITFAKTOR }}
                </x-hvm.alert>
            </div>
        @endif

        @if (session('status'))
            <div class="mb-6">
                <x-hvm.alert variant="success" label="Erledigt">
                    {{ session('status') }}
                </x-hvm.alert>
            </div>
        @endif

        @if (session('hinweis'))
            <div class="mb-6">
                <x-hvm.alert variant="info" label="Hinweis">
                    {{ session('hinweis') }}
                </x-hvm.alert>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6">
                <x-hvm.alert variant="error" label="Bitte prüfen" title="Die Eingabe konnte nicht verarbeitet werden">
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
        <div class="mx-auto max-w-7xl px-4 py-6 text-sm text-hvm-anthrazit sm:px-6">
            <p>
                Interner Bereich der {{ config('smartabrechnen.operator.legal_name') }}. Einblicke in Kundendaten
                erfolgen ausschließlich zu Supportzwecken, verlangen eine Begründung und werden protokolliert.
            </p>
        </div>
    </footer>
</body>
</html>
