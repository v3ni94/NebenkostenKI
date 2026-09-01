{{--
    Begruendung eines Supportzugriffs.

    Ohne Begruendung gibt es keinen Einblick in Kundendaten. Die Begruendung
    wird im Revisionsprotokoll gespeichert.
--}}
@extends('layouts.admin')

@section('titel', 'Supportzugriff begründen')

@section('content')
    <x-hvm.section-heading level="h1" title="Supportzugriff begründen" />

    <div class="mt-6 max-w-2xl space-y-6">
        <x-hvm.alert variant="info" label="Hinweis" title="Zugriff ausschließlich zu Supportzwecken">
            <p>
                Der Einblick in Kundendaten ist ausschließlich zu Supportzwecken zulässig. Die Begründung wird
                mit Akteur, Zeitpunkt und gekürzter IP protokolliert. Die Freischaltung gilt
                {{ \App\Application\Admin\SupportAccessGuard::GUELTIGKEIT_MINUTEN }} Minuten und nur für diesen
                Datensatz.
            </p>
        </x-hvm.alert>

        <x-hvm.card title="Angeforderter Datensatz">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>Art</dt><dd>{{ $entitaet }}</dd></div>
                <div class="flex justify-between"><dt>Kennung</dt><dd class="font-mono text-xs">{{ $id }}</dd></div>
            </dl>
        </x-hvm.card>

        <x-hvm.card title="Begründung">
            <form method="POST" action="{{ route('admin.support.freigeben', ['entitaet' => $entitaet, 'id' => $id]) }}" class="space-y-4">
                @csrf
                <div>
                    <label for="grund" class="block text-sm font-semibold">Begründung</label>
                    <textarea id="grund" name="grund" rows="3" required
                              class="mt-2 w-full rounded border border-hvm-mittelgrau px-3 py-2">{{ old('grund') }}</textarea>
                </div>
                <x-hvm.button type="submit">Einblick freischalten</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>
@endsection
