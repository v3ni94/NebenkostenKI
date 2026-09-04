@extends('layouts.portal')

@section('titel', 'Automatische Analyse')

@section('content')
    <x-hvm.section-heading
        eyebrow="Schritt 3"
        title="Automatische Analyse"
        lead="Ihre Unterlagen werden ausgelesen und den Kostenarten zugeordnet. Sie können die Seite verlassen und später fortfahren." />

    <div class="mt-6">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $schritte,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    <x-hvm.card class="mt-8" title="Stand der Auswertung">
        <p class="text-sm">
            {{ $fortschritt->percent() }} Prozent der Unterlagen sind ausgewertet.
        </p>

        <ul class="mt-4 space-y-2">
            @foreach ($fortschritt->lines as $zeile)
                <li class="flex items-start gap-2">
                    <span aria-hidden="true" class="mt-2 inline-block h-2 w-2 shrink-0 rounded-full bg-hvm-orange"></span>
                    <span>{{ $zeile }}</span>
                </li>
            @endforeach
        </ul>

        @if (! $fortschritt->complete)
            <p class="mt-4 text-sm text-hvm-anthrazit">
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

    <x-hvm.card class="mt-6" title="Vorgeschlagener Abrechnungsweg">
        <p>{{ $wegvorschlag->suggested->label() }}</p>

        <ul class="mt-3 space-y-1 text-sm">
            @foreach ($wegvorschlag->reasons as $grund)
                <li>{{ $grund }}</li>
            @endforeach
        </ul>

        <div class="mt-4">
            <x-hvm.button href="{{ route('portal.pruefung.weg.edit', ['billingRun' => $billingRun->getKey()]) }}"
                          variant="secondary" size="sm">Abrechnungsweg prüfen</x-hvm.button>
        </div>
    </x-hvm.card>

    <x-hvm.alert variant="info" class="mt-6" label="Hinweis">
        <p>
            Ihre Originaldateien wurden nach der Auswertung gelöscht. Gespeichert sind nur die ausgelesenen
            Inhaltsdaten mit Quellenangabe. Bitte halten Sie Ihre eigenen Belege bereit, wenn Sie einen Wert
            vergleichen möchten.
        </p>
    </x-hvm.alert>

    <form method="POST" action="{{ route('portal.pruefung.zuordnen', ['billingRun' => $billingRun->getKey()]) }}"
          class="mt-8">
        @csrf
        <x-hvm.button type="submit" variant="primary">Kostenpositionen zuordnen</x-hvm.button>
    </form>
@endsection
