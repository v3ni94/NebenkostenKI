{{--
    Regel- und Promptversionen.

    Der Prompttext wird nicht angezeigt, nur Zweck, Version, Status und ein
    gekuerzter Hash.
--}}
@extends('layouts.admin')

@section('titel', 'Versionen')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Regel- und Promptversionen"
        lead="Eine Änderung an Regel oder Prompt wirkt ausschließlich auf neue Berechnungsstände. Gespeicherte Stände bleiben reproduzierbar." />

    <div class="mt-6">
        <x-hvm.card title="Regelstände">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-hvm-orange-soft">
                        <tr>
                            <th class="px-3 py-2">Version</th>
                            <th class="px-3 py-2">Gültig ab</th>
                            <th class="px-3 py-2">Regeln</th>
                            <th class="px-3 py-2">Verwendet in Ständen</th>
                            <th class="px-3 py-2">Hinweis</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($regelstaende as $stand)
                            <tr class="border-t border-hvm-hellgrau">
                                <td class="px-3 py-2">{{ $stand['version'] }}</td>
                                <td class="px-3 py-2">{{ $stand['gueltig_ab'] }}</td>
                                <td class="px-3 py-2">{{ $stand['regelanzahl'] }}</td>
                                <td class="px-3 py-2">{{ $stand['verwendet_in_staenden'] }}</td>
                                <td class="px-3 py-2">{{ $stand['hinweis'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-hvm.card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Domainversionen in gespeicherten Ständen">
            @if ($domainversionen === [])
                <p>Kein gespeicherter Berechnungsstand.</p>
            @else
                <dl class="space-y-1 text-sm">
                    @foreach ($domainversionen as $version => $anzahl)
                        <div class="flex justify-between"><dt>{{ $version }}</dt><dd>{{ $anzahl }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </x-hvm.card>

        <x-hvm.card title="Promptversionen">
            @if ($prompts === [])
                <p>Es ist keine Promptversion hinterlegt.</p>
            @else
                <ul class="space-y-1 text-sm">
                    @foreach ($prompts as $prompt)
                        <li>
                            {{ $prompt['zweck'] }}, Version {{ $prompt['version'] }},
                            {{ $prompt['aktiv'] ? 'aktiv' : 'abgelöst' }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-hvm.card>
    </div>
@endsection
