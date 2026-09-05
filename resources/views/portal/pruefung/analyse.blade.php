@extends('layouts.portal')

@section('titel', 'Automatische Analyse')

@section('content')
    <x-hvm.page-header
        eyebrow="Schritt 3 von 10"
        title="Automatische Analyse"
        lead="Ihre Unterlagen werden ausgelesen und den Kostenarten zugeordnet. Sie können die Seite verlassen und später fortfahren." />

    <div class="mt-8">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $schritte,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-hvm.card class="min-w-0" title="Stand der Auswertung" eyebrow="Auswertung">
            <p class="text-sm text-hvm-text-sekundaer">
                {{ $fortschritt->percent() }} Prozent der Unterlagen sind ausgewertet.
            </p>

            {{-- Fortschrittsbalken als zusaetzliche Darstellung des Prozentwerts (Orange als Fortschrittsfarbe). --}}
            <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-hvm-canvas-deep" aria-hidden="true">
                <div class="h-1.5 rounded-full bg-hvm-orange" style="width: {{ max(0, min(100, $fortschritt->percent())) }}%"></div>
            </div>

            <ul class="mt-5 space-y-2 text-sm leading-relaxed text-hvm-textschwarz">
                @foreach ($fortschritt->lines as $zeile)
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-hvm-orange"></span>
                        <span>{{ $zeile }}</span>
                    </li>
                @endforeach
            </ul>

            @if (! $fortschritt->complete)
                <p class="mt-5 text-sm leading-relaxed text-hvm-text-sekundaer">
                    Die Auswertung läuft im Hintergrund in festen Abständen. Neu hochgeladene Unterlagen werden
                    spätestens nach {{ $intervallMinuten }} Minuten verarbeitet. Bitte laden Sie diese Seite neu, um den
                    aktuellen Stand zu sehen.
                </p>
            @endif

            @if ($fortschritt->blockingChecks > 0)
                <x-hvm.alert variant="warning" class="mt-6">
                    <p>
                        Einige Punkte blockieren die Abrechnung. Sie finden sie in der Kostenprüfung und im Prüfbericht.
                    </p>
                </x-hvm.alert>
            @endif

            @if ($fortschritt->documentsFailed > 0)
                <x-hvm.alert variant="info" class="mt-4">
                    <p>
                        Nicht jede Unterlage lässt sich maschinell auswerten. Sie können die betroffenen Werte manuell
                        erfassen oder die Unterlage erneut zur Auswertung hochladen.
                    </p>
                </x-hvm.alert>
            @endif
        </x-hvm.card>

        <x-hvm.card class="min-w-0" title="Vorgeschlagener Abrechnungsweg" eyebrow="Einordnung">
            <p class="font-semibold text-hvm-textschwarz">{{ $wegvorschlag->suggested->label() }}</p>

            <ul class="mt-3 space-y-2 text-sm leading-relaxed text-hvm-text-sekundaer">
                @foreach ($wegvorschlag->reasons as $grund)
                    <li class="flex gap-2">
                        <span aria-hidden="true" class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-hvm-text-sekundaer"></span>
                        <span>{{ $grund }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="mt-5">
                <x-hvm.button href="{{ route('portal.pruefung.weg.edit', ['billingRun' => $billingRun->getKey()]) }}"
                              variant="secondary" size="sm">
                    Abrechnungsweg prüfen
                    <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                </x-hvm.button>
            </div>
        </x-hvm.card>
    </div>

    <x-hvm.alert variant="info" class="mt-6" label="Hinweis">
        <p>
            Ihre Originaldateien wurden nach der Auswertung gelöscht. Gespeichert sind nur die ausgelesenen
            Inhaltsdaten mit Quellenangabe. Bitte halten Sie Ihre eigenen Belege bereit, wenn Sie einen Wert
            vergleichen möchten.
        </p>
    </x-hvm.alert>

    <form method="POST" action="{{ route('portal.pruefung.zuordnen', ['billingRun' => $billingRun->getKey()]) }}"
          class="mt-10 flex flex-wrap gap-3">
        @csrf
        <x-hvm.button type="submit" variant="primary">
            Kostenpositionen zuordnen
            <x-hvm.icon name="arrow-right" class="h-4 w-4" />
        </x-hvm.button>
    </form>
@endsection
