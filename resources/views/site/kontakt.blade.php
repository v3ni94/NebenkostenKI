@extends('layouts.site')

@section('meta_title', 'Kontakt')
@section('meta_description', 'Kontakt zur Hausverwaltung Müller GmbH als Betreiberin von Smart Abrechnen. Anfragen erreichen uns per E-Mail an kontakt@smart-abrechnen.de.')

@php
    $operator = config('smartabrechnen.operator');
@endphp

@section('content')
    {{-- Seitenkopf Website (Designsystem 4.2). --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 pt-16 pb-20 sm:px-6 lg:px-8 lg:pt-24 lg:pb-28">
            <x-hvm.badge variant="akzent" :icon="false">Kontakt</x-hvm.badge>
            <h1 class="mt-6 text-4xl leading-[1.05] font-semibold tracking-tight text-hvm-textschwarz sm:text-5xl lg:text-6xl">
                So erreichen Sie uns
            </h1>
            <p class="mt-7 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer sm:text-xl">
                {{ config('smartabrechnen.brand.relation') }} Anfragen zum Portal, zu einem
                laufenden Abrechnungslauf oder zum Datenschutz beantworten wir per E-Mail.
            </p>
        </div>
    </section>

    {{-- Kontaktwege --}}
    <section class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-12 lg:items-start">
                {{-- E-Mail als Hauptkontaktweg mit Kennlinie. --}}
                <div class="min-w-0 lg:col-span-5">
                    <x-hvm.card :kennlinie="true" padding="none" class="rounded-3xl">
                        <div class="p-7 sm:p-9">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="mail" /></span>
                            <h2 class="mt-5 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">E-Mail</h2>
                            <p class="mt-4">
                                <a href="mailto:kontakt@smart-abrechnen.de" class="inline-block text-xl font-semibold tracking-tight text-hvm-textschwarz underline decoration-hvm-orange decoration-2 underline-offset-4 sm:text-2xl">
                                    kontakt@smart-abrechnen.de
                                </a>
                            </p>
                            <p class="mt-6 text-base leading-relaxed text-hvm-text-sekundaer">
                                Bitte nennen Sie in Ihrer Anfrage die E-Mail-Adresse Ihres Kontos sowie Objekt und Abrechnungsjahr,
                                falls es um einen bestimmten Abrechnungslauf geht. Das verkürzt die Bearbeitung.
                            </p>
                        </div>
                    </x-hvm.card>
                </div>

                {{--
                    Betreiberangaben ausschliesslich aus config('smartabrechnen.operator').
                    Nicht ergaenzen, nicht abwandeln. Es wird bewusst keine Telefonnummer
                    angegeben, solange keine vom Auftraggeber freigegebene Nummer vorliegt.
                --}}
                <div class="min-w-0 lg:col-span-7">
                    <x-hvm.card tone="canvas" class="h-full rounded-3xl p-7 sm:p-9">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="building" /></span>
                        <h2 class="mt-5 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Anschrift der Betreiberin</h2>

                        <div class="mt-6 grid grid-cols-1 gap-8 sm:grid-cols-2">
                            <address class="text-base leading-relaxed not-italic text-hvm-textschwarz">
                                <span class="block font-semibold">{{ $operator['legal_name'] }}</span>
                                {{ $operator['address_line'] }}<br>
                                {{ $operator['postal_code'] }} {{ $operator['city'] }}
                            </address>

                            <dl class="space-y-3 text-sm leading-relaxed">
                                <div>
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Geschäftsführer:</dt>
                                    <dd class="mt-0.5 text-base text-hvm-textschwarz">{{ $operator['managing_director'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Registergericht:</dt>
                                    <dd class="mt-0.5 text-base text-hvm-textschwarz">{{ $operator['register_court'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Registernummer:</dt>
                                    <dd class="mt-0.5 text-base text-hvm-textschwarz">{{ $operator['register_number'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Website:</dt>
                                    <dd class="mt-0.5 text-base">
                                        <a href="{{ $operator['website'] }}" class="font-medium text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz">{{ $operator['website'] }}</a>
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <p class="mt-6 border-t border-hvm-linie pt-5 text-sm leading-relaxed text-hvm-text-sekundaer">
                            Die vollständigen Pflichtangaben finden Sie im
                            <a href="{{ route('legal.impressum') }}" class="font-medium text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz">Impressum</a>.
                        </p>
                    </x-hvm.card>
                </div>
            </div>
        </div>
    </section>

    {{-- Hinweise zum Umfang --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Gut zu wissen"
                        title="Bevor Sie schreiben" />
                </div>

                <div class="min-w-0 space-y-4 lg:col-span-8">
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

                    <div class="flex flex-wrap gap-3 pt-6">
                        <x-hvm.button href="{{ url('/app') }}" variant="primary" size="lg">
                            Kostenlos starten
                            <x-hvm.icon name="arrow-right" class="h-5 w-5" />
                        </x-hvm.button>
                        <x-hvm.button href="{{ route('site.faq') }}" variant="secondary" size="lg">Häufige Fragen ansehen</x-hvm.button>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
