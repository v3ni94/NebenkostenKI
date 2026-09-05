{{--
    Datenschutzmonitor.

    VERBINDLICH: Es werden niemals Dateiinhalte, Originaldateinamen,
    Storage-Keys oder Provider-Datei-IDs angezeigt. Sichtbar sind Anzahl,
    Alter, Versuchszaehler und Fehlercode.
--}}
@extends('layouts.admin')

@section('titel', 'Datenschutz')

@section('content')
    <x-hvm.page-header
        eyebrow="Datenschutz"
        title="Datenschutzmonitor"
        lead="Angezeigt werden ausschließlich Anzahl, Alter, Status und Fehlercode. Dateiinhalte und Originaldateinamen werden nicht gespeichert und nicht angezeigt." />

    @if ($zusammenfassung['alarm'])
        <div class="mt-8">
            <x-hvm.alert variant="error" label="Fehler" title="Kritischer Datenschutzalarm">
                <p>
                    Fehlgeschlagene oder überfällige Löschungen: {{ $zusammenfassung['fehlgeschlagene_loeschungen'] }}.
                    Überfällige temporäre Uploads: {{ $zusammenfassung['ueberfaellige_uploads'] }}.
                </p>
                <form class="mt-4" method="POST" action="{{ route('admin.datenschutz.wiederholen') }}">
                    @csrf
                    <x-hvm.button type="submit" variant="primary" size="sm">Löschung erneut anstoßen</x-hvm.button>
                </form>
            </x-hvm.alert>
        </div>
    @endif

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-hvm.card title="Zusammenfassung" eyebrow="Stand" class="min-w-0">
            <dl class="divide-y divide-hvm-linie">
                <x-hvm.rollout-admin-kv label="Überfällige temporäre Uploads">{{ $zusammenfassung['ueberfaellige_uploads'] }}</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Ältester überfälliger Upload">{{ $zusammenfassung['aeltester_upload_minuten'] === null ? 'keiner' : $zusammenfassung['aeltester_upload_minuten'].' Minuten' }}</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Offene lokale Löschungen">{{ $zusammenfassung['offene_lokale_loeschungen'] }}</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Offene Providerlöschungen">{{ $zusammenfassung['offene_providerloeschungen'] }}</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Fehlgeschlagene Löschungen">{{ $zusammenfassung['fehlgeschlagene_loeschungen'] }}</x-hvm.rollout-admin-kv>
            </dl>
        </x-hvm.card>

        <x-hvm.card title="Kurzzeit-Aufbewahrung" eyebrow="Fristen" class="min-w-0">
            <dl class="divide-y divide-hvm-linie">
                <x-hvm.rollout-admin-kv label="TTL temporärer Uploads">{{ config('smartabrechnen.retention.temp_upload_ttl_minutes') }} Minuten</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Harte Obergrenze">{{ config('smartabrechnen.retention.temp_upload_ttl_hard_limit_minutes') }} Minuten</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="TTL Providerdatei">{{ config('smartabrechnen.retention.ai_provider_file_ttl_minutes') }} Minuten</x-hvm.rollout-admin-kv>
            </dl>
        </x-hvm.card>
    </div>

    <x-hvm.rollout-admin-abschnitt class="mt-16" eyebrow="Löschungen" title="Fehlgeschlagene und überfällige Löschungen" :leer="$fehlgeschlagen === []">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Fehlgeschlagene und überfällige Löschungen</caption>
            <thead>
                <tr>
                    <th scope="col">Dokumentkennung</th>
                    <th scope="col">Status</th>
                    <th scope="col">Lokal</th>
                    <th scope="col">Provider</th>
                    <th scope="col">Versuch</th>
                    <th scope="col">Fehlercode</th>
                    <th scope="col">Alter</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($fehlgeschlagen as $zeile)
                    <tr>
                        <th scope="row" class="font-mono text-xs font-medium">{{ $zeile['dokument_id'] }}</th>
                        <td data-label="Status">{{ $zeile['status'] }}</td>
                        <td data-label="Lokal">{{ $zeile['lokal'] }}</td>
                        <td data-label="Provider">{{ $zeile['provider'] }}</td>
                        <td data-label="Versuch" class="tabular">{{ $zeile['versuch'] }}</td>
                        <td data-label="Fehlercode" class="font-mono text-xs">{{ $zeile['fehlercode'] ?? 'ohne Angabe' }}</td>
                        <td data-label="Alter" class="text-hvm-text-sekundaer">{{ $zeile['alter_stunden'] === null ? 'unbekannt' : $zeile['alter_stunden'].' Stunden' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @unless ($fehlgeschlagen === [])
            <x-slot:footer>
                <form method="POST" action="{{ route('admin.datenschutz.wiederholen') }}">
                    @csrf
                    <x-hvm.button type="submit" variant="secondary" size="sm">Löschung erneut anstoßen</x-hvm.button>
                </form>
            </x-slot:footer>
        @endunless
    </x-hvm.rollout-admin-abschnitt>

    <x-hvm.rollout-admin-abschnitt class="mt-16" eyebrow="Uploads" title="Überfällige temporäre Uploads" :leer="$ueberfaellig === []">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Überfällige temporäre Uploads</caption>
            <thead>
                <tr>
                    <th scope="col">Dokumentkennung</th>
                    <th scope="col">Überfällig seit</th>
                    <th scope="col">Löschversuche</th>
                    <th scope="col">Fehlerklasse</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ueberfaellig as $zeile)
                    <tr>
                        <th scope="row" class="font-mono text-xs font-medium">{{ $zeile['dokument_id'] }}</th>
                        <td data-label="Überfällig seit">{{ $zeile['alter_minuten'] === null ? 'unbekannt' : $zeile['alter_minuten'].' Minuten' }}</td>
                        <td data-label="Löschversuche" class="tabular">{{ $zeile['loeschversuche'] }}</td>
                        <td data-label="Fehlerklasse" class="font-mono text-xs">{{ $zeile['fehlerklasse'] ?? 'ohne Angabe' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.rollout-admin-abschnitt>

    <x-hvm.rollout-admin-abschnitt class="mt-16" eyebrow="Provider" title="Offene Providerlöschungen" :leer="$provider === []">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Offene Providerlöschungen</caption>
            <thead>
                <tr>
                    <th scope="col">Dokumentkennung</th>
                    <th scope="col">Provider</th>
                    <th scope="col">Status</th>
                    <th scope="col">Alter</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($provider as $zeile)
                    <tr>
                        <th scope="row" class="font-mono text-xs font-medium">{{ $zeile['dokument_id'] }}</th>
                        <td data-label="Provider">{{ $zeile['provider'] }}</td>
                        <td data-label="Status">{{ $zeile['status'] }}</td>
                        <td data-label="Alter" class="text-hvm-text-sekundaer">{{ $zeile['alter_minuten'] === null ? 'unbekannt' : $zeile['alter_minuten'].' Minuten' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.rollout-admin-abschnitt>
@endsection
