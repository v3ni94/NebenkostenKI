@extends('layouts.portal')

@section('titel', 'Passwort vergessen')
@section('ohne_navigation', 'ja')

@section('content')
    <div class="mx-auto max-w-md py-4 sm:py-10">
        <x-hvm.section-heading
            eyebrow="Portal"
            title="Passwort zurücksetzen"
            lead="Geben Sie Ihre E-Mail-Adresse an. Wir senden Ihnen einen Link, mit dem Sie ein neues Passwort vergeben." />

        <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
            <form method="POST" action="{{ route('password.email') }}" class="space-y-6 p-6 sm:p-8">
                @csrf

                <x-hvm.field
                    name="email"
                    label="E-Mail-Adresse"
                    type="email"
                    autocomplete="email"
                    :required="true"
                    autofocus />

                <x-hvm.button type="submit" variant="primary" size="lg" class="w-full">Link anfordern</x-hvm.button>
            </form>
        </x-hvm.card>

        <p class="mt-8 text-sm text-hvm-text-sekundaer">
            <a class="font-medium text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz" href="{{ route('login') }}">Zurück zur Anmeldung</a>
        </p>
    </div>
@endsection
