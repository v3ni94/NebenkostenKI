{{--
    Gemeinsamer Aufbau der Fehlerseiten (401, 403, 404, 405, 419, 429, 500, 503).

    Die Seiten liegen auf dem oeffentlichen Layout layouts.site und tragen
    damit Navigation, Impressums- und Datenschutzlink sowie die Betreiberangaben
    im Footer. Texte deutsch, in Sie-Ansprache, ohne technische Rohdaten.

    Erwartete Variablen:
      $code      HTTP-Statuscode
      $titel     Ueberschrift
      $text      Erklaerung in einem oder zwei Saetzen
      $hinweis   optionaler weiterer Hinweis
      $zurueck   optionale URL fuer "Zurueck zur vorherigen Seite"
--}}
<section class="mx-auto max-w-3xl px-4 py-16 sm:px-6 sm:py-24">
    <p class="text-sm font-semibold tracking-wide text-hvm-anthrazit uppercase">Fehler {{ $code }}</p>
    <h1 class="mt-3 text-3xl font-bold text-hvm-anthrazit sm:text-4xl">{{ $titel }}</h1>
    <p class="mt-6 text-lg text-hvm-textschwarz">{{ $text }}</p>
    @if (! empty($hinweis))
        <p class="mt-3 text-base text-hvm-textschwarz">{{ $hinweis }}</p>
    @endif

    <div class="mt-10 flex flex-wrap gap-3">
        @if (! empty($zurueck))
            <x-hvm.button href="{{ $zurueck }}" variant="primary">Zurück zur vorherigen Seite</x-hvm.button>
            <x-hvm.button href="{{ route('site.home') }}" variant="secondary">Zur Startseite</x-hvm.button>
        @else
            <x-hvm.button href="{{ route('site.home') }}" variant="primary">Zur Startseite</x-hvm.button>
        @endif
        @auth
            <x-hvm.button href="{{ route('portal.dashboard') }}" variant="secondary">Zur Übersicht in der Anwendung</x-hvm.button>
        @else
            <x-hvm.button href="{{ url('/app') }}" variant="secondary">Anmelden</x-hvm.button>
        @endauth
    </div>

    <p class="mt-10 text-sm text-hvm-textschwarz">
        Kommen Sie nicht weiter, schreiben Sie uns an
        <a class="font-medium underline underline-offset-2" href="mailto:kontakt@smart-abrechnen.de">kontakt@smart-abrechnen.de</a>.
    </p>
</section>
