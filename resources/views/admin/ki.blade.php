{{--
    KI-Bereich.

    VERBINDLICH: Es wird kein API-Key ausgegeben, auch nicht teilweise
    maskiert. Zum Schluessel wird nur gemeldet, ob er gesetzt ist. Der
    Prompttext wird nicht angezeigt.
--}}
@extends('layouts.admin')

@section('titel', 'KI')

@section('content')
    <x-hvm.page-header
        eyebrow="KI"
        title="KI-Provider, Modelle und Kosten"
        lead="Der Healthcheck sendet keinen Dokumentinhalt. Schlüssel werden nicht angezeigt." />

    @if ($warnung !== null)
        <div class="mt-8">
            <x-hvm.alert variant="warning" label="Achtung" title="Ungewöhnlicher Kostenanstieg">
                {{ $warnung }}
            </x-hvm.alert>
        </div>
    @endif

    <x-hvm.abschnitt
        class="mt-10"
        eyebrow="Provider"
        title="Healthcheck je Provider"
        :lead="'Primärprovider: '.$primaer.($fallback !== null ? ', Fallback: '.$fallback : '')"
        :leer="$provider === []"
        leertext="Es ist kein Provider konfiguriert oder die Konfiguration ist unvollständig."
        leer-icon="sparkle">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Healthcheck je Provider</caption>
            <thead>
                <tr>
                    <th scope="col">Provider</th>
                    <th scope="col">Konfiguriertes Modell</th>
                    <th scope="col">Schlüssel gesetzt</th>
                    <th scope="col">Erreichbar</th>
                    <th scope="col">Modell verfügbar</th>
                    <th scope="col">Datenschutzfreigabe</th>
                    <th scope="col">Meldung</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($provider as $zeile)
                    <tr>
                        <th scope="row" class="font-medium">{{ $zeile['provider'] }}</th>
                        <td data-label="Konfiguriertes Modell" class="font-mono text-xs">{{ $zeile['modell'] }}</td>
                        <td data-label="Schlüssel gesetzt">
                            <x-hvm.badge :variant="$zeile['api_key_gesetzt'] ? 'success' : 'error'" :icon="$zeile['api_key_gesetzt'] ? 'check-circle' : 'x-circle'">{{ $zeile['api_key_gesetzt'] ? 'ja' : 'nein' }}</x-hvm.badge>
                        </td>
                        <td data-label="Erreichbar">
                            <x-hvm.badge :variant="$zeile['erreichbar'] ? 'success' : 'error'" :icon="$zeile['erreichbar'] ? 'check-circle' : 'x-circle'">{{ $zeile['erreichbar'] ? 'ja' : 'nein' }}</x-hvm.badge>
                        </td>
                        <td data-label="Modell verfügbar">
                            <x-hvm.badge :variant="$zeile['modell_verfuegbar'] ? 'success' : 'error'" :icon="$zeile['modell_verfuegbar'] ? 'check-circle' : 'x-circle'">{{ $zeile['modell_verfuegbar'] ? 'ja' : 'nein' }}</x-hvm.badge>
                        </td>
                        <td data-label="Datenschutzfreigabe">
                            <x-hvm.badge :variant="$zeile['freigegeben'] ? 'success' : 'warning'" :icon="$zeile['freigegeben'] ? 'check-circle' : 'warning'">{{ $zeile['freigegeben'] ? 'liegt vor' : 'fehlt' }}</x-hvm.badge>
                        </td>
                        <td data-label="Meldung" class="text-hvm-text-sekundaer">{{ $zeile['meldung'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.abschnitt>

    <div class="mt-16 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-hvm.card title="Kosten" eyebrow="Laufender Monat" class="min-w-0">
            <dl class="divide-y divide-hvm-linie">
                <x-hvm.kv label="Laufender Monat">{{ \App\Application\Admin\AiOverview::formatCent($monat['kosten_cent']) }}</x-hvm.kv>
                <x-hvm.kv label="Aufrufe im Monat">{{ $monat['aufrufe'] }}</x-hvm.kv>
                <x-hvm.kv label="Fehlerhafte Aufrufe im Monat">{{ $monat['fehler'] }}</x-hvm.kv>
                <x-hvm.kv label="Gesamt">{{ \App\Application\Admin\AiOverview::formatCent($gesamt['kosten_cent']) }}</x-hvm.kv>
            </dl>
        </x-hvm.card>

        <x-hvm.card title="Limits" eyebrow="Konfiguration" class="min-w-0">
            <dl class="divide-y divide-hvm-linie">
                <x-hvm.kv label="Tageslimit je Nutzer">{{ $limits['tageslimit_cent_je_nutzer'] === null ? 'kein Limit gesetzt' : \App\Application\Admin\AiOverview::formatCent($limits['tageslimit_cent_je_nutzer']) }}</x-hvm.kv>
                <x-hvm.kv label="Konfidenzschwelle">{{ number_format($limits['konfidenzschwelle'], 2, ',', '.') }}</x-hvm.kv>
                <x-hvm.kv label="Maximale Wiederholungen">{{ $limits['maximale_wiederholungen'] }}</x-hvm.kv>
                <x-hvm.kv label="Doppelprüfung">{{ $limits['doppelpruefung_aktiv'] ? 'aktiv' : 'aus' }}</x-hvm.kv>
                <x-hvm.kv label="Fallback">{{ $limits['fallback_aktiv'] ? 'aktiv' : 'aus' }}</x-hvm.kv>
            </dl>
        </x-hvm.card>
    </div>

    <x-hvm.abschnitt class="mt-16" eyebrow="Nutzer" title="Kosten je Nutzer im laufenden Monat" :leer="$je_nutzer === []" leer-icon="user">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Kosten je Nutzer im laufenden Monat</caption>
            <thead>
                <tr>
                    <th scope="col">Nutzer</th>
                    <th scope="col">E-Mail</th>
                    <th scope="col" class="betrag">Aufrufe</th>
                    <th scope="col" class="betrag">Kosten</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($je_nutzer as $zeile)
                    <tr>
                        <th scope="row" class="font-medium">{{ $zeile['name'] }}</th>
                        <td data-label="E-Mail" class="text-hvm-text-sekundaer">{{ $zeile['email'] }}</td>
                        <td data-label="Aufrufe" class="betrag">{{ $zeile['aufrufe'] }}</td>
                        <td data-label="Kosten" class="betrag">{{ \App\Application\Admin\AiOverview::formatCent($zeile['kosten_cent']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.abschnitt>

    <div class="mt-16">
        <x-hvm.card title="Tageskosten" eyebrow="Letzte Tage">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                @foreach ($tageskosten as $tag => $cent)
                    <x-hvm.stat size="sm" tone="canvas" :icon="false" :label="$tag" :value="\App\Application\Admin\AiOverview::formatCent($cent)" />
                @endforeach
            </div>
        </x-hvm.card>
    </div>

    <x-hvm.abschnitt class="mt-16" eyebrow="Prompts" title="Promptversionen" :leer="$prompts === []" leertext="Es ist keine Promptversion hinterlegt." leer-icon="document">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Promptversionen</caption>
            <thead>
                <tr>
                    <th scope="col">Zweck</th>
                    <th scope="col">Version</th>
                    <th scope="col">Status</th>
                    <th scope="col">Aktiviert am</th>
                    <th scope="col">Hash (gekürzt)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($prompts as $zeile)
                    <tr>
                        <th scope="row" class="font-medium">{{ $zeile['zweck'] }}</th>
                        <td data-label="Version" class="tabular">{{ $zeile['version'] }}</td>
                        <td data-label="Status">
                            <x-hvm.badge :variant="$zeile['aktiv'] ? 'success' : 'neutral'" :icon="$zeile['aktiv'] ? 'check-circle' : 'clock'">{{ $zeile['aktiv'] ? 'aktiv' : 'abgelöst' }}</x-hvm.badge>
                        </td>
                        <td data-label="Aktiviert am" class="text-hvm-text-sekundaer">{{ $zeile['aktiviert_am'] ?? 'ohne Angabe' }}</td>
                        <td data-label="Hash (gekürzt)" class="font-mono text-xs">{{ $zeile['hash_kurz'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.abschnitt>
@endsection
