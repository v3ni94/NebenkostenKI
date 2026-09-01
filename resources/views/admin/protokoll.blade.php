{{--
    Revisionsprotokoll.

    Die IP ist bereits beim Schreiben gekuerzt, der User-Agent liegt nur als
    Hash vor. In den Metadaten stehen ausschliesslich technische Kennzahlen.
--}}
@extends('layouts.admin')

@section('titel', 'Protokoll')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Revisionsprotokoll"
        lead="Akteur, Aktion, Entität, Zeitpunkt, gekürzte IP und Begründung. Vollständige IP-Adressen werden nicht gespeichert." />

    <div class="mt-6">
        <x-hvm.card title="Filter">
            <form method="GET" action="{{ route('admin.protokoll') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="aktion" class="block text-sm font-semibold">Aktion beginnt mit</label>
                    <input type="search" id="aktion" name="aktion" value="{{ $aktion }}"
                           class="mt-2 rounded border border-hvm-mittelgrau px-3 py-2">
                </div>
                <x-hvm.button type="submit" variant="secondary" size="sm">Filtern</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Einträge">
            @if ($eintraege === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Zeitpunkt</th>
                                <th class="px-3 py-2">Akteur</th>
                                <th class="px-3 py-2">Interne Rolle</th>
                                <th class="px-3 py-2">Aktion</th>
                                <th class="px-3 py-2">Entität</th>
                                <th class="px-3 py-2">Gekürzte IP</th>
                                <th class="px-3 py-2">Begründung</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($eintraege as $eintrag)
                                <tr class="border-t border-hvm-hellgrau align-top">
                                    <td class="px-3 py-2">{{ \Illuminate\Support\Carbon::parse((string) $eintrag->getAttribute('occurred_at'))->format('d.m.Y H:i') }}</td>
                                    <td class="px-3 py-2">{{ $eintrag->actor?->getAttribute('name') ?? 'System' }}</td>
                                    <td class="px-3 py-2">{{ $eintrag->getAttribute('actor_admin_role')?->label() ?? '' }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ $eintrag->getAttribute('action') }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ class_basename((string) $eintrag->getAttribute('subject_type')) }}</td>
                                    <td class="px-3 py-2">{{ $eintrag->getAttribute('ip_truncated') ?? '' }}</td>
                                    <td class="px-3 py-2">{{ $eintrag->getAttribute('reason') ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>
@endsection
