{{--
    Begruendung eines Supportzugriffs.

    Ohne Begruendung gibt es keinen Einblick in Kundendaten. Die Begruendung
    wird im Revisionsprotokoll gespeichert.
--}}
@extends('layouts.admin')

@section('titel', 'Supportzugriff begründen')

@section('content')
    <x-hvm.page-header
        eyebrow="Support"
        title="Supportzugriff begründen"
        :back="route('admin.organisationen')"
        back-label="Organisationen" />

    <div class="mt-8 max-w-2xl space-y-8">
        <x-hvm.alert variant="info" label="Hinweis" title="Zugriff ausschließlich zu Supportzwecken">
            <p>
                Der Einblick in Kundendaten ist ausschließlich zu Supportzwecken zulässig. Die Begründung wird
                mit Akteur, Zeitpunkt und gekürzter IP protokolliert. Die Freischaltung gilt
                {{ \App\Application\Admin\SupportAccessGuard::GUELTIGKEIT_MINUTEN }} Minuten und nur für diesen
                Datensatz.
            </p>
        </x-hvm.alert>

        <x-hvm.card title="Angeforderter Datensatz" eyebrow="Datensatz">
            <dl class="divide-y divide-hvm-linie">
                <x-hvm.rollout-admin-kv label="Art">{{ $entitaet }}</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Kennung" :mono="true">{{ $id }}</x-hvm.rollout-admin-kv>
            </dl>
        </x-hvm.card>

        <x-hvm.card title="Begründung" eyebrow="Freischaltung" :kennlinie="true" class="rounded-3xl">
            <form method="POST" action="{{ route('admin.support.freigeben', ['entitaet' => $entitaet, 'id' => $id]) }}" class="space-y-6">
                @csrf
                <x-hvm.field
                    name="grund"
                    label="Begründung"
                    type="textarea"
                    rows="3"
                    :required="true" />
                <x-hvm.button type="submit" variant="primary">Einblick freischalten</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>
@endsection
