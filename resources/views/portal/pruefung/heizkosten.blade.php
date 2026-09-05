@extends('layouts.portal')

@section('titel', 'Heizkosten im Abgleich')

@section('content')
    <x-hvm.page-header
        eyebrow="Prüfung"
        title="Heizkosten im Abgleich"
        lead="Die Tabelle zeigt alle erkannten Heizkostenquellen mit Betrag, Einheit, Zeitraum und der vorgeschlagenen Behandlung."
        :back="route('portal.pruefung.kosten', ['billingRun' => $billingRun->getKey()])"
        backLabel="Zur Kostenprüfung" />

    <div class="mt-8 space-y-4">
        @if ($matrix->blocksFinalization && $matrix->blockingExplanation !== null)
            <x-hvm.alert variant="warning" data-heizkosten="blockiert">
                <p>{{ $matrix->blockingExplanation }}</p>
            </x-hvm.alert>
        @endif

        @if ($matrix->manualSourceConflict && $matrix->manualConflictExplanation !== null)
            <x-hvm.alert variant="warning" data-heizkosten="quellenkonflikt">
                <p>{{ $matrix->manualConflictExplanation }}</p>
            </x-hvm.alert>
        @endif

        @if ($matrix->manualEntryPresent)
            <x-hvm.alert variant="info" data-heizkosten="manuell-erfasst">
                <p>
                    Für diesen Zeitraum sind Heizkosten manuell erfasst. Die Plattform übernimmt die eingetragenen
                    Beträge unverändert als Direktzuordnung je Einheit. Sie prüft und berechnet die Verteilung nach
                    Grund- und Verbrauchskosten sowie die CO2-Kostenaufteilung nicht.
                </p>
            </x-hvm.alert>
        @endif

        @if ($matrix->externalStatementPresent)
            <x-hvm.alert variant="info">
                <p>
                    Es liegt eine externe Heizkostenabrechnung vor. Die Heizkostenposition der Hausgeldabrechnung dient
                    deshalb nur als Vergleichssumme und wird nicht zusätzlich angesetzt. So entsteht keine
                    Doppelzählung.
                </p>
            </x-hvm.alert>
        @endif
    </div>

    @if (! $matrix->hasRows())
        <x-hvm.empty-state class="mt-10" icon="document" title="Keine Heizkostenquellen">
            <p>Es sind keine Heizkostenquellen erkannt.</p>
        </x-hvm.empty-state>
    @else
        <div class="mt-10 overflow-hidden rounded-3xl border border-hvm-linie bg-white">
            <table class="hvm-table hvm-table-zebra hvm-table-stack text-base">
                <caption class="sr-only">Abgleich der Heizkostenquellen</caption>
                <thead>
                    <tr>
                        <th scope="col">Quelle</th>
                        <th scope="col" class="betrag">Betrag</th>
                        <th scope="col">Einheit</th>
                        <th scope="col">Zeitraum</th>
                        <th scope="col">Vorgeschlagene Behandlung</th>
                        <th scope="col">Angesetzt</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($matrix->rows as $zeile)
                        <tr>
                            <th scope="row" class="font-medium">
                                {{ $zeile->sourceKind->label() }}
                                <span class="mt-1 block text-sm font-normal text-hvm-text-sekundaer">{{ $zeile->sourceLabel }}</span>
                            </th>
                            <td class="betrag" data-label="Betrag">
                                {{ $zeile->amountCent === null ? 'nicht erkannt' : number_format($zeile->amountCent / 100, 2, ',', '.').' EUR' }}
                            </td>
                            <td data-label="Einheit">{{ $zeile->unitLabel }}</td>
                            <td data-label="Zeitraum">{{ $zeile->periodLabel }}</td>
                            <td data-label="Vorgeschlagene Behandlung" class="text-hvm-text-sekundaer">{{ $zeile->treatment }}</td>
                            <td data-label="Angesetzt">
                                <span class="inline-flex items-center gap-1.5 font-medium {{ $zeile->applied ? 'text-status-success' : 'text-hvm-text-sekundaer' }}">
                                    <x-hvm.icon :name="$zeile->applied ? 'check-circle' : 'x-circle'" class="h-4 w-4" />
                                    {{ $zeile->applied ? 'Ja' : 'Nein' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($matrix->externalTotalCent !== null && $matrix->lineSumCent !== null)
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-hvm.stat size="sm" :icon="false" label="Ausgewiesener Gesamtbetrag" :value="number_format($matrix->externalTotalCent / 100, 2, ',', '.').' EUR'" />
            <x-hvm.stat size="sm" :icon="false" label="Summe der Einzelbeträge" :value="number_format($matrix->lineSumCent / 100, 2, ',', '.').' EUR'" />
            <x-hvm.stat size="sm" :icon="false" :label="'Abweichung, zulässig '.number_format($matrix->toleranceCent / 100, 2, ',', '.').' EUR'" :value="number_format(($matrix->differenceCent ?? 0) / 100, 2, ',', '.').' EUR'" />
        </div>
    @endif

    @if ($matrix->missing !== [])
        <div class="mt-6 space-y-4">
            @foreach ($matrix->missing as $luecke)
                <x-hvm.alert variant="warning" :title="$luecke->fieldLabel">
                    <p>{{ $luecke->explanation }}</p>
                </x-hvm.alert>
            @endforeach
        </div>
    @endif

    <div class="mt-10 flex flex-wrap gap-3">
        <x-hvm.button href="{{ route('portal.pruefung.kosten', ['billingRun' => $billingRun->getKey()]) }}"
                      variant="secondary">Zurück zur Kostenprüfung</x-hvm.button>
        {{--
            Die Route der manuellen Erfassung wird zentral in routes/portal.php
            eingetragen. Bis dahin bleibt die Schaltflaeche ausgeblendet, damit
            die Seite in jeder Umgebung fehlerfrei rendert.
        --}}
        @if (Route::has('portal.pruefung.heizkosten.erfassung'))
            <x-hvm.button href="{{ route('portal.pruefung.heizkosten.erfassung', ['billingRun' => $billingRun->getKey()]) }}"
                          variant="secondary">Heizkosten selbst erfassen</x-hvm.button>
        @endif
    </div>
@endsection
