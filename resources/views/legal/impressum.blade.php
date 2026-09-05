{{--
    VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN

    Platzhalterseite. Die Betreiberangaben stammen ausschliesslich aus
    config('smartabrechnen.operator') und werden weder ergaenzt noch
    abgewandelt. Alle uebrigen Inhalte sind Platzhalter in eckigen Klammern
    und werden durch die anwaltlich freigegebene Textfassung ersetzt.
--}}
@extends('layouts.legal')

@section('meta_title', 'Impressum')
@section('meta_description', 'Impressum von smart-abrechnen.de. Betreiberin ist die Hausverwaltung Müller GmbH. Die Seite ist eine Platzhalterfassung und wird vor dem Livegang anwaltlich freigegeben.')

@section('legal_title', 'Impressum')
@section('legal_intro', 'Die nachfolgenden Betreiberangaben sind verbindlich hinterlegt. Die Gliederung der weiteren Pflichtangaben ist eine Platzhalterfassung.')

@php
    $operator = config('smartabrechnen.operator');
    $platzhalter = $operator['placeholder_text'];
@endphp

@section('legal_content')
    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">Anbieter</h2>
        <p class="mt-3">
            Smart Abrechnen (smart-abrechnen.de) ist eine Marke und ein Dienst der {{ $operator['legal_name'] }}.
            Vertragspartnerin und Betreiberin ist:
        </p>
        <address class="mt-3 not-italic">
            {{ $operator['legal_name'] }}<br>
            {{ $operator['address_line'] }}<br>
            {{ $operator['postal_code'] }} {{ $operator['city'] }}
        </address>
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">Vertretungsberechtigt</h2>
        <p class="mt-3">Geschäftsführer: {{ $operator['managing_director'] }}</p>
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">Registereintrag</h2>
        <dl class="mt-3 space-y-2">
            <div>
                <dt class="inline font-medium">Registergericht:</dt>
                <dd class="inline">{{ $operator['register_court'] }}</dd>
            </div>
            <div>
                <dt class="inline font-medium">Registernummer:</dt>
                <dd class="inline">{{ $operator['register_number'] }}</dd>
            </div>
        </dl>
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">Kontakt</h2>
        <dl class="mt-3 space-y-2">
            <div>
                <dt class="inline font-medium">E-Mail:</dt>
                <dd class="inline">
                    <a href="mailto:kontakt@smart-abrechnen.de" class="font-medium text-hvm-textschwarz underline decoration-hvm-orange decoration-2 underline-offset-4">kontakt@smart-abrechnen.de</a>
                </dd>
            </div>
            <div>
                <dt class="inline font-medium">Website:</dt>
                <dd class="inline">
                    <a href="{{ $operator['website'] }}" class="font-medium text-hvm-textschwarz underline decoration-hvm-orange decoration-2 underline-offset-4">{{ $operator['website'] }}</a>
                </dd>
            </div>
            <div>
                <dt class="inline font-medium">Telefon:</dt>
                <dd class="inline">{{ $platzhalter }}</dd>
            </div>
        </dl>
        <p class="mt-3 text-sm leading-relaxed text-hvm-text-sekundaer">
            Eine Telefonnummer wird erst aufgenommen, wenn die Betreiberin sie ausdrücklich freigibt.
        </p>
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">Steuerliche Angaben</h2>
        <dl class="mt-3 space-y-2">
            <div>
                <dt class="inline font-medium">Umsatzsteuer-Identifikationsnummer:</dt>
                <dd class="inline">
                    @if (filled($operator['vat_id']))
                        {{ $operator['vat_id'] }}
                    @else
                        {{ $platzhalter }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="inline font-medium">Steuernummer:</dt>
                <dd class="inline">
                    @if (filled($operator['tax_id']))
                        {{ $operator['tax_id'] }}
                    @else
                        {{ $platzhalter }}
                    @endif
                </dd>
            </div>
        </dl>

        @if (blank($operator['vat_id']) || blank($operator['tax_id']))
            <div class="mt-5">
                <x-hvm.alert variant="warning" title="Angabe fehlt noch">
                    Die steuerlichen Angaben sind noch nicht hinterlegt. Sie werden von der Betreiberin bestätigt und
                    vor dem Livegang ergänzt. Ob eine Veröffentlichung der Steuernummer erforderlich ist, wird
                    anwaltlich geprüft.
                </x-hvm.alert>
            </div>
        @endif
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">Verantwortlich für den Inhalt</h2>
        <p class="mt-3">[Angabe und Normverweis vor Livegang anwaltlich prüfen und ergänzen]</p>
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">Weitere Pflichtangaben</h2>
        <p class="mt-3">Die folgenden Punkte sind als Gliederung angelegt und noch ohne Text:</p>
        <ul class="mt-4 list-disc space-y-2 pl-5 marker:text-hvm-mittelgrau">
            <li>[Norm und Fundstelle der Anbieterkennzeichnung]</li>
            <li>[Zuständige Aufsichtsbehörde, soweit erforderlich]</li>
            <li>[Berufsrechtliche Angaben, soweit erforderlich]</li>
            <li>[Angaben zur Streitbeilegung und Verbraucherschlichtung]</li>
            <li>[Hinweis zur Haftung für Inhalte]</li>
            <li>[Hinweis zur Haftung für Links]</li>
            <li>[Hinweis zum Urheberrecht und zu Bildnachweisen]</li>
            <li>[Hinweis zur Rolle als Software-Werkzeug und zur Verantwortung des Vermieters]</li>
        </ul>
    </section>

    <section>
        <h2 class="text-2xl font-semibold tracking-tight text-hvm-textschwarz">Rolle der Betreiberin</h2>
        <p class="mt-3">
            Die {{ $operator['legal_name'] }} stellt unter der Marke Smart Abrechnen ein Software-Werkzeug bereit. Absender und
            inhaltlich verantwortlich für die erstellte Betriebskostenabrechnung ist der jeweilige Vermieter
            beziehungsweise Eigentümer. Eine Rechtsberatung im Einzelfall erfolgt nicht.
        </p>
    </section>
@endsection
