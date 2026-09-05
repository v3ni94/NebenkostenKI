{{--
    Zweiter Schritt der Anmeldung: Eingabe des Codes.

    Die Sitzung ist an dieser Stelle bewusst nicht angemeldet. Die Seite nennt
    deshalb weder Name noch E-Mail-Adresse des Kontos.
--}}
@extends('layouts.portal')

@section('titel', 'Anmeldung bestätigen')
@section('ohne_navigation', 'ja')

@section('content')
    <div class="mx-auto max-w-md py-4 sm:py-10">
        <x-hvm.section-heading
            eyebrow="Schritt 2 von 2"
            title="Anmeldung bestätigen"
            lead="Bitte geben Sie den sechsstelligen Code aus Ihrer Authenticator-App ein." />

        <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
            <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="space-y-6 p-6 sm:p-8">
                @csrf

                <x-hvm.field
                    name="code"
                    label="Code"
                    type="text"
                    autocomplete="one-time-code"
                    inputmode="text"
                    spellcheck="false"
                    hint="Der Code wechselt alle 30 Sekunden. Alternativ können Sie einen Ihrer Wiederherstellungscodes eingeben. Jeder Wiederherstellungscode gilt genau einmal."
                    class="tracking-widest"
                    :required="true"
                    autofocus />

                <x-hvm.button type="submit" variant="primary" size="lg" class="w-full">Anmeldung abschließen</x-hvm.button>
            </form>
        </x-hvm.card>

        <div class="mt-8 space-y-4 text-sm text-hvm-text-sekundaer">
            <form method="POST" action="{{ route('two-factor.abort') }}">
                @csrf
                <button type="submit" class="inline-flex min-h-11 items-center font-medium text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz">
                    Anmeldung abbrechen
                </button>
            </form>

            <p class="leading-relaxed">
                Sie haben keinen Zugriff mehr auf Ihre Authenticator-App und keinen Wiederherstellungscode?
                Bitte wenden Sie sich an kontakt@smart-abrechnen.de.
            </p>
        </div>
    </div>
@endsection
