{{-- Zahlungen, Erstattungen, Rechnungen, Stornos und Rechnungsnummernkreis. --}}
@extends('layouts.admin')

@section('titel', 'Zahlungen')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Zahlungen und Rechnungen"
        lead="Ein Storno überschreibt nichts. Es entsteht eine eigene Stornorechnung mit eigener Nummer und Referenz." />

    @if ($betreiber['blockiert'])
        <div class="mt-6">
            <x-hvm.alert variant="error" label="Fehler" title="Rechnungserzeugung blockiert">
                {{ $betreiber['hinweis'] }}
            </x-hvm.alert>
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <x-hvm.card title="Umsatz laufender Monat">
            <p class="text-2xl font-semibold">{{ \App\Application\Admin\MetricsOverview::formatCent($umsatz_cent) }}</p>
        </x-hvm.card>

        <x-hvm.card title="Rechnungsnummernkreis {{ $nummernkreis['jahr'] }}">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>Nächste Nummer</dt><dd class="font-mono">{{ $nummernkreis['naechste_nummer'] }}</dd></div>
                <div class="flex justify-between"><dt>Letzter Zählerwert</dt><dd>{{ $nummernkreis['letzter_wert'] }}</dd></div>
                <div class="flex justify-between"><dt>Vergebene Rechnungen</dt><dd>{{ $nummernkreis['vergebene_rechnungen'] }}</dd></div>
            </dl>
            @if ($nummernkreis['lueckenlos'])
                <p class="mt-3"><x-hvm.badge variant="success">Lückenlos</x-hvm.badge></p>
            @else
                <div class="mt-3">
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

    <div class="mt-6">
        <x-hvm.card title="Rechnungen">
            @if ($rechnungen === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Nummer</th>
                                <th class="px-3 py-2">Datum</th>
                                <th class="px-3 py-2">Kunde</th>
                                <th class="px-3 py-2">Brutto</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Storniert durch</th>
                                <th class="px-3 py-2">Handlung</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rechnungen as $rechnung)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2 font-mono text-xs">{{ $rechnung->getAttribute('number') }}</td>
                                    <td class="px-3 py-2">{{ \Illuminate\Support\Carbon::parse((string) $rechnung->getAttribute('issued_on'))->format('d.m.Y') }}</td>
                                    <td class="px-3 py-2">{{ $rechnung->getAttribute('customer_name') }}</td>
                                    <td class="px-3 py-2">{{ \App\Application\Admin\MetricsOverview::formatCent((int) $rechnung->getAttribute('gross_cent')) }}</td>
                                    <td class="px-3 py-2">{{ $rechnung->getAttribute('status')->label() }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ $rechnung->getAttribute('cancels_invoice_id') === null ? '' : 'Storno zu ' . $rechnung->getAttribute('cancels_invoice_id') }}</td>
                                    <td class="px-3 py-2">
                                        @if ($rechnung->getAttribute('status') !== \App\Enums\InvoiceStatus::STORNORECHNUNG
                                            && $rechnung->getAttribute('status') !== \App\Enums\InvoiceStatus::STORNIERT)
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
                </div>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Stornorechnungen">
            @if ($stornos === [])
                <p>Kein Eintrag.</p>
            @else
                <ul class="space-y-2 text-sm">
                    @foreach ($stornos as $storno)
                        <li>
                            <span class="font-mono">{{ $storno->getAttribute('number') }}</span>
                            über {{ \App\Application\Admin\MetricsOverview::formatCent((int) $storno->getAttribute('gross_cent')) }},
                            Referenz auf Rechnung
                            <span class="font-mono">{{ $storno->getAttribute('cancels_invoice_id') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Zahlungen">
            @if ($zahlungen === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Betrag</th>
                                <th class="px-3 py-2">Abrechnungen</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Bezahlt am</th>
                                <th class="px-3 py-2">Erstattet</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($zahlungen as $zahlung)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2">{{ \App\Application\Admin\MetricsOverview::formatCent((int) $zahlung->getAttribute('amount_cent')) }}</td>
                                    <td class="px-3 py-2">{{ $zahlung->getAttribute('statement_count') }}</td>
                                    <td class="px-3 py-2">{{ $zahlung->getAttribute('status')->label() }}</td>
                                    <td class="px-3 py-2">{{ $zahlung->getAttribute('paid_at') === null ? '' : \Illuminate\Support\Carbon::parse((string) $zahlung->getAttribute('paid_at'))->format('d.m.Y') }}</td>
                                    <td class="px-3 py-2">{{ $zahlung->getAttribute('refunded_amount_cent') === null ? '' : \App\Application\Admin\MetricsOverview::formatCent((int) $zahlung->getAttribute('refunded_amount_cent')) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6">
        @include('admin.partials.statuszahlen', ['titel' => 'Rechnungen je Status', 'werte' => $rechnungsstatus])
    </div>
@endsection
