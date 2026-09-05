@extends('layouts.site')

@section('meta_title', 'Kontakt')
@section('meta_description', 'Kontakt zur Hausverwaltung Müller GmbH als Betreiberin von Smart Abrechnen. Anfragen erreichen uns per E-Mail an kontakt@smart-abrechnen.de.')

@php
    $operator = config('smartabrechnen.operator');
@endphp

@section('content')
    <section class="border-b border-hvm-umrissgrau bg-hvm-umrissgrau">
        <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16">
            <x-hvm.badge variant="akzent">Kontakt</x-hvm.badge>
            <h1 class="mt-5 text-3xl font-bold text-hvm-anthrazit sm:text-4xl">
                So erreichen Sie uns
            </h1>
            <p class="mt-5 text-lg leading-relaxed text-hvm-textschwarz">
                {{ config('smartabrechnen.brand.relation') }} Anfragen zum Portal, zu einem
                laufenden Abrechnungslauf oder zum Datenschutz beantworten wir per E-Mail.
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16">
        <div class="grid gap-6 lg:grid-cols-2">
            <x-hvm.card :accent="true" title="E-Mail">
                <p>
                    <a href="mailto:kontakt@smart-abrechnen.de" class="text-lg font-semibold underline underline-offset-2">
                        kontakt@smart-abrechnen.de
                    </a>
                </p>
                <p class="mt-4">
                    Bitte nennen Sie in Ihrer Anfrage die E-Mail-Adresse Ihres Kontos sowie Objekt und Abrechnungsjahr,
                    falls es um einen bestimmten Abrechnungslauf geht. Das verkürzt die Bearbeitung.
                </p>
            </x-hvm.card>

            {{--
                Betreiberangaben ausschliesslich aus config('smartabrechnen.operator').
                Nicht ergaenzen, nicht abwandeln. Es wird bewusst keine Telefonnummer
                angegeben, solange keine vom Auftraggeber freigegebene Nummer vorliegt.
            --}}
            <x-hvm.card title="Anschrift der Betreiberin">
                <address class="not-italic">
                    {{ $operator['legal_name'] }}<br>
                    {{ $operator['address_line'] }}<br>
                    {{ $operator['postal_code'] }} {{ $operator['city'] }}
                </address>
                <dl class="mt-4 space-y-1">
                    <div>
                        <dt class="inline font-medium">Geschäftsführer:</dt>
                        <dd class="inline">{{ $operator['managing_director'] }}</dd>
                    </div>
                    <div>
                        <dt class="inline font-medium">Registergericht:</dt>
                        <dd class="inline">{{ $operator['register_court'] }}</dd>
                    </div>
                    <div>
                        <dt class="inline font-medium">Registernummer:</dt>
                        <dd class="inline">{{ $operator['register_number'] }}</dd>
                    </div>
                    <div>
                        <dt class="inline font-medium">Website:</dt>
                        <dd class="inline">
                            <a href="{{ $operator['website'] }}" class="underline underline-offset-2">{{ $operator['website'] }}</a>
                        </dd>
                    </div>
                </dl>
                <p class="mt-4 text-sm">
                    Die vollständigen Pflichtangaben finden Sie im
                    <a href="{{ route('legal.impressum') }}" class="underline underline-offset-2">Impressum</a>.
                </p>
            </x-hvm.card>
        </div>

        <div class="mt-8 space-y-4">
            <x-hvm.alert variant="warning" title="Keine Unterlagen per E-Mail">
                <p>
                    Bitte senden Sie keine Belege, Rechnungen, Bescheide oder Mietverträge per E-Mail. Unterlagen
                    werden ausschließlich im Portal hochgeladen und dort nach der Auswertung automatisch gelöscht. Per
                    E-Mail versandte Anhänge würden in einem Postfach verbleiben.
                </p>
            </x-hvm.alert>

            <x-hvm.alert variant="info" title="Umfang der Unterstützung">
                <p>
                    Wir unterstützen bei Fragen zur Bedienung des Portals, zum Ablauf, zur Zahlung und zur Rechnung.
                    Eine Rechtsberatung im Einzelfall und eine steuerliche Beratung sind nicht Teil der Leistung. Für
                    diese Fragen wenden Sie sich an einen Rechtsanwalt oder Steuerberater.
                </p>
            </x-hvm.alert>
        </div>

        <div class="mt-10 flex flex-wrap gap-3">
            <x-hvm.button href="{{ route('site.faq') }}" variant="secondary">Häufige Fragen ansehen</x-hvm.button>
            <x-hvm.button href="{{ url('/app') }}" variant="primary">Kostenlos starten</x-hvm.button>
        </div>
    </div>
@endsection
