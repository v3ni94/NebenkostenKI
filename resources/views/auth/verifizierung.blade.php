@extends('layouts.portal')

@section('titel', 'E-Mail-Adresse bestätigen')

@section('content')
    <div class="mx-auto max-w-2xl">
        <x-hvm.section-heading
            title="Bitte bestätigen Sie Ihre E-Mail-Adresse"
            lead="Wir haben Ihnen eine E-Mail gesendet. Öffnen Sie den darin enthaltenen Link, um Ihre Adresse zu bestätigen." />

        <x-hvm.card class="mt-8" accent>
            <p>
                Sie können Ihre Objekte, Einheiten und Abrechnungsentwürfe bereits jetzt anlegen. Für die Zahlung
                und den Download der fertigen Abrechnungen ist die Bestätigung erforderlich.
            </p>

            <p class="mt-4">
                Ihre hinterlegte Adresse lautet:
                <span class="font-semibold">{{ auth()->user()?->email }}</span>
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf
                    <x-hvm.button type="submit" variant="primary">E-Mail erneut senden</x-hvm.button>
                </form>

                <x-hvm.button href="{{ route('portal.dashboard') }}" variant="secondary">
                    Weiter zur Übersicht
                </x-hvm.button>
            </div>
        </x-hvm.card>

        <p class="mt-6 text-sm text-hvm-anthrazit">
            Keine E-Mail erhalten? Prüfen Sie bitte auch den Spamordner. Sie können Ihre Adresse im Kontobereich
            ändern und den Link erneut anfordern.
        </p>
    </div>
@endsection
