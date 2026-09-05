@extends('layouts.portal')

@section('titel', 'Konto anlegen')
@section('ohne_navigation', 'ja')

@section('content')
    <div class="mx-auto max-w-md py-4 sm:py-10">
        <x-hvm.section-heading
            eyebrow="Schritt 1"
            title="Konto anlegen"
            lead="Ihr Konto und alle Entwürfe sind kostenlos. Bezahlt wird erst, wenn Sie die Vorschau geprüft haben." />

        <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
            <form method="POST" action="{{ route('register') }}" class="space-y-6 p-6 sm:p-8">
                @csrf

                <x-hvm.field
                    name="name"
                    label="Name"
                    type="text"
                    autocomplete="name"
                    :required="true" />

                <x-hvm.field
                    name="email"
                    label="E-Mail-Adresse"
                    type="email"
                    autocomplete="email"
                    :required="true" />

                <x-hvm.field
                    name="password"
                    label="Passwort"
                    type="password"
                    autocomplete="new-password"
                    hint="Mindestens 12 Zeichen mit Buchstaben und Ziffern."
                    :required="true" />

                <x-hvm.field
                    name="password_confirmation"
                    label="Passwort wiederholen"
                    type="password"
                    autocomplete="new-password"
                    :required="true" />

                {{-- Beschriftung mit Link ueber den Slot labelHtml der Komponente. --}}
                <x-hvm.field name="datenschutz" type="checkbox" value="1" align="start" :checked="old('datenschutz') !== null">
                    <x-slot:labelHtml>
                        Ich habe die
                        <a class="font-medium underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz" href="{{ route('legal.datenschutz') }}">Datenschutzerklärung</a>
                        zur Kenntnis genommen.
                    </x-slot:labelHtml>
                </x-hvm.field>

                <x-hvm.button type="submit" variant="primary" size="lg" class="w-full">Konto anlegen</x-hvm.button>
            </form>
        </x-hvm.card>

        <p class="mt-8 text-sm text-hvm-text-sekundaer">
            Sie haben bereits ein Konto?
            <a class="font-medium text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz" href="{{ route('login') }}">Hier anmelden</a>
        </p>
    </div>
@endsection
