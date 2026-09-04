{{-- Uebersicht des internen Bereichs. Keine Kundendaten. --}}
@extends('layouts.admin')

@section('titel', 'Übersicht')

@section('content')
    <x-hvm.section-heading level="h1" title="Übersicht" />

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Livegang" accent>
            @if ($bericht->isClear())
                <p>Es ist kein offener Livegang-Blocker festgestellt.</p>
            @else
                <p>
                    Offene Punkte: <strong>{{ $bericht->count() }}</strong>, davon blockierend
                    <strong>{{ $bericht->blockingCount() }}</strong>.
                </p>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($bericht->blockers as $eintrag)
                        <li>{{ $eintrag->area }}: {{ $eintrag->missing }}</li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-4">
                <x-hvm.button href="{{ route('admin.livegang') }}" variant="secondary" size="sm">
                    Livegang-Blocker öffnen
                </x-hvm.button>
            </p>
        </x-hvm.card>

        <x-hvm.card title="Datenschutz">
            @if ($datenschutz['alarm'])
                <x-hvm.alert variant="error" label="Fehler" title="Löschungen prüfen">
                    Fehlgeschlagene Löschungen: {{ $datenschutz['fehlgeschlagene_loeschungen'] }},
                    überfällige temporäre Uploads: {{ $datenschutz['ueberfaellige_uploads'] }}.
                </x-hvm.alert>
            @else
                <p>Keine überfällige und keine fehlgeschlagene Löschung.</p>
            @endif
            <dl class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between"><dt>Offene lokale Löschungen</dt><dd>{{ $datenschutz['offene_lokale_loeschungen'] }}</dd></div>
                <div class="flex justify-between"><dt>Offene Providerlöschungen</dt><dd>{{ $datenschutz['offene_providerloeschungen'] }}</dd></div>
            </dl>
            <p class="mt-4">
                <x-hvm.button href="{{ route('admin.datenschutz') }}" variant="secondary" size="sm">
                    Datenschutzmonitor öffnen
                </x-hvm.button>
            </p>
        </x-hvm.card>

        <x-hvm.card title="Verarbeitung">
            <p>Wiederholbare Teiljobs: <strong>{{ $wiederholbar }}</strong>.</p>
            <p class="mt-4">
                <x-hvm.button href="{{ route('admin.verarbeitung') }}" variant="secondary" size="sm">
                    Verarbeitung öffnen
                </x-hvm.button>
            </p>
        </x-hvm.card>

        <x-hvm.card title="Zahlungsnachlauf">
            @if ($zahlungsnachlauf['zahlungen_ohne_lauf'] > 0)
                <x-hvm.alert variant="warning" label="Achtung" title="Zahlungseingang ohne Lauf">
                    Zahlungen ohne freischaltbaren Abrechnungslauf: {{ $zahlungsnachlauf['zahlungen_ohne_lauf'] }}.
                    Erstattung oder Zuordnung ist durch die Geschäftsführung zu entscheiden.
                </x-hvm.alert>
            @endif
            <p class="{{ $zahlungsnachlauf['zahlungen_ohne_lauf'] > 0 ? 'mt-3' : '' }}">
                Offene Fälle nach bestätigter Zahlung: <strong>{{ $zahlungsnachlauf['offene_faelle'] }}</strong>.
            </p>
            <p class="mt-4">
                <x-hvm.button href="{{ route('admin.zahlungsnachlauf') }}" variant="secondary" size="sm">
                    Zahlungsnachlauf öffnen
                </x-hvm.button>
            </p>
        </x-hvm.card>

        <x-hvm.card title="Geschäftszahlen des laufenden Monats">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>Umsatz</dt>
                    <dd>{{ \App\Application\Admin\MetricsOverview::formatCent($umsatz['umsatz_cent']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Zahlungen</dt>
                    <dd>{{ $umsatz['zahlungen'] }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Vorschau zu Zahlung</dt>
                    <dd>{{ $conversion['quote_prozent'] === null ? 'keine Daten' : $conversion['quote_prozent'].' Prozent' }}</dd>
                </div>
            </dl>
        </x-hvm.card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        @include('admin.partials.statuszahlen', ['titel' => 'Abrechnungsläufe je Status', 'werte' => $laeufe])
        @include('admin.partials.statuszahlen', ['titel' => 'Dokumente je Status', 'werte' => $dokumente])
        @include('admin.partials.statuszahlen', ['titel' => 'Teiljobs je Status', 'werte' => $jobs])
    </div>
@endsection
