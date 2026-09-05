{{--
    Organisationen.

    Die Liste zeigt nur Bezeichnung, Typ und Zaehlwerte. Der Einblick in einen
    Datensatz verlangt eine Begruendung.
--}}
@extends('layouts.admin')

@section('titel', 'Organisationen')

@section('content')
    <x-hvm.page-header
        eyebrow="Support"
        title="Organisationen"
        lead="Der Einblick in Kundendaten ist nur zu Supportzwecken zulässig, verlangt eine Begründung und wird protokolliert." />

    <div class="mt-10">
        <x-hvm.card title="Suche" eyebrow="Filter">
            <form method="GET" action="{{ route('admin.organisationen') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1 sm:max-w-md">
                    <x-hvm.field name="suche" label="Bezeichnung" type="search" :value="$suche" />
                </div>
                <x-hvm.button type="submit" variant="secondary" class="shrink-0">
                    <x-hvm.icon name="search" class="h-4 w-4" />
                    Suchen
                </x-hvm.button>
            </form>
        </x-hvm.card>
    </div>

    <x-hvm.rollout-admin-abschnitt class="mt-16" eyebrow="Bestand" title="Mandanten" :leer="$organisationen === []" leer-icon="building">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Mandanten</caption>
            <thead>
                <tr>
                    <th scope="col">Bezeichnung</th>
                    <th scope="col">Typ</th>
                    <th scope="col" class="betrag">Objekte</th>
                    <th scope="col" class="betrag">Läufe</th>
                    <th scope="col">Support</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($organisationen as $organisation)
                    <tr>
                        <th scope="row" class="font-medium">{{ $organisation->getAttribute('name') }}</th>
                        <td data-label="Typ">{{ $organisation->getAttribute('type')->label() }}</td>
                        <td data-label="Objekte" class="betrag">{{ $organisation->getAttribute('properties_count') }}</td>
                        <td data-label="Läufe" class="betrag">{{ $organisation->getAttribute('billing_runs_count') }}</td>
                        <td data-label="Support">
                            <x-hvm.button
                                href="{{ route('admin.organisationen.show', $organisation) }}"
                                variant="secondary"
                                size="sm">
                                Einblick anfordern
                                <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                            </x-hvm.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.rollout-admin-abschnitt>
@endsection
