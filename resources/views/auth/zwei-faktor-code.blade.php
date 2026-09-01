{{--
    Zweiter Schritt der Anmeldung: Eingabe des Codes.

    Die Sitzung ist an dieser Stelle bewusst nicht angemeldet. Die Seite nennt
    deshalb weder Name noch E-Mail-Adresse des Kontos.
--}}
@extends('layouts.portal')

@section('titel', 'Anmeldung bestätigen')
@section('ohne_navigation', 'ja')

@section('content')
    <div class="mx-auto max-w-xl">
        <x-hvm.section-heading
            eyebrow="Schritt 2 von 2"
            title="Anmeldung bestätigen"
            lead="Bitte geben Sie den sechsstelligen Code aus Ihrer Authenticator-App ein." />

        <x-hvm.card class="mt-8">
            <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="code" class="block text-sm font-semibold text-hvm-textschwarz">Code</label>
                    <input id="code" name="code" type="text" required autofocus inputmode="text"
                           autocomplete="one-time-code" spellcheck="false"
                           @error('code') aria-invalid="true" aria-describedby="code-hinweis code-fehler" @else aria-describedby="code-hinweis" @enderror
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2 tracking-widest">
                    <p id="code-hinweis" class="mt-1 text-sm text-hvm-anthrazit">
                        Der Code wechselt alle 30 Sekunden. Alternativ können Sie einen Ihrer
                        Wiederherstellungscodes eingeben. Jeder Wiederherstellungscode gilt genau einmal.
                    </p>
                    @error('code')
                        <p id="code-fehler" class="mt-1 text-sm text-status-error">{{ $message }}</p>
                    @enderror
                </div>

                <x-hvm.button type="submit" variant="primary">Anmeldung abschließen</x-hvm.button>
            </form>
        </x-hvm.card>

        <form method="POST" action="{{ route('two-factor.abort') }}" class="mt-6">
            @csrf
            <button type="submit" class="text-sm font-medium underline underline-offset-2">
                Anmeldung abbrechen
            </button>
        </form>

        <p class="mt-4 text-sm text-hvm-anthrazit">
            Sie haben keinen Zugriff mehr auf Ihre Authenticator-App und keinen Wiederherstellungscode?
            Bitte wenden Sie sich an kontakt@smart-abrechnen.de.
        </p>
    </div>
@endsection
