@extends('layouts.portal')

@section('titel', 'Abrechnungsweg')

@section('content')
    <x-hvm.section-heading
        title="Abrechnungsweg"
        lead="Wir schlagen den Weg vor, der zu Ihren Unterlagen passt. Sie können jederzeit wechseln." />

    @if (session('status'))
        <x-hvm.alert variant="success" class="mt-6">
            <p>{{ session('status') }}</p>
        </x-hvm.alert>
    @endif

    <x-hvm.card class="mt-6" title="Vorschlag">
        <p>{{ $vorschlag->suggested->label() }}</p>

        <ul class="mt-3 space-y-1 text-sm">
            @foreach ($vorschlag->reasons as $grund)
                <li>{{ $grund }}</li>
            @endforeach
        </ul>

        <p class="mt-4 text-sm">
            Aktuell gewählt: {{ $vorschlag->current->label() }}
        </p>
    </x-hvm.card>

    <x-hvm.alert variant="info" class="mt-6" label="Hinweis">
        <p>
            Ein Wechsel löscht keine ausgelesenen Inhaltsdaten. Ihre bereits erfassten Werte bleiben erhalten und
            werden neu eingeordnet.
        </p>
    </x-hvm.alert>

    <form method="POST" action="{{ route('portal.pruefung.weg.update', ['billingRun' => $billingRun->getKey()]) }}"
          class="mt-6">
        @csrf
        @method('PUT')

        <fieldset>
            <legend class="text-sm font-semibold">Abrechnungsweg wählen</legend>

            @foreach (\App\Enums\BillingMode::cases() as $modus)
                <label class="mt-3 flex items-start gap-3">
                    <input type="radio" name="mode" value="{{ $modus->value }}" class="mt-1"
                           @checked($vorschlag->current === $modus) />
                    <span>{{ $modus->label() }}</span>
                </label>
            @endforeach
        </fieldset>

        <div class="mt-6">
            <x-hvm.button type="submit" variant="primary">Abrechnungsweg speichern</x-hvm.button>
        </div>
    </form>
@endsection
