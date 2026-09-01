@extends('layouts.portal')

@section('titel', 'Prüfbericht')

@section('content')
    <x-hvm.section-heading
        title="Schritt 9: Prüfbericht"
        lead="Vor der Vorschau prüfen wir Ihre Angaben vollständig. Die Ergebnisse stehen in vier Gruppen." />

    <div class="mt-6">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $fortschritt,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    <p class="mt-4 text-sm text-hvm-anthrazit">
        Angewendeter Regelstand: {{ $regelstand }}. Der Regelstand richtet sich nach dem Abrechnungszeitraum, damit
        alte Abrechnungen nachvollziehbar bleiben.
    </p>

    @if ($sperrgrund !== null)
        <x-hvm.alert variant="error" class="mt-6" label="Blockiert die Abrechnung">
            <p>{{ $sperrgrund }}</p>
        </x-hvm.alert>
    @endif

    @foreach ($stufen as $stufe)
        @php($eintraege = $gruppen[$stufe->value] ?? [])

        <x-hvm.card class="mt-6" :title="$stufe->label()">
            <p class="text-sm text-hvm-anthrazit">
                {{ $anzahl[$stufe->value] ?? 0 }} Ergebnisse.
                @if ($stufe->value === 'BLOCKER')
                    Diese Ergebnisse verhindern die Abrechnung.
                @elseif ($stufe->value === 'WARNUNG')
                    Eine Warnung ist nur mit Ihrer ausdrücklichen Entscheidung auflösbar.
                @elseif ($stufe->value === 'HINWEIS')
                    Hinweise sind informativ und verhindern nichts.
                @else
                    Diese Prüfschritte sind bestanden.
                @endif
            </p>

            @if ($eintraege === [])
                <p class="mt-3">Es liegen keine Ergebnisse in dieser Gruppe vor.</p>
            @else
                <ul class="mt-3 space-y-4">
                    @foreach ($eintraege as $eintrag)
                        <li class="border-t border-hvm-umrissgrau pt-3">
                            <p class="font-semibold">{{ $eintrag->title }}</p>
                            <p class="mt-1 text-sm">{{ $eintrag->description }}</p>
                            <p class="mt-1 text-sm text-hvm-anthrazit">
                                Regel {{ $eintrag->rule_code }}, Stand {{ $eintrag->rule_version }},
                                Status {{ $eintrag->status->label() }}
                            </p>

                            @if ($eintrag->resolution !== null)
                                <p class="mt-1 text-sm">Ihre Entscheidung: {{ $eintrag->resolution }}</p>
                            @endif

                            @if ($stufe->value === 'WARNUNG' && $eintrag->status->value === 'OFFEN')
                                <form method="POST" class="mt-3"
                                      action="{{ route('portal.wizard.pruefbericht.entscheiden', ['billingRun' => $billingRun->getKey(), 'issue' => $eintrag->getKey()]) }}">
                                    @csrf
                                    <label class="block">
                                        <span class="text-sm font-semibold">Ihre Entscheidung</span>
                                        <textarea name="entscheidung" rows="2" required
                                                  class="mt-1 block w-full rounded border border-hvm-mittelgrau p-2"
                                                  placeholder="Bitte begründen Sie kurz, warum Sie fortfahren."></textarea>
                                    </label>
                                    <x-hvm.button type="submit" variant="secondary" size="sm" class="mt-2">
                                        Entscheidung protokollieren
                                    </x-hvm.button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-hvm.card>
    @endforeach

    <form method="POST" class="mt-6"
          action="{{ route('portal.wizard.pruefbericht.weiter', ['billingRun' => $billingRun->getKey()]) }}">
        @csrf
        @if ($weiterMoeglich)
            <x-hvm.button type="submit" variant="primary">Weiter zur Vorschau</x-hvm.button>
        @else
            <p class="text-sm text-status-error">
                Solange Blocker offen sind, kann die Vorschau nicht erzeugt werden.
            </p>
        @endif
    </form>
@endsection
