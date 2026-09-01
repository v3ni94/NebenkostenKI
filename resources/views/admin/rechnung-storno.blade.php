{{--
    Storno einer Leistungsrechnung.

    VERBINDLICH: Ein Storno ist ausschliesslich nach Freigabe durch die
    Geschaeftsfuehrung auszuloesen. Die Freigabe ist zu bestaetigen, die
    Begruendung ist Pflicht und wird protokolliert.
--}}
@extends('layouts.admin')

@section('titel', 'Storno vorbereiten')

@section('content')
    <x-hvm.section-heading level="h1" title="Storno der Rechnung {{ $rechnung->getAttribute('number') }}" />

    <div class="mt-6 max-w-3xl space-y-6">
        <x-hvm.alert variant="warning" label="Achtung" title="Freigabe der Geschäftsführung erforderlich">
            <p>
                Ein Storno ist ausschließlich nach Freigabe durch die Geschäftsführung auszulösen. Die
                Ursprungsrechnung wird nicht überschrieben: Nummer, Beträge, Anschrift und Beleg bleiben
                unverändert erhalten. Es entsteht eine eigene Stornorechnung mit eigener Nummer, eigenem Beleg
                und Referenz auf die Ursprungsrechnung.
            </p>
        </x-hvm.alert>

        <x-hvm.card title="Rechnungsdaten">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>Nummer</dt><dd class="font-mono">{{ $rechnung->getAttribute('number') }}</dd></div>
                <div class="flex justify-between"><dt>Kunde</dt><dd>{{ $rechnung->getAttribute('customer_name') }}</dd></div>
                <div class="flex justify-between"><dt>Netto</dt><dd>{{ \App\Application\Admin\MetricsOverview::formatCent((int) $rechnung->getAttribute('net_cent')) }}</dd></div>
                <div class="flex justify-between"><dt>Umsatzsteuer</dt><dd>{{ \App\Application\Admin\MetricsOverview::formatCent((int) $rechnung->getAttribute('tax_cent')) }}</dd></div>
                <div class="flex justify-between"><dt>Brutto</dt><dd>{{ \App\Application\Admin\MetricsOverview::formatCent((int) $rechnung->getAttribute('gross_cent')) }}</dd></div>
                <div class="flex justify-between"><dt>Status</dt><dd>{{ $rechnung->getAttribute('status')->label() }}</dd></div>
            </dl>
        </x-hvm.card>

        @if ($bereits_storniert)
            <x-hvm.alert variant="info" label="Hinweis">
                Zu dieser Rechnung liegt bereits eine Stornorechnung vor. Ein zweites Storno wird nicht erzeugt.
            </x-hvm.alert>
        @endif

        <x-hvm.card title="Storno auslösen">
            <form method="POST" action="{{ route('admin.rechnungen.storno.store', $rechnung) }}" class="space-y-4">
                @csrf

                <div>
                    <label for="grund" class="block text-sm font-semibold">Begründung</label>
                    <p class="text-sm text-hvm-anthrazit">
                        Die Begründung wird im Revisionsprotokoll gespeichert.
                    </p>
                    <textarea id="grund" name="grund" rows="4" required
                              class="mt-2 w-full rounded border border-hvm-mittelgrau px-3 py-2">{{ old('grund') }}</textarea>
                </div>

                <div class="flex items-start gap-3">
                    <input type="checkbox" id="freigabe_geschaeftsfuehrung" name="freigabe_geschaeftsfuehrung" value="1"
                           class="mt-1 h-5 w-5">
                    <label for="freigabe_geschaeftsfuehrung" class="text-sm">
                        Die Freigabe der Geschäftsführung für dieses Storno liegt vor.
                    </label>
                </div>

                <x-hvm.button type="submit">Stornorechnung erzeugen</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>
@endsection
