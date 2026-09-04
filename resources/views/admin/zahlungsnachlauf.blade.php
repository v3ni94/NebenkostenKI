{{--
    Nachlauf nach bestaetigter Zahlung: bezahlte Laeufe ohne Finalisierung,
    bezahlte Laeufe ohne Rechnung, Zahlungseingaenge ohne freischaltbaren Lauf.

    Es werden keine Dokumentinhalte und keine Rohdaten angezeigt.
--}}
@extends('layouts.admin')

@section('titel', 'Zahlungsnachlauf')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Zahlungsnachlauf"
        lead="Ein Kunde, der bezahlt hat, erhält seine Leistung. Hier werden offene Fälle nach bestätigter Zahlung sichtbar und nachgeholt." />

    @if ($betreiber['blockiert'])
        <div class="mt-6">
            <x-hvm.alert variant="error" label="Fehler" title="Rechnungserzeugung blockiert">
                {{ $betreiber['hinweis'] }} Rechnungen können erst nach Ergänzung und Bestätigung nachgeholt werden.
            </x-hvm.alert>
        </div>
    @endif

    <div class="mt-6">
        <x-hvm.card title="Bezahlt, aber nicht finalisiert">
            @if ($nicht_finalisiert === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Lauf</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Bezahlt am</th>
                                <th class="px-3 py-2">Fehlercode</th>
                                <th class="px-3 py-2">Handlung</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($nicht_finalisiert as $lauf)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2 font-mono text-xs">{{ $lauf->getKey() }}</td>
                                    <td class="px-3 py-2">{{ $lauf->getAttribute('status')->label() }}</td>
                                    <td class="px-3 py-2">{{ $lauf->getAttribute('paid_at')?->format('d.m.Y H:i') }}</td>
                                    <td class="px-3 py-2">{{ $lauf->getAttribute('failure_code') ?? 'ohne Angabe' }}</td>
                                    <td class="px-3 py-2">
                                        <form method="POST" action="{{ route('admin.zahlungsnachlauf.finalisieren', $lauf) }}">
                                            @csrf
                                            <x-hvm.button type="submit" variant="secondary" size="sm">Finalisierung nachholen</x-hvm.button>
                                        </form>
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
        <x-hvm.card title="Bezahlt und finalisiert, aber ohne Rechnung">
            @if ($ohne_rechnung === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Lauf</th>
                                <th class="px-3 py-2">Finalisiert am</th>
                                <th class="px-3 py-2">Brutto</th>
                                <th class="px-3 py-2">Handlung</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ohne_rechnung as $lauf)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2 font-mono text-xs">{{ $lauf->getKey() }}</td>
                                    <td class="px-3 py-2">{{ $lauf->getAttribute('finalized_at')?->format('d.m.Y H:i') }}</td>
                                    <td class="px-3 py-2">{{ \App\Application\Admin\MetricsOverview::formatCent((int) ($lauf->getAttribute('price_total_gross_cent') ?? 0)) }}</td>
                                    <td class="px-3 py-2">
                                        <form method="POST" action="{{ route('admin.zahlungsnachlauf.rechnung', $lauf) }}">
                                            @csrf
                                            <x-hvm.button type="submit" variant="secondary" size="sm" :disabled="$betreiber['blockiert']">Rechnung nachholen</x-hvm.button>
                                        </form>
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
        <x-hvm.card title="Zahlungseingang ohne freischaltbaren Lauf">
            <p class="text-sm text-hvm-anthrazit">
                Der Betrag ist beim Zahlungsanbieter vereinnahmt, der Abrechnungslauf war zu diesem Zeitpunkt
                abgebrochen, gelöscht oder verändert, oder seine Vorschau war nicht mehr gültig und bestätigt
                (Grund VORSCHAU_UNGUELTIG). Es wurde nicht finalisiert. Erstattung oder Zuordnung ist kaufmännisch
                zu entscheiden und durch die Geschäftsführung freizugeben.
            </p>
            @if ($ohne_lauf === [])
                <p class="mt-3">Kein Eintrag.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Zahlung</th>
                                <th class="px-3 py-2">Betrag</th>
                                <th class="px-3 py-2">Bezahlt am</th>
                                <th class="px-3 py-2">Grund</th>
                                <th class="px-3 py-2">Lauf</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ohne_lauf as $zahlung)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2 font-mono text-xs">{{ $zahlung->getKey() }}</td>
                                    <td class="px-3 py-2">{{ \App\Application\Admin\MetricsOverview::formatCent((int) $zahlung->getAttribute('amount_cent')) }}</td>
                                    <td class="px-3 py-2">{{ $zahlung->getAttribute('paid_at')?->format('d.m.Y H:i') }}</td>
                                    <td class="px-3 py-2">{{ $zahlung->getAttribute('failure_code') }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ $zahlung->getAttribute('billing_run_id') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Liegen gebliebene Benachrichtigungen des Zahlungsanbieters">
            <p class="text-sm text-hvm-anthrazit">
                Die Benachrichtigung wurde empfangen, ihre Verarbeitung aber nicht abgeschlossen, zum Beispiel nach
                einem Abbruch des Prozesses. Eine erneute Zustellung des Anbieters wird verarbeitet. Bleibt der Eintrag
                bestehen, ist der Zahlungsstatus im Konto des Anbieters zu prüfen.
            </p>
            @if ($liegen_geblieben === [])
                <p class="mt-3">Kein Eintrag.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Ereignis</th>
                                <th class="px-3 py-2">Art</th>
                                <th class="px-3 py-2">Empfangen am</th>
                                <th class="px-3 py-2">Zustellungen</th>
                                <th class="px-3 py-2">Zahlung</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($liegen_geblieben as $ereignis)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2 font-mono text-xs">{{ $ereignis->getAttribute('provider_event_id') }}</td>
                                    <td class="px-3 py-2">{{ $ereignis->getAttribute('event_type') }}</td>
                                    <td class="px-3 py-2">{{ $ereignis->getAttribute('received_at')?->format('d.m.Y H:i') }}</td>
                                    <td class="px-3 py-2">{{ $ereignis->getAttribute('attempts') }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ $ereignis->getAttribute('payment_id') ?? 'nicht zugeordnet' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>
@endsection
