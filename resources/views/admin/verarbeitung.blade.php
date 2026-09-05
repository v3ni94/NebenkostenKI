{{--
    Dokumentverarbeitung und Teiljobs.

    Es werden keine Rohdaten, keine Nutzlasten und keine Prompts angezeigt.
--}}
@extends('layouts.admin')

@section('titel', 'Verarbeitung')

@section('content')
    <x-hvm.page-header
        eyebrow="Verarbeitung"
        title="Verarbeitung und Teiljobs"
        lead="Sichtbar sind Jobart, Status, Versuchszähler und Fehlercode. Nutzlasten, Rohdaten und Prompts werden nicht angezeigt." />

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2">
        @include('admin.partials.statuszahlen', ['titel' => 'Dokumente je Status', 'werte' => $dokumente])
        @include('admin.partials.statuszahlen', ['titel' => 'Teiljobs je Status', 'werte' => $jobs])
    </div>

    @foreach ([['Fehlgeschlagene Teiljobs', $fehlgeschlagen, 'Wiederholbar'], ['Endgültig fehlgeschlagene Teiljobs (Dead Letter)', $deadletter, 'Endgültig']] as [$titel, $zeilen, $eyebrow])
        <x-hvm.rollout-admin-abschnitt class="mt-16" :eyebrow="$eyebrow" :title="$titel" :leer="$zeilen === []" leer-icon="layers">
            <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
                <caption class="sr-only">{{ $titel }}</caption>
                <thead>
                    <tr>
                        <th scope="col">Jobart</th>
                        <th scope="col">Status</th>
                        <th scope="col">Versuche</th>
                        <th scope="col">Fehlercode</th>
                        <th scope="col">Alter</th>
                        <th scope="col">Handlung</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($zeilen as $zeile)
                        <tr>
                            <th scope="row" class="font-mono text-xs font-medium">{{ $zeile['jobart'] }}</th>
                            <td data-label="Status">
                                <x-hvm.badge variant="error" icon="x-circle">{{ $zeile['status'] }}</x-hvm.badge>
                            </td>
                            <td data-label="Versuche" class="tabular">{{ $zeile['versuche'] }} von {{ $zeile['max_versuche'] }}</td>
                            <td data-label="Fehlercode" class="font-mono text-xs">{{ $zeile['fehlercode'] ?? 'ohne Angabe' }}</td>
                            <td data-label="Alter" class="text-hvm-text-sekundaer">{{ $zeile['alter_minuten'] === null ? 'unbekannt' : $zeile['alter_minuten'].' Minuten' }}</td>
                            <td data-label="Handlung">
                                <form method="POST" action="{{ route('admin.verarbeitung.wiederholen', $zeile['id']) }}">
                                    @csrf
                                    <x-hvm.button type="submit" variant="secondary" size="sm">Wiederholen</x-hvm.button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-hvm.rollout-admin-abschnitt>
    @endforeach
@endsection
