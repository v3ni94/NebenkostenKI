{{-- Uebersicht des internen Bereichs. Keine Kundendaten. --}}
@extends('layouts.admin')

@section('titel', 'Übersicht')

@section('content')
    <x-hvm.page-header
        eyebrow="Interner Bereich"
        title="Übersicht"
        lead="Stand von Livegang, Datenschutz, Verarbeitung und Zahlungsnachlauf auf einen Blick." />

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-hvm.card title="Livegang" eyebrow="Freigabe" class="min-w-0">
            @if ($bericht->isClear())
                <p class="flex flex-wrap items-center gap-2">
                    <x-hvm.badge variant="success">Erledigt</x-hvm.badge>
                    <span>Es ist kein offener Livegang-Blocker festgestellt.</span>
                </p>
            @else
                <p class="flex flex-wrap items-center gap-2">
                    <x-hvm.badge variant="error">Blockiert</x-hvm.badge>
                    <span>
                        Offene Punkte: <strong>{{ $bericht->count() }}</strong>, davon blockierend
                        <strong>{{ $bericht->blockingCount() }}</strong>.
                    </span>
                </p>
                <ul class="mt-4 space-y-2 text-sm">
                    @foreach ($bericht->blockers as $eintrag)
                        <li class="flex gap-2">
                            <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-hvm-text-sekundaer" aria-hidden="true"></span>
                            <span>{{ $eintrag->area }}: {{ $eintrag->missing }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-5">
                <x-hvm.button href="{{ route('admin.livegang') }}" variant="secondary" size="sm">
                    Livegang-Blocker öffnen
                    <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                </x-hvm.button>
            </p>
        </x-hvm.card>

        <x-hvm.card title="Datenschutz" eyebrow="Löschungen" class="min-w-0">
            @if ($datenschutz['alarm'])
                <x-hvm.alert variant="error" label="Fehler" title="Löschungen prüfen">
                    Fehlgeschlagene Löschungen: {{ $datenschutz['fehlgeschlagene_loeschungen'] }},
                    überfällige temporäre Uploads: {{ $datenschutz['ueberfaellige_uploads'] }}.
                </x-hvm.alert>
            @else
                <p class="flex flex-wrap items-center gap-2">
                    <x-hvm.badge variant="success">Erledigt</x-hvm.badge>
                    <span>Keine überfällige und keine fehlgeschlagene Löschung.</span>
                </p>
            @endif
            <dl class="mt-4 divide-y divide-hvm-linie">
                <x-hvm.kv label="Offene lokale Löschungen">{{ $datenschutz['offene_lokale_loeschungen'] }}</x-hvm.kv>
                <x-hvm.kv label="Offene Providerlöschungen">{{ $datenschutz['offene_providerloeschungen'] }}</x-hvm.kv>
            </dl>
            <p class="mt-5">
                <x-hvm.button href="{{ route('admin.datenschutz') }}" variant="secondary" size="sm">
                    Datenschutzmonitor öffnen
                    <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                </x-hvm.button>
            </p>
        </x-hvm.card>

        <x-hvm.card title="Verarbeitung" eyebrow="Teiljobs" class="min-w-0">
            <p>Wiederholbare Teiljobs: <strong>{{ $wiederholbar }}</strong>.</p>
            <p class="mt-5">
                <x-hvm.button href="{{ route('admin.verarbeitung') }}" variant="secondary" size="sm">
                    Verarbeitung öffnen
                    <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                </x-hvm.button>
            </p>
        </x-hvm.card>

        <x-hvm.card title="Zahlungsnachlauf" eyebrow="Zahlungen" class="min-w-0">
            @if ($zahlungsnachlauf['zahlungen_ohne_lauf'] > 0)
                <x-hvm.alert variant="warning" label="Achtung" title="Zahlungseingang ohne Lauf">
                    Zahlungen ohne freischaltbaren Abrechnungslauf: {{ $zahlungsnachlauf['zahlungen_ohne_lauf'] }}.
                    Erstattung oder Zuordnung ist durch die Geschäftsführung zu entscheiden.
                </x-hvm.alert>
            @endif
            <p class="{{ $zahlungsnachlauf['zahlungen_ohne_lauf'] > 0 ? 'mt-4' : '' }}">
                Offene Fälle nach bestätigter Zahlung: <strong>{{ $zahlungsnachlauf['offene_faelle'] }}</strong>.
            </p>
            <p class="mt-5">
                <x-hvm.button href="{{ route('admin.zahlungsnachlauf') }}" variant="secondary" size="sm">
                    Zahlungsnachlauf öffnen
                    <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                </x-hvm.button>
            </p>
        </x-hvm.card>

        <x-hvm.card title="Geschäftszahlen des laufenden Monats" eyebrow="Kennzahlen" class="min-w-0 lg:col-span-2">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <x-hvm.stat size="sm" tone="canvas" :icon="false" label="Umsatz" :value="\App\Application\Admin\MetricsOverview::formatCent($umsatz['umsatz_cent'])" />
                <x-hvm.stat size="sm" tone="canvas" :icon="false" label="Zahlungen" :value="$umsatz['zahlungen']" />
                <x-hvm.stat size="sm" tone="canvas" :icon="false" label="Vorschau zu Zahlung" :value="$conversion['quote_prozent'] === null ? 'keine Daten' : $conversion['quote_prozent'].' Prozent'" />
            </div>
        </x-hvm.card>
    </div>

    <section class="mt-16" aria-labelledby="ueberschrift-statuszahlen">
        <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Bestand</p>
        <h2 id="ueberschrift-statuszahlen" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Zähler je Status</h2>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
            @include('admin.partials.statuszahlen', ['titel' => 'Abrechnungsläufe je Status', 'werte' => $laeufe])
            @include('admin.partials.statuszahlen', ['titel' => 'Dokumente je Status', 'werte' => $dokumente])
            @include('admin.partials.statuszahlen', ['titel' => 'Teiljobs je Status', 'werte' => $jobs])
        </div>
    </section>
@endsection
