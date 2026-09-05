{{--
    Gemeinsamer Aufbau der Fehlerseiten (401, 403, 404, 405, 419, 429, 500, 503).

    Die Seiten liegen auf dem oeffentlichen Layout layouts.site und tragen
    damit Navigation, Impressums- und Datenschutzlink sowie die Betreiberangaben
    im Footer. Texte deutsch, in Sie-Ansprache, ohne technische Rohdaten.

    Gestaltung nach dem Seitenkopf-Muster des Designsystems (4.2): Eyebrow mit
    Statuscode und orangem Strich, Ueberschrift in Display-Groesse, Lead in
    Sekundaerfarbe, Buttonreihe mit genau einem Primaerbutton, darunter der
    Kontakthinweis. Der Statuscode ist Text plus Symbol, nie nur Farbe.

    Erwartete Variablen:
      $code      HTTP-Statuscode
      $titel     Ueberschrift
      $text      Erklaerung in einem oder zwei Saetzen
      $hinweis   optionaler weiterer Hinweis
      $zurueck   optionale URL fuer "Zurueck zur vorherigen Seite"
--}}
<section class="bg-hvm-canvas">
    <div class="mx-auto max-w-7xl px-4 pt-16 pb-20 sm:px-6 lg:px-8 lg:pt-24 lg:pb-28">
        <div class="mx-auto max-w-3xl">
            <p class="flex items-center gap-3 text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">
                <span class="inline-block h-px w-8 bg-hvm-orange" aria-hidden="true"></span>
                <span class="inline-flex items-center gap-1.5">
                    <x-hvm.icon name="alert" class="h-4 w-4" />
                    Fehler <span class="tabular">{{ $code }}</span>
                </span>
            </p>

            <h1 class="mt-6 text-4xl leading-[1.05] font-semibold tracking-tight text-hvm-textschwarz sm:text-5xl">{{ $titel }}</h1>

            <p class="mt-7 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer sm:text-xl">{{ $text }}</p>
            @if (! empty($hinweis))
                <p class="mt-4 max-w-prose text-base leading-relaxed text-hvm-textschwarz">{{ $hinweis }}</p>
            @endif

            <div class="mt-10 flex flex-wrap gap-3">
                @if (! empty($zurueck))
                    <x-hvm.button href="{{ $zurueck }}" variant="primary" size="lg">Zurück zur vorherigen Seite</x-hvm.button>
                    <x-hvm.button href="{{ route('site.home') }}" variant="secondary" size="lg">Zur Startseite</x-hvm.button>
                @else
                    <x-hvm.button href="{{ route('site.home') }}" variant="primary" size="lg">Zur Startseite</x-hvm.button>
                @endif
                @auth
                    <x-hvm.button href="{{ route('portal.dashboard') }}" variant="secondary" size="lg">Zur Übersicht in der Anwendung</x-hvm.button>
                @else
                    <x-hvm.button href="{{ url('/app') }}" variant="secondary" size="lg">Anmelden</x-hvm.button>
                @endauth
            </div>

            <div class="mt-14 flex items-start gap-4 rounded-2xl border border-hvm-linie bg-white p-5 sm:p-6">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
                    <x-hvm.icon name="mail" />
                </span>
                <p class="min-w-0 text-sm leading-relaxed text-hvm-textschwarz sm:text-base">
                    Kommen Sie nicht weiter, schreiben Sie uns an
                    <a class="font-medium underline decoration-hvm-orange decoration-2 underline-offset-4" href="mailto:kontakt@smart-abrechnen.de">kontakt@smart-abrechnen.de</a>.
                </p>
            </div>
        </div>
    </div>
</section>
