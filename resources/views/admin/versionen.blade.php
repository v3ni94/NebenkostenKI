{{--
    Regel- und Promptversionen.

    Der Prompttext wird nicht angezeigt, nur Zweck, Version, Status und ein
    gekuerzter Hash.
--}}
@extends('layouts.admin')

@section('titel', 'Versionen')

@section('content')
    <x-hvm.page-header
        eyebrow="Versionen"
        title="Regel- und Promptversionen"
        lead="Eine Änderung an Regel oder Prompt wirkt ausschließlich auf neue Berechnungsstände. Gespeicherte Stände bleiben reproduzierbar." />

    <x-hvm.rollout-admin-abschnitt class="mt-10" eyebrow="Regeln" title="Regelstände" :leer="$regelstaende === []" leer-icon="document">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Regelstände</caption>
            <thead>
                <tr>
                    <th scope="col">Version</th>
                    <th scope="col">Gültig ab</th>
                    <th scope="col" class="betrag">Regeln</th>
                    <th scope="col" class="betrag">Verwendet in Ständen</th>
                    <th scope="col">Hinweis</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($regelstaende as $stand)
                    <tr>
                        <th scope="row" class="font-medium tabular">{{ $stand['version'] }}</th>
                        <td data-label="Gültig ab" class="tabular">{{ $stand['gueltig_ab'] }}</td>
                        <td data-label="Regeln" class="betrag">{{ $stand['regelanzahl'] }}</td>
                        <td data-label="Verwendet in Ständen" class="betrag">{{ $stand['verwendet_in_staenden'] }}</td>
                        <td data-label="Hinweis" class="text-hvm-text-sekundaer">{{ $stand['hinweis'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.rollout-admin-abschnitt>

    <div class="mt-16 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-hvm.card title="Domainversionen in gespeicherten Ständen" eyebrow="Berechnung" class="min-w-0">
            @if ($domainversionen === [])
                <p class="text-sm text-hvm-text-sekundaer">Kein gespeicherter Berechnungsstand.</p>
            @else
                <dl class="divide-y divide-hvm-linie">
                    @foreach ($domainversionen as $version => $anzahl)
                        <x-hvm.rollout-admin-kv :label="(string) $version">{{ $anzahl }}</x-hvm.rollout-admin-kv>
                    @endforeach
                </dl>
            @endif
        </x-hvm.card>

        <x-hvm.card title="Promptversionen" eyebrow="KI" class="min-w-0">
            @if ($prompts === [])
                <p class="text-sm text-hvm-text-sekundaer">Es ist keine Promptversion hinterlegt.</p>
            @else
                <ul class="divide-y divide-hvm-linie">
                    @foreach ($prompts as $prompt)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm first:pt-0 last:pb-0">
                            <x-hvm.badge :variant="$prompt['aktiv'] ? 'success' : 'neutral'" :icon="$prompt['aktiv'] ? 'check-circle' : 'clock'">{{ $prompt['aktiv'] ? 'aktiv' : 'abgelöst' }}</x-hvm.badge>
                            <span>
                                {{ $prompt['zweck'] }}, Version {{ $prompt['version'] }},
                                {{ $prompt['aktiv'] ? 'aktiv' : 'abgelöst' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-hvm.card>
    </div>
@endsection
