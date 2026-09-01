{{--
    Datenschutzmonitor.

    VERBINDLICH: Es werden niemals Dateiinhalte, Originaldateinamen,
    Storage-Keys oder Provider-Datei-IDs angezeigt. Sichtbar sind Anzahl,
    Alter, Versuchszaehler und Fehlercode.
--}}
@extends('layouts.admin')

@section('titel', 'Datenschutz')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Datenschutzmonitor"
        lead="Angezeigt werden ausschließlich Anzahl, Alter, Status und Fehlercode. Dateiinhalte und Originaldateinamen werden nicht gespeichert und nicht angezeigt." />

    @if ($zusammenfassung['alarm'])
        <div class="mt-6">
            <x-hvm.alert variant="error" label="Fehler" title="Kritischer Datenschutzalarm">
                <p>
                    Fehlgeschlagene oder überfällige Löschungen: {{ $zusammenfassung['fehlgeschlagene_loeschungen'] }}.
                    Überfällige temporäre Uploads: {{ $zusammenfassung['ueberfaellige_uploads'] }}.
                </p>
                <form class="mt-3" method="POST" action="{{ route('admin.datenschutz.wiederholen') }}">
                    @csrf
                    <x-hvm.button type="submit" size="sm">Löschung erneut anstoßen</x-hvm.button>
                </form>
            </x-hvm.alert>
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Zusammenfassung">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>Überfällige temporäre Uploads</dt>
                    <dd>{{ $zusammenfassung['ueberfaellige_uploads'] }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Ältester überfälliger Upload</dt>
                    <dd>{{ $zusammenfassung['aeltester_upload_minuten'] === null ? 'keiner' : $zusammenfassung['aeltester_upload_minuten'].' Minuten' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Offene lokale Löschungen</dt>
                    <dd>{{ $zusammenfassung['offene_lokale_loeschungen'] }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Offene Providerlöschungen</dt>
                    <dd>{{ $zusammenfassung['offene_providerloeschungen'] }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Fehlgeschlagene Löschungen</dt>
                    <dd>{{ $zusammenfassung['fehlgeschlagene_loeschungen'] }}</dd>
                </div>
            </dl>
        </x-hvm.card>

        <x-hvm.card title="Kurzzeit-Aufbewahrung">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>TTL temporärer Uploads</dt>
                    <dd>{{ config('smartabrechnen.retention.temp_upload_ttl_minutes') }} Minuten</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Harte Obergrenze</dt>
                    <dd>{{ config('smartabrechnen.retention.temp_upload_ttl_hard_limit_minutes') }} Minuten</dd>
                </div>
                <div class="flex justify-between">
                    <dt>TTL Providerdatei</dt>
                    <dd>{{ config('smartabrechnen.retention.ai_provider_file_ttl_minutes') }} Minuten</dd>
                </div>
            </dl>
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Fehlgeschlagene und überfällige Löschungen">
            @if ($fehlgeschlagen === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Dokumentkennung</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Lokal</th>
                                <th class="px-3 py-2">Provider</th>
                                <th class="px-3 py-2">Versuch</th>
                                <th class="px-3 py-2">Fehlercode</th>
                                <th class="px-3 py-2">Alter</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fehlgeschlagen as $zeile)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2 font-mono text-xs">{{ $zeile['dokument_id'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['status'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['lokal'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['provider'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['versuch'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['fehlercode'] ?? 'ohne Angabe' }}</td>
                                    <td class="px-3 py-2">{{ $zeile['alter_stunden'] === null ? 'unbekannt' : $zeile['alter_stunden'].' Stunden' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <form class="mt-4" method="POST" action="{{ route('admin.datenschutz.wiederholen') }}">
                    @csrf
                    <x-hvm.button type="submit" size="sm">Löschung erneut anstoßen</x-hvm.button>
                </form>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Überfällige temporäre Uploads">
            @if ($ueberfaellig === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Dokumentkennung</th>
                                <th class="px-3 py-2">Überfällig seit</th>
                                <th class="px-3 py-2">Löschversuche</th>
                                <th class="px-3 py-2">Fehlerklasse</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ueberfaellig as $zeile)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2 font-mono text-xs">{{ $zeile['dokument_id'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['alter_minuten'] === null ? 'unbekannt' : $zeile['alter_minuten'].' Minuten' }}</td>
                                    <td class="px-3 py-2">{{ $zeile['loeschversuche'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['fehlerklasse'] ?? 'ohne Angabe' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Offene Providerlöschungen">
            @if ($provider === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Dokumentkennung</th>
                                <th class="px-3 py-2">Provider</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Alter</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($provider as $zeile)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2 font-mono text-xs">{{ $zeile['dokument_id'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['provider'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['status'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['alter_minuten'] === null ? 'unbekannt' : $zeile['alter_minuten'].' Minuten' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>
@endsection
