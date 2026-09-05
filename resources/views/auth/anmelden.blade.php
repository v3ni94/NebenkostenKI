@extends('layouts.portal')

@section('titel', 'Anmelden')
@section('ohne_navigation', 'ja')

@section('content')
    <div class="mx-auto max-w-md py-4 sm:py-10">
        <x-hvm.section-heading
            eyebrow="Portal"
            title="Anmelden"
            lead="Melden Sie sich mit Ihrer E-Mail-Adresse und Ihrem Passwort an." />

        <div class="mt-10 overflow-hidden rounded-3xl border border-hvm-linie bg-white">
            <div class="hvm-kennlinie" aria-hidden="true"></div>
            <form method="POST" action="{{ route('login') }}" class="space-y-6 p-6 sm:p-8">
                @csrf

                <x-hvm.field
                    name="email"
                    label="E-Mail-Adresse"
                    type="email"
                    autocomplete="email"
                    :required="true"
                    autofocus />

                <x-hvm.field
                    name="password"
                    label="Passwort"
                    type="password"
                    autocomplete="current-password"
                    :required="true" />

                <x-hvm.field name="remember" label="Angemeldet bleiben" type="checkbox" value="1" />

                <x-hvm.button type="submit" variant="primary" size="lg" class="w-full">Anmelden</x-hvm.button>
            </form>
        </div>

        <div class="mt-8 flex flex-col gap-3 text-sm text-hvm-text-sekundaer sm:flex-row sm:items-center sm:justify-between">
            <p>
                <a class="font-medium text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz" href="{{ route('password.request') }}">
                    Passwort vergessen
                </a>
            </p>
            <p>
                Noch kein Konto?
                <a class="font-medium text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz" href="{{ route('register') }}">Jetzt anlegen</a>
            </p>
        </div>
    </div>
@endsection
