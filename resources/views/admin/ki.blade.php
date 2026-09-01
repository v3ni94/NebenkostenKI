{{--
    KI-Bereich.

    VERBINDLICH: Es wird kein API-Key ausgegeben, auch nicht teilweise
    maskiert. Zum Schluessel wird nur gemeldet, ob er gesetzt ist. Der
    Prompttext wird nicht angezeigt.
--}}
@extends('layouts.admin')

@section('titel', 'KI')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="KI-Provider, Modelle und Kosten"
        lead="Der Healthcheck sendet keinen Dokumentinhalt. Schlüssel werden nicht angezeigt." />

    @if ($warnung !== null)
        <div class="mt-6">
            <x-hvm.alert variant="warning" label="Achtung" title="Ungewöhnlicher Kostenanstieg">
                {{ $warnung }}
            </x-hvm.alert>
        </div>
    @endif

    <div class="mt-6">
        <x-hvm.card title="Healthcheck je Provider">
            <p class="text-sm text-hvm-anthrazit">
                Primärprovider: {{ $primaer }}@if ($fallback !== null), Fallback: {{ $fallback }}@endif
            </p>

            @if ($provider === [])
                <p class="mt-3">Es ist kein Provider konfiguriert oder die Konfiguration ist unvollständig.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Provider</th>
                                <th class="px-3 py-2">Konfiguriertes Modell</th>
                                <th class="px-3 py-2">Schlüssel gesetzt</th>
                                <th class="px-3 py-2">Erreichbar</th>
                                <th class="px-3 py-2">Modell verfügbar</th>
                                <th class="px-3 py-2">Datenschutzfreigabe</th>
                                <th class="px-3 py-2">Meldung</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($provider as $zeile)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2">{{ $zeile['provider'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['modell'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['api_key_gesetzt'] ? 'ja' : 'nein' }}</td>
                                    <td class="px-3 py-2">{{ $zeile['erreichbar'] ? 'ja' : 'nein' }}</td>
                                    <td class="px-3 py-2">{{ $zeile['modell_verfuegbar'] ? 'ja' : 'nein' }}</td>
                                    <td class="px-3 py-2">{{ $zeile['freigegeben'] ? 'liegt vor' : 'fehlt' }}</td>
                                    <td class="px-3 py-2">{{ $zeile['meldung'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Kosten">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>Laufender Monat</dt>
                    <dd>{{ \App\Application\Admin\AiOverview::formatCent($monat['kosten_cent']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Aufrufe im Monat</dt>
                    <dd>{{ $monat['aufrufe'] }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Fehlerhafte Aufrufe im Monat</dt>
                    <dd>{{ $monat['fehler'] }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Gesamt</dt>
                    <dd>{{ \App\Application\Admin\AiOverview::formatCent($gesamt['kosten_cent']) }}</dd>
                </div>
            </dl>
        </x-hvm.card>

        <x-hvm.card title="Limits">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>Tageslimit je Nutzer</dt>
                    <dd>{{ $limits['tageslimit_cent_je_nutzer'] === null ? 'kein Limit gesetzt' : \App\Application\Admin\AiOverview::formatCent($limits['tageslimit_cent_je_nutzer']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Konfidenzschwelle</dt>
                    <dd>{{ number_format($limits['konfidenzschwelle'], 2, ',', '.') }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Maximale Wiederholungen</dt>
                    <dd>{{ $limits['maximale_wiederholungen'] }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Doppelprüfung</dt>
                    <dd>{{ $limits['doppelpruefung_aktiv'] ? 'aktiv' : 'aus' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Fallback</dt>
                    <dd>{{ $limits['fallback_aktiv'] ? 'aktiv' : 'aus' }}</dd>
                </div>
            </dl>
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Kosten je Nutzer im laufenden Monat">
            @if ($je_nutzer === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Nutzer</th>
                                <th class="px-3 py-2">E-Mail</th>
                                <th class="px-3 py-2">Aufrufe</th>
                                <th class="px-3 py-2">Kosten</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($je_nutzer as $zeile)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2">{{ $zeile['name'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['email'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['aufrufe'] }}</td>
                                    <td class="px-3 py-2">{{ \App\Application\Admin\AiOverview::formatCent($zeile['kosten_cent']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Tageskosten">
            <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                @foreach ($tageskosten as $tag => $cent)
                    <div class="rounded border border-hvm-hellgrau px-3 py-2">
                        <dt class="text-xs text-hvm-anthrazit">{{ $tag }}</dt>
                        <dd>{{ \App\Application\Admin\AiOverview::formatCent($cent) }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Promptversionen">
            @if ($prompts === [])
                <p>Es ist keine Promptversion hinterlegt.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Zweck</th>
                                <th class="px-3 py-2">Version</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Aktiviert am</th>
                                <th class="px-3 py-2">Hash (gekürzt)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($prompts as $zeile)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2">{{ $zeile['zweck'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['version'] }}</td>
                                    <td class="px-3 py-2">{{ $zeile['aktiv'] ? 'aktiv' : 'abgelöst' }}</td>
                                    <td class="px-3 py-2">{{ $zeile['aktiviert_am'] ?? 'ohne Angabe' }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ $zeile['hash_kurz'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>
@endsection
