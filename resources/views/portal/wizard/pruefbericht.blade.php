@extends('layouts.portal')

@section('titel', 'Prüfbericht')

@section('content')
    <x-hvm.page-header
        :eyebrow="$schritt->eyebrow()"
        title="Prüfbericht"
        lead="Vor der Vorschau prüfen wir Ihre Angaben vollständig. Die Ergebnisse stehen in vier Gruppen.">
        <p class="text-sm text-hvm-text-sekundaer">
            Angewendeter Regelstand: <span class="font-semibold text-hvm-textschwarz">{{ $regelstand }}</span>. Der Regelstand richtet sich nach dem Abrechnungszeitraum, damit
            alte Abrechnungen nachvollziehbar bleiben.
        </p>
    </x-hvm.page-header>

    <div class="mt-8">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $fortschritt,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    @if ($sperrgrund !== null)
        <x-hvm.alert variant="error" class="mt-8" label="Blockiert die Abrechnung">
            <p>{{ $sperrgrund }}</p>
        </x-hvm.alert>
    @endif

    <div class="mt-10 space-y-4">
        @foreach ($stufen as $stufe)
            @php
                $eintraege = $gruppen[$stufe->value] ?? [];
                $zahl = $anzahl[$stufe->value] ?? 0;
                // Variante je Stufe; das Symbol kommt aus der einen Statuszuordnung (App\Support\Statussymbol).
                $variante = match ($stufe->value) {
                    'BLOCKER' => 'error',
                    'WARNUNG' => 'warning',
                    'HINWEIS' => 'info',
                    default => 'success',
                };
                $symbolfarbe = match ($variante) {
                    'error' => 'bg-status-error-soft text-status-error',
                    'warning' => 'bg-status-warning-soft text-status-warning',
                    'info' => 'bg-status-info-soft text-status-info',
                    default => 'bg-status-success-soft text-status-success',
                };
                // Die Gruppe "Bestanden" ist ohne Handlungsbedarf und wird eingeklappt (details),
                // ohne JavaScript per Klick, mit JavaScript ebenso: Progressive Enhancement.
                $einklappbar = $stufe->value === 'BESTANDEN' && $eintraege !== [];
            @endphp

            <x-hvm.card padding="none">
                <div class="flex gap-4 p-5 sm:p-6">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $symbolfarbe }}" aria-hidden="true">
                        <x-hvm.icon :name="\App\Support\Statussymbol::fuer($variante)" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">{{ $stufe->label() }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-hvm-text-sekundaer">
                            <span class="font-semibold text-hvm-textschwarz tabular">{{ $zahl }}</span> {{ $zahl === 1 ? 'Ergebnis.' : 'Ergebnisse.' }}
                            @if ($stufe->value === 'BLOCKER')
                                {{ $zahl === 1 ? 'Dieses Ergebnis verhindert die Abrechnung.' : 'Diese Ergebnisse verhindern die Abrechnung.' }}
                            @elseif ($stufe->value === 'WARNUNG')
                                Eine Warnung ist nur mit Ihrer ausdrücklichen Entscheidung auflösbar.
                            @elseif ($stufe->value === 'HINWEIS')
                                Hinweise sind informativ und verhindern nichts.
                            @else
                                Diese Prüfschritte sind bestanden.
                            @endif
                        </p>
                    </div>
                </div>

                @if ($eintraege === [])
                    <p class="border-t border-hvm-linie px-5 py-4 text-sm text-hvm-text-sekundaer sm:px-6">Es liegen keine Ergebnisse in dieser Gruppe vor.</p>
                @else
                    @if ($einklappbar)
                        <details class="group border-t border-hvm-linie">
                            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 text-sm font-semibold text-hvm-textschwarz hover:bg-hvm-canvas sm:px-6">
                                <span>{{ $zahl === 1 ? 'Bestandenen Prüfschritt anzeigen' : 'Bestandene Prüfschritte anzeigen' }}</span>
                                <x-hvm.icon name="chevron-down" class="h-4 w-4 shrink-0 text-hvm-text-sekundaer transition-transform group-open:rotate-180" />
                            </summary>
                    @endif
                    <ul class="divide-y divide-hvm-linie border-t border-hvm-linie">
                        @foreach ($eintraege as $eintrag)
                            <li class="px-5 py-5 sm:px-6">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <p class="font-semibold text-hvm-textschwarz">{{ $eintrag->title }}</p>
                                    <x-hvm.badge :variant="$eintrag->status->value === 'OFFEN' ? 'neutral' : 'success'" :icon="$eintrag->status->value === 'OFFEN' ? 'clock' : null">{{ $eintrag->status->label() }}</x-hvm.badge>
                                </div>
                                <p class="mt-1 max-w-prose text-sm leading-relaxed text-hvm-textschwarz">{{ $eintrag->description }}</p>
                                {{-- Technische Angaben nur fuer den Support, zusammengeklappt; der Regelcode ist kein Nutzertext. --}}
                                <details class="mt-2 text-xs text-hvm-text-sekundaer">
                                    <summary class="inline-flex min-h-11 cursor-pointer items-center gap-1.5 font-medium underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz">Technische Angaben</summary>
                                    <p class="mt-1">
                                        Regel <code class="font-mono [overflow-wrap:anywhere]">{{ $eintrag->rule_code }}</code>,
                                        Stand <span class="tabular">{{ $eintrag->rule_version }}</span>
                                    </p>
                                </details>

                                @if ($eintrag->resolution !== null)
                                    <p class="mt-3 rounded-2xl bg-hvm-canvas p-4 text-sm text-hvm-textschwarz">Ihre Entscheidung: {{ $eintrag->resolution }}</p>
                                @endif

                                @if ($stufe->value === 'WARNUNG' && $eintrag->status->value === 'OFFEN')
                                    <form method="POST" class="mt-4 space-y-4 rounded-2xl bg-hvm-canvas p-4 sm:p-5"
                                          action="{{ route('portal.wizard.pruefbericht.entscheiden', ['billingRun' => $billingRun->getKey(), 'issue' => $eintrag->getKey()]) }}">
                                        @csrf
                                        <x-hvm.field name="entscheidung" :id="'entscheidung-'.$eintrag->getKey()" label="Ihre Entscheidung" type="textarea" rows="2" :required="true"
                                                     placeholder="Bitte begründen Sie kurz, warum Sie fortfahren." value="" />
                                        <x-hvm.button type="submit" variant="secondary" size="sm">
                                            Entscheidung protokollieren
                                        </x-hvm.button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($einklappbar)
                        </details>
                    @endif
                @endif
            </x-hvm.card>
        @endforeach
    </div>

    <form method="POST" class="mt-10"
          action="{{ route('portal.wizard.pruefbericht.weiter', ['billingRun' => $billingRun->getKey()]) }}">
        @csrf
        @if ($weiterMoeglich)
            <x-hvm.button type="submit" variant="primary">
                Weiter zur Vorschau
                <x-hvm.icon name="arrow-right" class="h-4 w-4" />
            </x-hvm.button>
        @else
            <p class="flex items-start gap-1.5 text-sm font-medium text-status-error">
                <x-hvm.icon :name="\App\Support\Statussymbol::ERROR" class="mt-0.5 h-4 w-4" />
                <span>Solange Blocker offen sind, kann die Vorschau nicht erzeugt werden.</span>
            </p>
        @endif
    </form>
@endsection
