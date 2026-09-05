@extends('layouts.portal')

@section('titel', 'Abrechnungsweg')

@section('content')
    <x-hvm.page-header
        eyebrow="Prüfung"
        title="Abrechnungsweg"
        lead="Wir schlagen den Weg vor, der zu Ihren Unterlagen passt. Sie können jederzeit wechseln."
        :back="route('portal.pruefung.analyse', ['billingRun' => $billingRun->getKey()])"
        backLabel="Zur Analyse" />

    @if (session('status'))
        <x-hvm.alert variant="success" class="mt-8">
            <p>{{ session('status') }}</p>
        </x-hvm.alert>
    @endif

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div class="min-w-0 space-y-6 lg:col-span-5">
            <x-hvm.card title="Vorschlag" eyebrow="Einordnung">
                <p class="font-semibold text-hvm-textschwarz">{{ $vorschlag->suggested->label() }}</p>

                <ul class="mt-3 space-y-2 text-sm leading-relaxed text-hvm-text-sekundaer">
                    @foreach ($vorschlag->reasons as $grund)
                        <li class="flex gap-2">
                            <span aria-hidden="true" class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-hvm-text-sekundaer"></span>
                            <span>{{ $grund }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="mt-5 rounded-2xl bg-hvm-canvas p-4 text-sm text-hvm-textschwarz">
                    Aktuell gewählt: <span class="font-semibold">{{ $vorschlag->current->label() }}</span>
                </p>
            </x-hvm.card>

            <x-hvm.alert variant="info" label="Hinweis">
                <p>
                    Ein Wechsel löscht keine ausgelesenen Inhaltsdaten. Ihre bereits erfassten Werte bleiben erhalten und
                    werden neu eingeordnet.
                </p>
            </x-hvm.alert>
        </div>

        <div class="min-w-0 lg:col-span-7">
            <x-hvm.card :kennlinie="true" padding="none" class="rounded-3xl">
                <form method="POST" action="{{ route('portal.pruefung.weg.update', ['billingRun' => $billingRun->getKey()]) }}"
                      class="space-y-6 p-6 sm:p-8">
                    @csrf
                    @method('PUT')

                    <x-hvm.field
                        name="mode"
                        label="Abrechnungsweg wählen"
                        type="radio-group"
                        :value="$vorschlag->current->value"
                        :options="collect(\App\Enums\BillingMode::cases())->mapWithKeys(fn ($modus) => [$modus->value => $modus->label()])->all()" />

                    <div class="flex flex-wrap gap-3">
                        <x-hvm.button type="submit" variant="primary">Abrechnungsweg speichern</x-hvm.button>
                    </div>
                </form>
            </x-hvm.card>
        </div>
    </div>
@endsection
