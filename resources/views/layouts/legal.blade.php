{{--
    VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN

    Layout der Rechtstexte. Schmale Textspalte fuer gute Lesbarkeit. Alle
    Inhalte sind strukturierte Platzhalter. Es werden keine Rechtsinhalte
    formuliert oder ergaenzt.

    Je Seite zu setzen:
      @section('meta_title')
      @section('meta_description')
      @section('legal_title')    sichtbare Hauptueberschrift
      @section('legal_intro')    optionaler Einleitungssatz
      @section('legal_content')  Gliederung mit Platzhaltern
--}}
@extends('layouts.site')

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
        <x-hvm.legal-placeholder-banner />

        <h1 class="mt-8 text-3xl font-bold text-hvm-anthrazit sm:text-4xl">
            @yield('legal_title')
        </h1>

        @hasSection('legal_intro')
            <p class="mt-4 text-base text-hvm-textschwarz">
                @yield('legal_intro')
            </p>
        @endif

        <div class="mt-8 space-y-8 text-base leading-relaxed text-hvm-textschwarz">
            @yield('legal_content')
        </div>

        <div class="mt-12 border-t border-hvm-mittelgrau pt-6">
            <p class="text-sm text-hvm-textschwarz">
                Stand der Gliederung: Entwurfsfassung. Die endgültige Textfassung wird vor dem Livegang anwaltlich
                geprüft und freigegeben. Bis dahin entfaltet diese Seite keine rechtliche Wirkung.
            </p>
        </div>
    </div>
@endsection
