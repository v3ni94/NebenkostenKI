{{--
    Revisionsprotokoll.

    Die IP ist bereits beim Schreiben gekuerzt, der User-Agent liegt nur als
    Hash vor. In den Metadaten stehen ausschliesslich technische Kennzahlen.
--}}
@extends('layouts.admin')

@section('titel', 'Protokoll')

@section('content')
    <x-hvm.page-header
        eyebrow="Protokoll"
        title="Revisionsprotokoll"
        lead="Akteur, Aktion, Entität, Zeitpunkt, gekürzte IP und Begründung. Vollständige IP-Adressen werden nicht gespeichert." />

    <div class="mt-10">
        <x-hvm.card title="Filter" eyebrow="Eingrenzung">
            <form method="GET" action="{{ route('admin.protokoll') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1 sm:max-w-md">
                    <x-hvm.field name="aktion" label="Aktion beginnt mit" type="search" :value="$aktion" />
                </div>
                <x-hvm.button type="submit" variant="secondary" class="shrink-0">
                    <x-hvm.icon name="search" class="h-4 w-4" />
                    Filtern
                </x-hvm.button>
            </form>
        </x-hvm.card>
    </div>

    <x-hvm.rollout-admin-abschnitt class="mt-16" eyebrow="Revision" title="Einträge" :leer="$eintraege === []" leer-icon="clock">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Einträge des Revisionsprotokolls</caption>
            <thead>
                <tr>
                    <th scope="col">Zeitpunkt</th>
                    <th scope="col">Akteur</th>
                    <th scope="col">Interne Rolle</th>
                    <th scope="col">Aktion</th>
                    <th scope="col">Entität</th>
                    <th scope="col">Gekürzte IP</th>
                    <th scope="col">Begründung</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($eintraege as $eintrag)
                    <tr>
                        <th scope="row" class="font-medium whitespace-nowrap tabular">{{ \Illuminate\Support\Carbon::parse((string) $eintrag->getAttribute('occurred_at'))->format('d.m.Y H:i') }}</th>
                        <td data-label="Akteur">{{ $eintrag->actor?->getAttribute('name') ?? 'System' }}</td>
                        <td data-label="Interne Rolle" class="text-hvm-text-sekundaer">{{ $eintrag->getAttribute('actor_admin_role')?->label() ?? '' }}</td>
                        <td data-label="Aktion" class="font-mono text-xs">{{ $eintrag->getAttribute('action') }}</td>
                        <td data-label="Entität" class="font-mono text-xs">{{ class_basename((string) $eintrag->getAttribute('subject_type')) }}</td>
                        <td data-label="Gekürzte IP" class="font-mono text-xs">{{ $eintrag->getAttribute('ip_truncated') ?? '' }}</td>
                        <td data-label="Begründung" class="text-hvm-text-sekundaer">{{ $eintrag->getAttribute('reason') ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.rollout-admin-abschnitt>
@endsection
