{{-- Zahlungen, Erstattungen, Rechnungen, Stornos und Rechnungsnummernkreis. --}}
@extends('layouts.admin')

@section('titel', 'Zahlungen')

@section('content')
    <x-hvm.page-header
        eyebrow="Zahlungen"
        title="Zahlungen und Rechnungen"
        lead="Ein Storno überschreibt nichts. Es entsteht eine eigene Stornorechnung mit eigener Nummer und Referenz.">
        <x-slot:actions>
            <x-hvm.button href="{{ route('admin.zahlungsnachlauf') }}" variant="secondary" size="sm">
                Zahlungsnachlauf: offene Fälle nach bestätigter Zahlung ({{ $nachlauf_offen }})
                <x-hvm.icon name="arrow-right" class="h-4 w-4" />
            </x-hvm.button>
        </x-slot:actions>
    </x-hvm.page-header>

    @if ($betreiber['blockiert'])
        <div class="mt-8">
            <x-hvm.alert variant="error" label="Fehler" title="Rechnungserzeugung blockiert">
                {{ $betreiber['hinweis'] }}
            </x-hvm.alert>
        </div>
    @endif

    @if ($zahlungen_ohne_lauf > 0)
        <div class="mt-8">
            <x-hvm.alert variant="warning" label="Achtung" title="Zahlungseingang ohne freischaltbaren Lauf">
                {{ $zahlungen_ohne_lauf }} bestätigte {{ $zahlungen_ohne_lauf === 1 ? 'Zahlung gehört' : 'Zahlungen gehören' }} zu keinem freischaltbaren Abrechnungslauf.
                Erstattung oder Zuordnung ist kaufmännisch zu entscheiden und durch die Geschäftsführung freizugeben.
            </x-hvm.alert>
        </div>
    @endif

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-hvm.stat
            label="Umsatz laufender Monat"
            :value="\App\Application\Admin\MetricsOverview::formatCent($umsatz_cent)"
            icon="euro"
            class="min-w-0" />

        <x-hvm.card :title="'Rechnungsnummernkreis '.$nummernkreis['jahr']" eyebrow="Nummernkreis" class="min-w-0">
            <dl class="divide-y divide-hvm-linie">
                <x-hvm.rollout-admin-kv label="Nächste Nummer" :mono="true">{{ $nummernkreis['naechste_nummer'] }}</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Letzter Zählerwert">{{ $nummernkreis['letzter_wert'] }}</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Vergebene Rechnungen">{{ $nummernkreis['vergebene_rechnungen'] }}</x-hvm.rollout-admin-kv>
            </dl>
            @if ($nummernkreis['lueckenlos'])
                <p class="mt-4"><x-hvm.badge variant="success">Lückenlos</x-hvm.badge></p>
            @else
                <div class="mt-4">
                    <x-hvm.alert variant="warning" label="Achtung" title="Lücke im Nummernkreis">
                        <p>
                            Nicht belegte Nummern: {{ implode(', ', $nummernkreis['fehlende_nummern']) }}.
                            Eine vergebene Nummer wird nie wiederverwendet. Die Lücke ist zu dokumentieren.
                        </p>
                    </x-hvm.alert>
                </div>
            @endif
        </x-hvm.card>

        @include('admin.partials.statuszahlen', ['titel' => 'Zahlungen je Status', 'werte' => $zahlungsstatus])
    </div>

    <x-hvm.rollout-admin-abschnitt class="mt-16" eyebrow="Rechnungen" title="Rechnungen" :leer="$rechnungen === []" leer-icon="receipt">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Rechnungen</caption>
            <thead>
                <tr>
                    <th scope="col">Nummer</th>
                    <th scope="col">Datum</th>
                    <th scope="col">Kunde</th>
                    <th scope="col" class="betrag">Brutto</th>
                    <th scope="col">Status</th>
                    <th scope="col">Storniert durch</th>
                    <th scope="col">Handlung</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rechnungen as $rechnung)
                    @php
                        $statusDerRechnung = $rechnung->getAttribute('status');
                        $stornierbar = $statusDerRechnung !== \App\Enums\InvoiceStatus::STORNORECHNUNG
                            && $statusDerRechnung !== \App\Enums\InvoiceStatus::STORNIERT;
                    @endphp
                    <tr>
                        <th scope="row" class="font-mono text-xs font-medium">{{ $rechnung->getAttribute('number') }}</th>
                        <td data-label="Datum" class="tabular">{{ \Illuminate\Support\Carbon::parse((string) $rechnung->getAttribute('issued_on'))->format('d.m.Y') }}</td>
                        <td data-label="Kunde">{{ $rechnung->getAttribute('customer_name') }}</td>
                        <td data-label="Brutto" class="betrag">{{ \App\Application\Admin\MetricsOverview::formatCent((int) $rechnung->getAttribute('gross_cent')) }}</td>
                        <td data-label="Status">
                            <x-hvm.badge :variant="$stornierbar ? 'neutral' : 'warning'" :icon="$stornierbar ? 'receipt' : 'x-circle'">{{ $statusDerRechnung->label() }}</x-hvm.badge>
                        </td>
                        <td data-label="Storniert durch" class="font-mono text-xs">{{ $rechnung->getAttribute('cancels_invoice_id') === null ? '' : 'Storno zu ' . $rechnung->getAttribute('cancels_invoice_id') }}</td>
                        <td data-label="Handlung">
                            @if ($stornierbar)
                                <x-hvm.button
                                    href="{{ route('admin.rechnungen.storno.create', $rechnung) }}"
                                    variant="secondary"
                                    size="sm">
                                    Storno vorbereiten
                                </x-hvm.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.rollout-admin-abschnitt>

    <x-hvm.rollout-admin-abschnitt class="mt-16" eyebrow="Stornos" title="Stornorechnungen" :leer="$stornos === []" leer-icon="receipt">
        <ul class="divide-y divide-hvm-linie">
            @foreach ($stornos as $storno)
                <li class="flex flex-wrap items-baseline gap-x-2 gap-y-1 px-5 py-4 text-sm sm:px-6">
                    <span class="font-mono text-xs font-medium text-hvm-textschwarz">{{ $storno->getAttribute('number') }}</span>
                    <span>über <span class="font-medium tabular whitespace-nowrap">{{ \App\Application\Admin\MetricsOverview::formatCent((int) $storno->getAttribute('gross_cent')) }}</span>,</span>
                    <span class="text-hvm-text-sekundaer">Referenz auf Rechnung</span>
                    <span class="font-mono text-xs">{{ $storno->getAttribute('cancels_invoice_id') }}</span>
                </li>
            @endforeach
        </ul>
    </x-hvm.rollout-admin-abschnitt>

    <x-hvm.rollout-admin-abschnitt class="mt-16" eyebrow="Zahlungseingänge" title="Zahlungen" :leer="$zahlungen === []" leer-icon="euro">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Zahlungen</caption>
            <thead>
                <tr>
                    <th scope="col" class="betrag">Betrag</th>
                    <th scope="col" class="betrag">Abrechnungen</th>
                    <th scope="col">Status</th>
                    <th scope="col">Bezahlt am</th>
                    <th scope="col" class="betrag">Erstattet</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($zahlungen as $zahlung)
                    <tr>
                        <th scope="row" class="betrag font-medium">{{ \App\Application\Admin\MetricsOverview::formatCent((int) $zahlung->getAttribute('amount_cent')) }}</th>
                        <td data-label="Abrechnungen" class="betrag">{{ $zahlung->getAttribute('statement_count') }}</td>
                        <td data-label="Status">
                            <x-hvm.badge :variant="$zahlung->getAttribute('status') === \App\Enums\PaymentStatus::BEZAHLT ? 'success' : 'neutral'" :icon="$zahlung->getAttribute('status') === \App\Enums\PaymentStatus::BEZAHLT ? 'check-circle' : 'clock'">{{ $zahlung->getAttribute('status')->label() }}</x-hvm.badge>
                        </td>
                        <td data-label="Bezahlt am" class="tabular">{{ $zahlung->getAttribute('paid_at') === null ? '' : \Illuminate\Support\Carbon::parse((string) $zahlung->getAttribute('paid_at'))->format('d.m.Y') }}</td>
                        <td data-label="Erstattet" class="betrag">{{ $zahlung->getAttribute('refunded_amount_cent') === null ? '' : \App\Application\Admin\MetricsOverview::formatCent((int) $zahlung->getAttribute('refunded_amount_cent')) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.rollout-admin-abschnitt>

    <div class="mt-16">
        @include('admin.partials.statuszahlen', ['titel' => 'Rechnungen je Status', 'werte' => $rechnungsstatus])
    </div>
@endsection
