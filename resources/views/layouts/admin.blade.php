{{--
    Layout des internen Adminbereichs.

    Bereichstrennung nach ARCHITECTURE.md, ADR-002: Der Adminbereich teilt sich
    weder Layout noch Navigation mit der Kundenoberflaeche. Es gibt hier
    ausdruecklich kein Kundenmenue, keinen Marketingtext und keinen Verweis in
    den Portalbereich, damit ein Adminview niemals versehentlich wie eine
    Kundenseite aussieht.

    Die Kopfleiste ist dunkel (Graphit, Kontextklasse .hvm-dark nach
    docs/designsystem.md Abschnitt 4.15) und traegt den Hinweis "Interner
    Bereich", damit eine Verwechslung mit der Kundenoberflaeche ausgeschlossen
    ist. Darunter liegt die Bereichsnavigation als umbrechende Liste auf Weiss,
    der Arbeitsbereich liegt auf Canvas.

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
        ['route' => 'admin.dashboard', 'label' => 'Übersicht', 'icon' => 'grid'],
        ['route' => 'admin.livegang', 'label' => 'Livegang-Blocker', 'icon' => 'alert'],
        ['route' => 'admin.datenschutz', 'label' => 'Datenschutz', 'icon' => 'shield'],
        ['route' => 'admin.verarbeitung', 'label' => 'Verarbeitung', 'icon' => 'layers'],
        ['route' => 'admin.ki', 'label' => 'KI', 'icon' => 'sparkle'],
        ['route' => 'admin.zahlungen', 'label' => 'Zahlungen', 'icon' => 'euro'],
        ['route' => 'admin.preise', 'label' => 'Preise', 'icon' => 'receipt'],
        ['route' => 'admin.nutzer', 'label' => 'Nutzer', 'icon' => 'user'],
        ['route' => 'admin.organisationen', 'label' => 'Organisationen', 'icon' => 'building'],
        ['route' => 'admin.kommunikation', 'label' => 'Kommunikation', 'icon' => 'mail'],
        ['route' => 'admin.versionen', 'label' => 'Versionen', 'icon' => 'document'],
        ['route' => 'admin.technik', 'label' => 'Technik', 'icon' => 'key'],
        ['route' => 'admin.kennzahlen', 'label' => 'Kennzahlen', 'icon' => 'list'],
        ['route' => 'admin.protokoll', 'label' => 'Protokoll', 'icon' => 'clock'],
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
<body class="min-h-screen bg-hvm-canvas text-hvm-textschwarz antialiased">
    <a href="#hauptinhalt"
       class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:rounded-full focus:bg-hvm-orange focus:px-5 focus:py-3 focus:font-semibold focus:text-hvm-textschwarz">
        Zum Hauptinhalt springen
    </a>

    {{-- Dunkle Kopfleiste mit Kennlinie: der interne Bereich ist auf den ersten Blick vom Portal unterscheidbar. --}}
    <header class="hvm-dark">
        <div class="hvm-kennlinie" aria-hidden="true"></div>
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-6 gap-y-3 px-4 py-4 sm:px-6 lg:px-8">
            <div class="flex min-w-0 items-center gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-hvm-graphit-soft text-hvm-orange" aria-hidden="true">
                    <x-hvm.icon name="lock" class="h-5 w-5" />
                </span>
                <span class="flex min-w-0 flex-col leading-tight">
                    <span class="text-xs font-semibold tracking-[0.12em] text-hvm-orange uppercase">Interner Bereich</span>
                    <span class="mt-0.5 text-lg font-semibold tracking-tight text-white">Verwaltung Smart Abrechnen</span>
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if ($benutzer !== null)
                    <span class="text-sm text-hvm-hellgrau">
                        Angemeldet als <span class="font-semibold text-white">{{ $benutzer->name }}</span>
                    </span>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-hvm.button type="submit" variant="secondary" size="sm">
                        <x-hvm.icon name="logout" class="h-4 w-4" />
                        Abmelden
                    </x-hvm.button>
                </form>
            </div>
        </div>
    </header>

    {{-- Bereichsnavigation: alle Eintraege sichtbar, umbrechend, kein horizontales Scrollen. --}}
    <nav class="border-b border-hvm-linie bg-white" aria-label="Bereichsnavigation">
        <ul class="mx-auto flex max-w-7xl flex-wrap gap-1 px-4 py-2 sm:px-6 lg:px-8">
            @foreach ($navigation as $eintrag)
                <li>
                    <a href="{{ route($eintrag['route']) }}"
                       @if (request()->routeIs($eintrag['route'])) aria-current="page" @endif
                       class="hvm-nav-item hvm-nav-item-compact">
                        <x-hvm.icon :name="$eintrag['icon']" class="h-4 w-4" />
                        {{ $eintrag['label'] }}
                        @if ($eintrag['route'] === 'admin.livegang' && ! $blockerbericht->isClear())
                            <x-hvm.badge variant="akzent" :icon="false" class="ml-1">{{ $blockerbericht->count() }}</x-hvm.badge>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <main id="hauptinhalt" class="mx-auto w-full max-w-7xl px-4 py-10 sm:px-6 lg:px-8 lg:py-12">
        @if ($zweitfaktorFehlt)
            <div class="mb-8">
                <x-hvm.alert variant="warning" label="Achtung" title="Zweiter Faktor nicht eingerichtet">
                    {{ \App\Http\Middleware\RequireAdminTwoFactor::MELDUNG_ZWEITFAKTOR }}
                </x-hvm.alert>
            </div>
        @endif

        @if (session('status'))
            <div class="mb-8">
                <x-hvm.alert variant="success" label="Erledigt">
                    {{ session('status') }}
                </x-hvm.alert>
            </div>
        @endif

        @if (session('hinweis'))
            <div class="mb-8">
                <x-hvm.alert variant="info" label="Hinweis">
                    {{ session('hinweis') }}
                </x-hvm.alert>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-8">
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

    <footer class="mt-16 border-t border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-10 text-sm leading-relaxed text-hvm-text-sekundaer sm:px-6 lg:px-8">
            <p class="max-w-prose">
                Interner Bereich der {{ config('smartabrechnen.operator.legal_name') }}. Einblicke in Kundendaten
                erfolgen ausschließlich zu Supportzwecken, verlangen eine Begründung und werden protokolliert.
            </p>
        </div>
    </footer>
</body>
</html>
