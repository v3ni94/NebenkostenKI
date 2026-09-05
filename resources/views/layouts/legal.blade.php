{{--
    VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN

    Layout der Rechtstexte. Seitenkopf nach dem Website-Muster des
    Designsystems, darunter eine schmale Textspalte fuer gute Lesbarkeit. Alle
    Inhalte sind strukturierte Platzhalter. Es werden keine Rechtsinhalte
    formuliert oder ergaenzt. Der Warnhinweis (legal-placeholder-banner) bleibt
    unveraendert und steht sichtbar direkt unter dem Seitenkopf.

    Je Seite zu setzen:
      @section('meta_title')
      @section('meta_description')
      @section('legal_title')    sichtbare Hauptueberschrift
      @section('legal_intro')    optionaler Einleitungssatz
      @section('legal_content')  Gliederung mit Platzhaltern
--}}
@extends('layouts.site')

@section('content')
    {{-- Seitenkopf Website (Designsystem 4.2) in der schmalen Textspalte. --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 pt-16 pb-14 sm:px-6 lg:px-8 lg:pt-24 lg:pb-20">
            <div class="mx-auto max-w-3xl">
                <x-hvm.badge variant="akzent" :icon="false">Rechtliches</x-hvm.badge>

                {{-- legal_title darf &shy; enthalten (weicher Trennstrich fuer lange Komposita, Designsystem 2.2). --}}
                <h1 class="mt-6 text-[2rem] leading-[1.05] font-semibold tracking-tight text-hvm-textschwarz sm:text-5xl">
                    {!! str_replace('&amp;shy;', "\u{00AD}", $__env->yieldContent('legal_title')) !!}
                </h1>

                @hasSection('legal_intro')
                    <p class="mt-7 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer sm:text-xl">
                        @yield('legal_intro')
                    </p>
                @endif

                <x-hvm.legal-placeholder-banner class="mt-10" />
            </div>
        </div>
    </section>

    {{--
        Textkoerper auf Weiss. Die Gliederungspunkte der Rechtstexte sind
        <section> mit h2; die Abstaende und die Ueberschriftenfarbe kommen aus
        den Seiten selbst (Textschwarz, kein Anthrazit).
    --}}
    <section class="border-t border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
            <div class="mx-auto max-w-3xl">
                <div class="space-y-12 text-base leading-relaxed text-hvm-textschwarz">
                    @yield('legal_content')
                </div>

                <div class="mt-16 rounded-2xl border border-hvm-linie bg-hvm-canvas p-6 sm:p-7">
                    <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Stand</p>
                    <p class="mt-2 text-sm leading-relaxed text-hvm-textschwarz">
                        Stand der Gliederung: Entwurfsfassung. Die endgültige Textfassung wird vor dem Livegang anwaltlich
                        geprüft und freigegeben. Bis dahin entfaltet diese Seite keine rechtliche Wirkung.
                    </p>
                </div>
            </div>
        </div>
    </section>
@endsection
