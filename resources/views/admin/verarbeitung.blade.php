{{--
    Dokumentverarbeitung und Teiljobs.

    Es werden keine Rohdaten, keine Nutzlasten und keine Prompts angezeigt.
--}}
@extends('layouts.admin')

@section('titel', 'Verarbeitung')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Verarbeitung und Teiljobs"
        lead="Sichtbar sind Jobart, Status, Versuchszähler und Fehlercode. Nutzlasten, Rohdaten und Prompts werden nicht angezeigt." />

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        @include('admin.partials.statuszahlen', ['titel' => 'Dokumente je Status', 'werte' => $dokumente])
        @include('admin.partials.statuszahlen', ['titel' => 'Teiljobs je Status', 'werte' => $jobs])
    </div>

    @foreach ([['Fehlgeschlagene Teiljobs', $fehlgeschlagen], ['Endgültig fehlgeschlagene Teiljobs (Dead Letter)', $deadletter]] as [$titel, $zeilen])
        <div class="mt-6">
            <x-hvm.card :title="$titel">
                @if ($zeilen === [])
                    <p>Kein Eintrag.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-hvm-orange-soft">
                                <tr>
                                    <th class="px-3 py-2">Jobart</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2">Versuche</th>
                                    <th class="px-3 py-2">Fehlercode</th>
                                    <th class="px-3 py-2">Alter</th>
                                    <th class="px-3 py-2">Handlung</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($zeilen as $zeile)
                                    <tr class="border-t border-hvm-hellgrau">
                                        <td class="px-3 py-2">{{ $zeile['jobart'] }}</td>
                                        <td class="px-3 py-2">{{ $zeile['status'] }}</td>
                                        <td class="px-3 py-2">{{ $zeile['versuche'] }} von {{ $zeile['max_versuche'] }}</td>
                                        <td class="px-3 py-2">{{ $zeile['fehlercode'] ?? 'ohne Angabe' }}</td>
                                        <td class="px-3 py-2">{{ $zeile['alter_minuten'] === null ? 'unbekannt' : $zeile['alter_minuten'].' Minuten' }}</td>
                                        <td class="px-3 py-2">
                                            <form method="POST" action="{{ route('admin.verarbeitung.wiederholen', $zeile['id']) }}">
                                                @csrf
                                                <x-hvm.button type="submit" variant="secondary" size="sm">Wiederholen</x-hvm.button>
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
    @endforeach
@endsection
