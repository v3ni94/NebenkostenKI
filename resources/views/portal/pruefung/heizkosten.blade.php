@extends('layouts.portal')

@section('titel', 'Heizkosten im Abgleich')

@section('content')
    <x-hvm.section-heading
        title="Heizkosten im Abgleich"
        lead="Die Tabelle zeigt alle erkannten Heizkostenquellen mit Betrag, Einheit, Zeitraum und der vorgeschlagenen Behandlung." />

    @if ($matrix->blocksFinalization && $matrix->blockingExplanation !== null)
        <x-hvm.alert variant="warning" class="mt-6" data-heizkosten="blockiert">
            <p>{{ $matrix->blockingExplanation }}</p>
        </x-hvm.alert>
    @endif

    @if ($matrix->manualSourceConflict && $matrix->manualConflictExplanation !== null)
        <x-hvm.alert variant="warning" class="mt-6" data-heizkosten="quellenkonflikt">
            <p>{{ $matrix->manualConflictExplanation }}</p>
        </x-hvm.alert>
    @endif

    @if ($matrix->manualEntryPresent)
        <x-hvm.alert variant="info" class="mt-6" data-heizkosten="manuell-erfasst">
            <p>
                Für diesen Zeitraum sind Heizkosten manuell erfasst. Die Plattform übernimmt die eingetragenen
                Beträge unverändert als Direktzuordnung je Einheit. Sie prüft und berechnet die Verteilung nach
                Grund- und Verbrauchskosten sowie die CO2-Kostenaufteilung nicht.
            </p>
        </x-hvm.alert>
    @endif

    @if ($matrix->externalStatementPresent)
        <x-hvm.alert variant="info" class="mt-6">
            <p>
                Es liegt eine externe Heizkostenabrechnung vor. Die Heizkostenposition der Hausgeldabrechnung dient
                deshalb nur als Vergleichssumme und wird nicht zusätzlich angesetzt. So entsteht keine
                Doppelzählung.
            </p>
        </x-hvm.alert>
    @endif

    <x-hvm.card class="mt-6">
        @if (! $matrix->hasRows())
            <p>Es sind keine Heizkostenquellen erkannt.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[46rem] border-collapse text-sm">
                    <caption class="sr-only">Abgleich der Heizkostenquellen</caption>
                    <thead>
                        <tr class="border-b border-hvm-mittelgrau text-left">
                            <th scope="col" class="py-2 pr-4">Quelle</th>
                            <th scope="col" class="py-2 pr-4 text-right">Betrag</th>
                            <th scope="col" class="py-2 pr-4">Einheit</th>
                            <th scope="col" class="py-2 pr-4">Zeitraum</th>
                            <th scope="col" class="py-2 pr-4">Vorgeschlagene Behandlung</th>
                            <th scope="col" class="py-2">Angesetzt</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matrix->rows as $zeile)
                            <tr class="border-b border-hvm-hellgrau">
                                <td class="py-2 pr-4">
                                    {{ $zeile->sourceKind->label() }}<br />
                                    <span class="text-xs">{{ $zeile->sourceLabel }}</span>
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    {{ $zeile->amountCent === null ? 'nicht erkannt' : number_format($zeile->amountCent / 100, 2, ',', '.').' EUR' }}
                                </td>
                                <td class="py-2 pr-4">{{ $zeile->unitLabel }}</td>
                                <td class="py-2 pr-4">{{ $zeile->periodLabel }}</td>
                                <td class="py-2 pr-4">{{ $zeile->treatment }}</td>
                                <td class="py-2">{{ $zeile->applied ? 'Ja' : 'Nein' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($matrix->externalTotalCent !== null && $matrix->lineSumCent !== null)
            <dl class="mt-6 grid gap-3 sm:grid-cols-3">
                <div>
                    <dt class="text-sm">Ausgewiesener Gesamtbetrag</dt>
                    <dd class="font-semibold">{{ number_format($matrix->externalTotalCent / 100, 2, ',', '.') }} EUR</dd>
                </div>
                <div>
                    <dt class="text-sm">Summe der Einzelbeträge</dt>
                    <dd class="font-semibold">{{ number_format($matrix->lineSumCent / 100, 2, ',', '.') }} EUR</dd>
                </div>
                <div>
                    <dt class="text-sm">Abweichung, zulässig {{ number_format($matrix->toleranceCent / 100, 2, ',', '.') }} EUR</dt>
                    <dd class="font-semibold">{{ number_format(($matrix->differenceCent ?? 0) / 100, 2, ',', '.') }} EUR</dd>
                </div>
            </dl>
        @endif
    </x-hvm.card>

    @foreach ($matrix->missing as $luecke)
        <x-hvm.alert variant="warning" class="mt-4">
            <p class="font-semibold">{{ $luecke->fieldLabel }}</p>
            <p class="mt-2">{{ $luecke->explanation }}</p>
        </x-hvm.alert>
    @endforeach

    <div class="mt-8 flex flex-wrap gap-3">
        <x-hvm.button href="{{ route('portal.pruefung.kosten', ['billingRun' => $billingRun->getKey()]) }}"
                      variant="secondary" size="sm">Zurück zur Kostenprüfung</x-hvm.button>
        {{--
            Die Route der manuellen Erfassung wird zentral in routes/portal.php
            eingetragen. Bis dahin bleibt die Schaltflaeche ausgeblendet, damit
            die Seite in jeder Umgebung fehlerfrei rendert.
        --}}
        @if (Route::has('portal.pruefung.heizkosten.erfassung'))
            <x-hvm.button href="{{ route('portal.pruefung.heizkosten.erfassung', ['billingRun' => $billingRun->getKey()]) }}"
                          variant="secondary" size="sm">Heizkosten selbst erfassen</x-hvm.button>
        @endif
    </div>
@endsection
