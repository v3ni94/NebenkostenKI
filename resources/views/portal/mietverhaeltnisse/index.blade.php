@extends('layouts.portal')

@section('titel', 'Mietverhältnisse')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-hvm.section-heading
            eyebrow="{{ $objekt->label }}, Einheit {{ $einheit->label }}"
            title="Mietverhältnisse und Zeitachse"
            lead="Für jeden Tag des Abrechnungszeitraums muss entweder ein Mietverhältnis oder ein Leerstand erfasst sein." />
        <x-hvm.button href="{{ route('portal.mietverhaeltnisse.create', ['unit' => $einheit->getKey()]) }}" variant="primary">
            Mietverhältnis anlegen
        </x-hvm.button>
    </div>

    <x-hvm.card class="mt-6" title="Geprüfter Zeitraum">
        <p>
            {{ \Carbon\CarbonImmutable::parse($rahmen->startIso())->format('d.m.Y') }}
            bis
            {{ \Carbon\CarbonImmutable::parse($rahmen->endIso())->format('d.m.Y') }}
        </p>

        <div class="mt-4">
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
        </div>
    </x-hvm.card>

    {{-- Mietverhaeltnisse ------------------------------------------------------ --}}

    <h2 class="mt-10 text-xl font-bold text-hvm-anthrazit">Mietverhältnisse</h2>

    @if ($mietverhaeltnisse->isEmpty())
        <x-hvm.card class="mt-4">
            <p>Für diese Einheit ist noch kein Mietverhältnis erfasst.</p>
        </x-hvm.card>
    @else
        <div class="mt-4 space-y-4">
            @foreach ($mietverhaeltnisse as $mietverhaeltnis)
                <x-hvm.card>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold text-hvm-anthrazit">
                                {{ $mietverhaeltnis->tenant_display_name }}
                            </h3>
                            <p class="mt-1 text-sm text-hvm-textschwarz">
                                Einzug {{ $mietverhaeltnis->starts_on?->format('d.m.Y') }},
                                @if ($mietverhaeltnis->ends_on !== null)
                                    Auszug {{ $mietverhaeltnis->ends_on->format('d.m.Y') }}
                                @else
                                    laufend
                                @endif
                            </p>
                            <p class="mt-1 text-sm text-hvm-anthrazit">
                                {{ $mietverhaeltnis->kind->label() }}, Status {{ $mietverhaeltnis->status->label() }}
                            </p>

                            @if ($mietverhaeltnis->kind === \App\Enums\TenancyKind::GEWERBE)
                                <div class="mt-3">
                                    <x-hvm.alert variant="warning" label="Bitte prüfen">
                                        Gewerbliche Mietverhältnisse werden nicht automatisch finalisiert.
                                        Bitte prüfen Sie die Umlagevereinbarung und die umsatzsteuerliche Behandlung
                                        gesondert.
                                    </x-hvm.alert>
                                </div>
                            @endif

                            <div class="mt-4">
                                <p class="text-sm font-semibold text-hvm-textschwarz">Personenanzahl je Zeitraum</p>
                                @if ($mietverhaeltnis->occupancyPeriods->isEmpty())
                                    <p class="mt-1 text-sm text-hvm-anthrazit">Noch kein Zeitraum erfasst.</p>
                                @else
                                    <ul class="mt-1 space-y-1 text-sm">
                                        @foreach ($mietverhaeltnis->occupancyPeriods as $belegung)
                                            <li class="flex flex-wrap items-center gap-2">
                                                <span>
                                                    {{ $belegung->starts_on?->format('d.m.Y') }}
                                                    bis {{ $belegung->ends_on?->format('d.m.Y') }}:
                                                    {{ $belegung->person_count }}
                                                    {{ $belegung->person_count === 1 ? 'Person' : 'Personen' }}
                                                </span>
                                                <form method="POST"
                                                      action="{{ route('portal.belegung.destroy', ['occupancy' => $belegung->getKey()]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="underline underline-offset-2">Entfernen</button>
                                                </form>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <form method="POST"
                                      action="{{ route('portal.belegung.store', ['tenancy' => $mietverhaeltnis->getKey()]) }}"
                                      class="mt-3 flex flex-wrap items-end gap-3">
                                    @csrf
                                    <div>
                                        <label for="belegung-start-{{ $mietverhaeltnis->getKey() }}" class="block text-xs font-semibold">Von</label>
                                        <input id="belegung-start-{{ $mietverhaeltnis->getKey() }}" name="starts_on" type="date" required
                                               class="mt-1 min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                                    </div>
                                    <div>
                                        <label for="belegung-ende-{{ $mietverhaeltnis->getKey() }}" class="block text-xs font-semibold">Bis</label>
                                        <input id="belegung-ende-{{ $mietverhaeltnis->getKey() }}" name="ends_on" type="date" required
                                               class="mt-1 min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                                    </div>
                                    <div>
                                        <label for="belegung-anzahl-{{ $mietverhaeltnis->getKey() }}" class="block text-xs font-semibold">Personen</label>
                                        <input id="belegung-anzahl-{{ $mietverhaeltnis->getKey() }}" name="person_count" type="number"
                                               min="0" max="99" required
                                               class="mt-1 w-24 min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                                    </div>
                                    <x-hvm.button type="submit" variant="secondary" size="sm">Zeitraum hinzufügen</x-hvm.button>
                                </form>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <x-hvm.button href="{{ route('portal.mietverhaeltnisse.edit', ['tenancy' => $mietverhaeltnis->getKey()]) }}"
                                          variant="secondary" size="sm">Bearbeiten</x-hvm.button>
                            <form method="POST"
                                  action="{{ route('portal.mietverhaeltnisse.destroy', ['tenancy' => $mietverhaeltnis->getKey()]) }}">
                                @csrf
                                @method('DELETE')
                                <x-hvm.button type="submit" variant="ghost" size="sm">Entfernen</x-hvm.button>
                            </form>
                        </div>
                    </div>
                </x-hvm.card>
            @endforeach
        </div>
    @endif

    {{-- Leerstand -------------------------------------------------------------- --}}

    <h2 class="mt-10 text-xl font-bold text-hvm-anthrazit">Leerstand</h2>
    <p class="mt-2 text-sm text-hvm-textschwarz">
        Ein Leerstand ersetzt kein Mietverhältnis. Er dokumentiert die Zeit ohne Mieter, die Kosten bleiben beim
        Eigentümer.
    </p>

    @if ($leerstaende->isEmpty())
        <x-hvm.card class="mt-4">
            <p>Für diese Einheit ist kein Leerstand erfasst.</p>
        </x-hvm.card>
    @else
        <ul class="mt-4 space-y-2">
            @foreach ($leerstaende as $leerstand)
                <li class="flex flex-wrap items-center gap-3 rounded-md border border-hvm-hellgrau bg-white px-4 py-3 text-sm">
                    <span>
                        {{ $leerstand->starts_on?->format('d.m.Y') }} bis {{ $leerstand->ends_on?->format('d.m.Y') }}
                        @if ($leerstand->reason !== null), {{ $leerstand->reason }}@endif
                    </span>
                    <form method="POST" action="{{ route('portal.leerstand.destroy', ['vacancy' => $leerstand->getKey()]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="underline underline-offset-2">Entfernen</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    <x-hvm.card class="mt-4" title="Leerstand erfassen">
        <form method="POST" action="{{ route('portal.leerstand.store', ['unit' => $einheit->getKey()]) }}"
              class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="leerstand-start" class="block text-sm font-semibold">Von</label>
                <input id="leerstand-start" name="starts_on" type="date" required
                       value="{{ old('starts_on') }}"
                       class="mt-1 min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
            </div>
            <div>
                <label for="leerstand-ende" class="block text-sm font-semibold">Bis</label>
                <input id="leerstand-ende" name="ends_on" type="date" required
                       value="{{ old('ends_on') }}"
                       class="mt-1 min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
            </div>
            <div>
                <label for="leerstand-grund" class="block text-sm font-semibold">Grund</label>
                <input id="leerstand-grund" name="reason" type="text"
                       value="{{ old('reason') }}"
                       class="mt-1 min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
            </div>
            <x-hvm.button type="submit" variant="secondary">Leerstand speichern</x-hvm.button>
        </form>
    </x-hvm.card>

    <p class="mt-8">
        <a class="font-medium underline underline-offset-2"
           href="{{ route('portal.einheiten.index', ['property' => $objekt->getKey()]) }}">
            Zurück zu den Einheiten
        </a>
    </p>
@endsection
