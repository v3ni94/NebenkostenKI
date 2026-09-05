{{--
    Kennzahlen.

    Datensparsam und ohne externes Tracking. Es gibt keinen Analysetracker und
    kein Zaehlpixel.
--}}
@extends('layouts.admin')

@section('titel', 'Kennzahlen')

@section('content')
    <x-hvm.page-header
        eyebrow="Kennzahlen"
        title="Kennzahlen"
        lead="Alle Zahlen entstehen aus den vorhandenen Fachdaten. Es gibt keinen Analysetracker, kein Zählpixel und keine Übermittlung an Dritte." />

    <div class="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-hvm.stat
            label="Umsatz laufender Monat"
            :value="\App\Application\Admin\MetricsOverview::formatCent($monat['umsatz_cent'])"
            icon="euro"
            :note="$monat['zahlungen'].' Zahlungen'" />

        <x-hvm.stat
            label="Umsatz laufendes Jahr"
            :value="\App\Application\Admin\MetricsOverview::formatCent($jahr['umsatz_cent'])"
            icon="calendar"
            :note="'Erstattet: '.\App\Application\Admin\MetricsOverview::formatCent($jahr['erstattet_cent'])" />

        <x-hvm.stat
            label="Vorschau zu Zahlung"
            :value="$conversion['quote_prozent'] === null ? 'keine Daten' : $conversion['quote_prozent'].' Prozent'"
            icon="receipt"
            :note="$conversion['bezahlt'].' von '.$conversion['mit_vorschau'].' Läufen mit Vorschau'" />
    </div>

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-hvm.card title="Umsatz je Monat" eyebrow="Verlauf" class="min-w-0">
            <dl class="divide-y divide-hvm-linie">
                @foreach ($monatsreihe as $monatsname => $cent)
                    <x-hvm.kv :label="$monatsname">{{ \App\Application\Admin\MetricsOverview::formatCent($cent) }}</x-hvm.kv>
                @endforeach
            </dl>
        </x-hvm.card>

        <x-hvm.card title="Abbruchschritte offener Läufe" eyebrow="Ablauf" class="min-w-0">
            @if ($abbruchschritte === [])
                <p class="text-sm text-hvm-text-sekundaer">Kein offener Lauf.</p>
            @else
                <dl class="divide-y divide-hvm-linie">
                    @foreach ($abbruchschritte as $schritt => $anzahl)
                        <x-hvm.kv :label="'Schritt '.$schritt">{{ $anzahl }}</x-hvm.kv>
                    @endforeach
                </dl>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-10">
        @include('admin.partials.statuszahlen', ['titel' => 'Abrechnungsläufe je Status', 'werte' => $laeufe])
    </div>
@endsection
