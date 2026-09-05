@extends('layouts.portal')

@section('titel', 'Mietverhältnisse')

@section('content')
    <x-hvm.page-header
        eyebrow="{{ $objekt->label }}, Einheit {{ $einheit->label }}"
        title="Mietverhältnisse und Zeitachse"
        lead="Für jeden Tag des Abrechnungszeitraums muss entweder ein Mietverhältnis oder ein Leerstand erfasst sein."
        :back="route('portal.einheiten.index', ['property' => $objekt->getKey()])"
        backLabel="Zurück zu den Einheiten">
        <x-slot:actions>
            <x-hvm.button href="{{ route('portal.mietverhaeltnisse.create', ['unit' => $einheit->getKey()]) }}" variant="primary">
                <x-hvm.icon name="plus" class="h-4 w-4" />
                Mietverhältnis anlegen
            </x-hvm.button>
        </x-slot:actions>
    </x-hvm.page-header>

    {{-- Gepruefter Zeitraum ------------------------------------------------------ --}}

    <x-hvm.card class="mt-10" eyebrow="Geprüfter Zeitraum"
                :title="\Carbon\CarbonImmutable::parse($rahmen->startIso())->format('d.m.Y').' bis '.\Carbon\CarbonImmutable::parse($rahmen->endIso())->format('d.m.Y')">
        @if ($lueckenlos && $befunde === [])
            <x-hvm.alert variant="success" label="Erledigt">
                Der Zeitraum ist lückenlos durch Mietverhältnisse oder Leerstand abgedeckt.
            </x-hvm.alert>
        @else
            <x-hvm.alert variant="warning" label="Bitte prüfen" title="Hinweise zur Zeitachse">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($befunde as $befund)
                        <li>{{ $befund['text'] }}</li>
                    @endforeach
                    @if (! $lueckenlos && $befunde === [])
                        <li>Der Zeitraum ist noch nicht lückenlos abgedeckt.</li>
                    @endif
                </ul>
            </x-hvm.alert>
        @endif
    </x-hvm.card>

    {{-- Mietverhaeltnisse ------------------------------------------------------ --}}

    <section class="mt-16" aria-labelledby="ueberschrift-mietverhaeltnisse">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Belegung</p>
                <h2 id="ueberschrift-mietverhaeltnisse" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Mietverhältnisse</h2>
            </div>
        </div>

        @if ($mietverhaeltnisse->isEmpty())
            <x-hvm.empty-state class="mt-6" icon="user" title="Noch kein Mietverhältnis">
                <p>Für diese Einheit ist noch kein Mietverhältnis erfasst.</p>
                <x-slot:action>
                    <x-hvm.button href="{{ route('portal.mietverhaeltnisse.create', ['unit' => $einheit->getKey()]) }}" variant="secondary">
                        Mietverhältnis anlegen
                    </x-hvm.button>
                </x-slot:action>
            </x-hvm.empty-state>
        @else
            <div class="mt-6 space-y-4">
                @foreach ($mietverhaeltnisse as $mietverhaeltnis)
                    @php
                        $zeitraum = 'Einzug '.$mietverhaeltnis->starts_on?->format('d.m.Y').', '
                            .($mietverhaeltnis->ends_on !== null ? 'Auszug '.$mietverhaeltnis->ends_on->format('d.m.Y') : 'laufend');
                        $schluessel = $mietverhaeltnis->getKey();
                    @endphp
                    <x-hvm.card padding="none">
                        <x-hvm.list-row
                            :stacked="true"
                            :title="$mietverhaeltnis->tenant_display_name"
                            :subtitle="$zeitraum">
                            <div class="grid gap-5 border-t border-hvm-linie pt-5 lg:grid-cols-12 lg:gap-8">
                                <div class="space-y-4 text-sm lg:col-span-4">
                                    <div>
                                        <p class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Vertrag</p>
                                        <p class="mt-1 text-hvm-textschwarz">
                                            {{ $mietverhaeltnis->kind->label() }}, Status {{ $mietverhaeltnis->status->label() }}
                                        </p>
                                    </div>

                                    @if ($mietverhaeltnis->kind === \App\Enums\TenancyKind::GEWERBE)
                                        <x-hvm.alert variant="warning" label="Bitte prüfen">
                                            Gewerbliche Mietverhältnisse werden nicht automatisch finalisiert.
                                            Bitte prüfen Sie die Umlagevereinbarung und die umsatzsteuerliche Behandlung
                                            gesondert.
                                        </x-hvm.alert>
                                    @endif
                                </div>

                                <div class="lg:col-span-8">
                                    <p class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Personenanzahl je Zeitraum</p>

                                    @if ($mietverhaeltnis->occupancyPeriods->isEmpty())
                                        <p class="mt-1 text-sm text-hvm-text-sekundaer">Noch kein Zeitraum erfasst.</p>
                                    @else
                                        <ul class="mt-2 divide-y divide-hvm-linie rounded-2xl bg-hvm-canvas px-4">
                                            @foreach ($mietverhaeltnis->occupancyPeriods as $belegung)
                                                <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                                                    <span class="text-sm text-hvm-textschwarz">
                                                        {{ $belegung->starts_on?->format('d.m.Y') }}
                                                        bis {{ $belegung->ends_on?->format('d.m.Y') }}:
                                                        {{ $belegung->person_count }}
                                                        {{ $belegung->person_count === 1 ? 'Person' : 'Personen' }}
                                                    </span>
                                                    <form method="POST"
                                                          action="{{ route('portal.belegung.destroy', ['occupancy' => $belegung->getKey()]) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <x-hvm.button type="submit" variant="danger" size="sm">
                                                            <x-hvm.icon name="trash" class="h-4 w-4" />
                                                            Entfernen
                                                        </x-hvm.button>
                                                    </form>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    {{--
                                        Die Feldnamen kommen auf der Seite mehrfach vor (je Mietverhaeltnis und
                                        beim Leerstand). Feldfehler stehen deshalb nur in der Sammelmeldung des
                                        Layouts; errorKey zeigt auf einen leeren Schluessel.
                                    --}}
                                    <form method="POST"
                                          action="{{ route('portal.belegung.store', ['tenancy' => $schluessel]) }}"
                                          class="mt-4">
                                        @csrf
                                        <div class="grid gap-4 sm:grid-cols-3">
                                            <x-hvm.field
                                                name="starts_on"
                                                :id="'belegung-start-'.$schluessel"
                                                :errorKey="'belegung-start-'.$schluessel"
                                                label="Von"
                                                type="date"
                                                :required="true"
                                                value="" />
                                            <x-hvm.field
                                                name="ends_on"
                                                :id="'belegung-ende-'.$schluessel"
                                                :errorKey="'belegung-ende-'.$schluessel"
                                                label="Bis"
                                                type="date"
                                                :required="true"
                                                value="" />
                                            <x-hvm.field
                                                name="person_count"
                                                :id="'belegung-anzahl-'.$schluessel"
                                                :errorKey="'belegung-anzahl-'.$schluessel"
                                                label="Personen"
                                                type="number"
                                                min="0"
                                                max="99"
                                                :required="true"
                                                value="" />
                                        </div>
                                        <div class="mt-4">
                                            <x-hvm.button type="submit" variant="secondary" size="sm">
                                                <x-hvm.icon name="plus" class="h-4 w-4" />
                                                Zeitraum hinzufügen
                                            </x-hvm.button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <x-slot:actions>
                                <x-hvm.button href="{{ route('portal.mietverhaeltnisse.edit', ['tenancy' => $schluessel]) }}"
                                              variant="secondary" size="sm">Bearbeiten</x-hvm.button>
                                <form method="POST"
                                      action="{{ route('portal.mietverhaeltnisse.destroy', ['tenancy' => $schluessel]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-hvm.button type="submit" variant="danger" size="sm">
                                        <x-hvm.icon name="trash" class="h-4 w-4" />
                                        Entfernen
                                    </x-hvm.button>
                                </form>
                            </x-slot:actions>
                        </x-hvm.list-row>
                    </x-hvm.card>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Leerstand -------------------------------------------------------------- --}}

    <section class="mt-16" aria-labelledby="ueberschrift-leerstand">
        <div>
            <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Zeit ohne Mieter</p>
            <h2 id="ueberschrift-leerstand" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Leerstand</h2>
            <p class="mt-3 max-w-prose text-base leading-relaxed text-hvm-text-sekundaer">
                Ein Leerstand ersetzt kein Mietverhältnis. Er dokumentiert die Zeit ohne Mieter, die Kosten bleiben beim
                Eigentümer.
            </p>
        </div>

        @if ($leerstaende->isEmpty())
            <x-hvm.empty-state class="mt-6" icon="calendar" title="Kein Leerstand">
                <p>Für diese Einheit ist kein Leerstand erfasst.</p>
            </x-hvm.empty-state>
        @else
            <x-hvm.card class="mt-6 divide-y divide-hvm-linie" padding="none">
                @foreach ($leerstaende as $leerstand)
                    <div class="flex flex-wrap items-center justify-between gap-3 p-5 sm:p-6">
                        <p class="text-base text-hvm-textschwarz">
                            {{ $leerstand->starts_on?->format('d.m.Y') }} bis {{ $leerstand->ends_on?->format('d.m.Y') }}@if ($leerstand->reason !== null), {{ $leerstand->reason }}@endif
                        </p>
                        <form method="POST" action="{{ route('portal.leerstand.destroy', ['vacancy' => $leerstand->getKey()]) }}">
                            @csrf
                            @method('DELETE')
                            <x-hvm.button type="submit" variant="danger" size="sm">
                                <x-hvm.icon name="trash" class="h-4 w-4" />
                                Entfernen
                            </x-hvm.button>
                        </form>
                    </div>
                @endforeach
            </x-hvm.card>
        @endif

        <x-hvm.card class="mt-6" title="Leerstand erfassen">
            <form method="POST" action="{{ route('portal.leerstand.store', ['unit' => $einheit->getKey()]) }}">
                @csrf
                <div class="grid gap-6 sm:grid-cols-3">
                    <x-hvm.field
                        name="starts_on"
                        id="leerstand-start"
                        errorKey="leerstand-start"
                        label="Von"
                        type="date"
                        :required="true"
                        :value="old('starts_on')" />
                    <x-hvm.field
                        name="ends_on"
                        id="leerstand-ende"
                        errorKey="leerstand-ende"
                        label="Bis"
                        type="date"
                        :required="true"
                        :value="old('ends_on')" />
                    <x-hvm.field
                        name="reason"
                        id="leerstand-grund"
                        errorKey="leerstand-grund"
                        label="Grund"
                        :optional="true"
                        :value="old('reason')" />
                </div>
                <div class="mt-6">
                    <x-hvm.button type="submit" variant="secondary">Leerstand speichern</x-hvm.button>
                </div>
            </form>
        </x-hvm.card>
    </section>
@endsection
