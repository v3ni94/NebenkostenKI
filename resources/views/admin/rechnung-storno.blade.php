{{--
    Storno einer Leistungsrechnung.

    VERBINDLICH: Ein Storno ist ausschliesslich nach Freigabe durch die
    Geschaeftsfuehrung auszuloesen. Die Freigabe ist zu bestaetigen, die
    Begruendung ist Pflicht und wird protokolliert.
--}}
@extends('layouts.admin')

@section('titel', 'Storno vorbereiten')

@section('content')
    <x-hvm.page-header
        eyebrow="Zahlungen"
        :title="'Storno der Rechnung '.$rechnung->getAttribute('number')"
        :back="route('admin.zahlungen')"
        back-label="Zahlungen und Rechnungen" />

    <div class="mt-8 max-w-3xl space-y-8">
        <x-hvm.alert variant="warning" label="Achtung" title="Freigabe der Geschäftsführung erforderlich">
            <p>
                Ein Storno ist ausschließlich nach Freigabe durch die Geschäftsführung auszulösen. Die
                Ursprungsrechnung wird nicht überschrieben: Nummer, Beträge, Anschrift und Beleg bleiben
                unverändert erhalten. Es entsteht eine eigene Stornorechnung mit eigener Nummer, eigenem Beleg
                und Referenz auf die Ursprungsrechnung.
            </p>
        </x-hvm.alert>

        <x-hvm.card title="Rechnungsdaten" eyebrow="Ursprungsrechnung">
            <dl class="divide-y divide-hvm-linie">
                <x-hvm.kv label="Nummer" :mono="true">{{ $rechnung->getAttribute('number') }}</x-hvm.kv>
                <x-hvm.kv label="Kunde">{{ $rechnung->getAttribute('customer_name') }}</x-hvm.kv>
                <x-hvm.kv label="Netto">{{ \App\Application\Admin\MetricsOverview::formatCent((int) $rechnung->getAttribute('net_cent')) }}</x-hvm.kv>
                <x-hvm.kv label="Umsatzsteuer">{{ \App\Application\Admin\MetricsOverview::formatCent((int) $rechnung->getAttribute('tax_cent')) }}</x-hvm.kv>
                <x-hvm.kv label="Brutto">{{ \App\Application\Admin\MetricsOverview::formatCent((int) $rechnung->getAttribute('gross_cent')) }}</x-hvm.kv>
                <x-hvm.kv label="Status">{{ $rechnung->getAttribute('status')->label() }}</x-hvm.kv>
            </dl>
        </x-hvm.card>

        @if ($bereits_storniert)
            <x-hvm.alert variant="info" label="Hinweis">
                Zu dieser Rechnung liegt bereits eine Stornorechnung vor. Ein zweites Storno wird nicht erzeugt.
            </x-hvm.alert>
        @endif

        <x-hvm.card title="Storno auslösen" eyebrow="Handlung" :kennlinie="true" class="rounded-3xl">
            <form method="POST" action="{{ route('admin.rechnungen.storno.store', $rechnung) }}" class="space-y-6">
                @csrf

                <x-hvm.field
                    name="grund"
                    label="Begründung"
                    type="textarea"
                    rows="4"
                    hint="Die Begründung wird im Revisionsprotokoll gespeichert."
                    :required="true" />

                <x-hvm.field
                    name="freigabe_geschaeftsfuehrung"
                    label="Die Freigabe der Geschäftsführung für dieses Storno liegt vor."
                    type="checkbox"
                    value="1" />

                <x-hvm.button type="submit" variant="primary">Stornorechnung erzeugen</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>
@endsection
