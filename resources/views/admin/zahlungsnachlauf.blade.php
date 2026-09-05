{{--
    Nachlauf nach bestaetigter Zahlung: bezahlte Laeufe ohne Finalisierung,
    bezahlte Laeufe ohne Rechnung, Zahlungseingaenge ohne freischaltbaren Lauf.

    Es werden keine Dokumentinhalte und keine Rohdaten angezeigt.
--}}
@extends('layouts.admin')

@section('titel', 'Zahlungsnachlauf')

@section('content')
    <x-hvm.page-header
        eyebrow="Zahlungen"
        title="Zahlungsnachlauf"
        lead="Ein Kunde, der bezahlt hat, erhält seine Leistung. Hier werden offene Fälle nach bestätigter Zahlung sichtbar und nachgeholt."
        :back="route('admin.zahlungen')"
        back-label="Zahlungen und Rechnungen" />

    @if ($betreiber['blockiert'])
        <div class="mt-8">
            <x-hvm.alert variant="error" label="Fehler" title="Rechnungserzeugung blockiert">
                {{ $betreiber['hinweis'] }} Rechnungen können erst nach Ergänzung und Bestätigung nachgeholt werden.
            </x-hvm.alert>
        </div>
    @endif

    <x-hvm.abschnitt class="mt-10" eyebrow="Finalisierung" title="Bezahlt, aber nicht finalisiert" :leer="$nicht_finalisiert === []" leer-icon="check-circle">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Bezahlte Läufe ohne Finalisierung</caption>
            <thead>
                <tr>
                    <th scope="col">Lauf</th>
                    <th scope="col">Status</th>
                    <th scope="col">Bezahlt am</th>
                    <th scope="col">Fehlercode</th>
                    <th scope="col">Handlung</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($nicht_finalisiert as $lauf)
                    <tr>
                        <th scope="row" class="font-mono text-xs font-medium">{{ $lauf->getKey() }}</th>
                        <td data-label="Status">
                            <x-hvm.badge variant="error" icon="x-circle">{{ $lauf->getAttribute('status')->label() }}</x-hvm.badge>
                        </td>
                        <td data-label="Bezahlt am" class="tabular">{{ $lauf->getAttribute('paid_at')?->format('d.m.Y H:i') }}</td>
                        <td data-label="Fehlercode" class="font-mono text-xs">{{ $lauf->getAttribute('failure_code') ?? 'ohne Angabe' }}</td>
                        <td data-label="Handlung">
                            <form method="POST" action="{{ route('admin.zahlungsnachlauf.finalisieren', $lauf) }}">
                                @csrf
                                <x-hvm.button type="submit" variant="secondary" size="sm">Finalisierung nachholen</x-hvm.button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.abschnitt>

    <x-hvm.abschnitt class="mt-16" eyebrow="Rechnung" title="Bezahlt und finalisiert, aber ohne Rechnung" :leer="$ohne_rechnung === []" leer-icon="receipt">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Bezahlte und finalisierte Läufe ohne Rechnung</caption>
            <thead>
                <tr>
                    <th scope="col">Lauf</th>
                    <th scope="col">Finalisiert am</th>
                    <th scope="col" class="betrag">Brutto</th>
                    <th scope="col">Handlung</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ohne_rechnung as $lauf)
                    <tr>
                        <th scope="row" class="font-mono text-xs font-medium">{{ $lauf->getKey() }}</th>
                        <td data-label="Finalisiert am" class="tabular">{{ $lauf->getAttribute('finalized_at')?->format('d.m.Y H:i') }}</td>
                        <td data-label="Brutto" class="betrag">{{ \App\Application\Admin\MetricsOverview::formatCent((int) ($lauf->getAttribute('price_total_gross_cent') ?? 0)) }}</td>
                        <td data-label="Handlung">
                            <form method="POST" action="{{ route('admin.zahlungsnachlauf.rechnung', $lauf) }}">
                                @csrf
                                <x-hvm.button type="submit" variant="secondary" size="sm" :disabled="$betreiber['blockiert']">Rechnung nachholen</x-hvm.button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.abschnitt>

    <x-hvm.abschnitt
        class="mt-16"
        eyebrow="Zuordnung"
        title="Zahlungseingang ohne freischaltbaren Lauf"
        lead="Der Betrag ist beim Zahlungsanbieter vereinnahmt, der Abrechnungslauf war zu diesem Zeitpunkt abgebrochen, gelöscht oder verändert, oder seine Vorschau war nicht mehr gültig und bestätigt (Grund VORSCHAU_UNGUELTIG). Es wurde nicht finalisiert. Erstattung oder Zuordnung ist kaufmännisch zu entscheiden und durch die Geschäftsführung freizugeben."
        :leer="$ohne_lauf === []"
        leer-icon="euro">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Zahlungseingänge ohne freischaltbaren Lauf</caption>
            <thead>
                <tr>
                    <th scope="col">Zahlung</th>
                    <th scope="col" class="betrag">Betrag</th>
                    <th scope="col">Bezahlt am</th>
                    <th scope="col">Grund</th>
                    <th scope="col">Lauf</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ohne_lauf as $zahlung)
                    <tr>
                        <th scope="row" class="font-mono text-xs font-medium">{{ $zahlung->getKey() }}</th>
                        <td data-label="Betrag" class="betrag">{{ \App\Application\Admin\MetricsOverview::formatCent((int) $zahlung->getAttribute('amount_cent')) }}</td>
                        <td data-label="Bezahlt am" class="tabular">{{ $zahlung->getAttribute('paid_at')?->format('d.m.Y H:i') }}</td>
                        <td data-label="Grund" class="font-mono text-xs">{{ $zahlung->getAttribute('failure_code') }}</td>
                        <td data-label="Lauf" class="font-mono text-xs">{{ $zahlung->getAttribute('billing_run_id') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.abschnitt>

    <x-hvm.abschnitt
        class="mt-16"
        eyebrow="Zahlungsanbieter"
        title="Liegen gebliebene Benachrichtigungen des Zahlungsanbieters"
        lead="Die Benachrichtigung wurde empfangen, ihre Verarbeitung aber nicht abgeschlossen, zum Beispiel nach einem Abbruch des Prozesses. Eine erneute Zustellung des Anbieters wird verarbeitet. Bleibt der Eintrag bestehen, ist der Zahlungsstatus im Konto des Anbieters zu prüfen."
        :leer="$liegen_geblieben === []"
        leer-icon="inbox">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Liegen gebliebene Benachrichtigungen des Zahlungsanbieters</caption>
            <thead>
                <tr>
                    <th scope="col">Ereignis</th>
                    <th scope="col">Art</th>
                    <th scope="col">Empfangen am</th>
                    <th scope="col" class="betrag">Zustellungen</th>
                    <th scope="col">Zahlung</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($liegen_geblieben as $ereignis)
                    <tr>
                        <th scope="row" class="font-mono text-xs font-medium">{{ $ereignis->getAttribute('provider_event_id') }}</th>
                        <td data-label="Art" class="font-mono text-xs">{{ $ereignis->getAttribute('event_type') }}</td>
                        <td data-label="Empfangen am" class="tabular">{{ $ereignis->getAttribute('received_at')?->format('d.m.Y H:i') }}</td>
                        <td data-label="Zustellungen" class="betrag">{{ $ereignis->getAttribute('attempts') }}</td>
                        <td data-label="Zahlung" class="font-mono text-xs">{{ $ereignis->getAttribute('payment_id') ?? 'nicht zugeordnet' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.abschnitt>
@endsection
