{{--
    Organisationen.

    Die Liste zeigt nur Bezeichnung, Typ und Zaehlwerte. Der Einblick in einen
    Datensatz verlangt eine Begruendung.
--}}
@extends('layouts.admin')

@section('titel', 'Organisationen')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Organisationen"
        lead="Der Einblick in Kundendaten ist nur zu Supportzwecken zulässig, verlangt eine Begründung und wird protokolliert." />

    <div class="mt-6">
        <x-hvm.card title="Suche">
            <form method="GET" action="{{ route('admin.organisationen') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="suche" class="block text-sm font-semibold">Bezeichnung</label>
                    <input type="search" id="suche" name="suche" value="{{ $suche }}"
                           class="mt-2 rounded border border-hvm-mittelgrau px-3 py-2">
                </div>
                <x-hvm.button type="submit" variant="secondary" size="sm">Suchen</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Mandanten">
            @if ($organisationen === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Bezeichnung</th>
                                <th class="px-3 py-2">Typ</th>
                                <th class="px-3 py-2">Objekte</th>
                                <th class="px-3 py-2">Läufe</th>
                                <th class="px-3 py-2">Support</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($organisationen as $organisation)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2">{{ $organisation->getAttribute('name') }}</td>
                                    <td class="px-3 py-2">{{ $organisation->getAttribute('type')->label() }}</td>
                                    <td class="px-3 py-2">{{ $organisation->getAttribute('properties_count') }}</td>
                                    <td class="px-3 py-2">{{ $organisation->getAttribute('billing_runs_count') }}</td>
                                    <td class="px-3 py-2">
                                        <x-hvm.button
                                            href="{{ route('admin.organisationen.show', $organisation) }}"
                                            variant="secondary"
                                            size="sm">
                                            Einblick anfordern
                                        </x-hvm.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>
@endsection
