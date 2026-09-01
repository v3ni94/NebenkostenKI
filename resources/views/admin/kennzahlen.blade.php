{{--
    Kennzahlen.

    Datensparsam und ohne externes Tracking. Es gibt keinen Analysetracker und
    kein Zaehlpixel.
--}}
@extends('layouts.admin')

@section('titel', 'Kennzahlen')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Kennzahlen"
        lead="Alle Zahlen entstehen aus den vorhandenen Fachdaten. Es gibt keinen Analysetracker, kein Zählpixel und keine Übermittlung an Dritte." />

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <x-hvm.card title="Umsatz laufender Monat">
            <p class="text-2xl font-semibold">{{ \App\Application\Admin\MetricsOverview::formatCent($monat['umsatz_cent']) }}</p>
            <p class="mt-1 text-sm text-hvm-anthrazit">{{ $monat['zahlungen'] }} Zahlungen</p>
        </x-hvm.card>

        <x-hvm.card title="Umsatz laufendes Jahr">
            <p class="text-2xl font-semibold">{{ \App\Application\Admin\MetricsOverview::formatCent($jahr['umsatz_cent']) }}</p>
            <p class="mt-1 text-sm text-hvm-anthrazit">
                Erstattet: {{ \App\Application\Admin\MetricsOverview::formatCent($jahr['erstattet_cent']) }}
            </p>
        </x-hvm.card>

        <x-hvm.card title="Vorschau zu Zahlung">
            <p class="text-2xl font-semibold">
                {{ $conversion['quote_prozent'] === null ? 'keine Daten' : $conversion['quote_prozent'].' Prozent' }}
            </p>
            <p class="mt-1 text-sm text-hvm-anthrazit">
                {{ $conversion['bezahlt'] }} von {{ $conversion['mit_vorschau'] }} Läufen mit Vorschau
            </p>
        </x-hvm.card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Umsatz je Monat">
            <dl class="space-y-1 text-sm">
                @foreach ($monatsreihe as $monatsname => $cent)
                    <div class="flex justify-between">
                        <dt>{{ $monatsname }}</dt>
                        <dd>{{ \App\Application\Admin\MetricsOverview::formatCent($cent) }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-hvm.card>

        <x-hvm.card title="Abbruchschritte offener Läufe">
            @if ($abbruchschritte === [])
                <p>Kein offener Lauf.</p>
            @else
                <dl class="space-y-1 text-sm">
                    @foreach ($abbruchschritte as $schritt => $anzahl)
                        <div class="flex justify-between"><dt>Schritt {{ $schritt }}</dt><dd>{{ $anzahl }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6">
        @include('admin.partials.statuszahlen', ['titel' => 'Abrechnungsläufe je Status', 'werte' => $laeufe])
    </div>
@endsection
