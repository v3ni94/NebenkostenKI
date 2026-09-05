@extends('layouts.portal')

@section('titel', 'Objekte')

@section('content')
    <x-hvm.page-header
        eyebrow="Bestand"
        title="Objekte"
        lead="Ein Objekt ist die Liegenschaft, für die abgerechnet wird. Zu jedem Objekt gehören eine oder mehrere Einheiten.">
        <x-slot:actions>
            <x-hvm.button href="{{ route('portal.objekte.create') }}" variant="primary">
                <x-hvm.icon name="plus" class="h-4 w-4" />
                Objekt anlegen
            </x-hvm.button>
        </x-slot:actions>
    </x-hvm.page-header>

    @if ($objekte === [])
        <x-hvm.empty-state class="mt-10" icon="house" title="Noch kein Objekt">
            <p>Sie haben noch kein Objekt angelegt.</p>
            <x-slot:action>
                <x-hvm.button href="{{ route('portal.objekte.create') }}" variant="primary">Objekt anlegen</x-hvm.button>
            </x-slot:action>
        </x-hvm.empty-state>
    @else
        {{--
            Je Objekt eine Karte mit gestapelter Listenzeile: Titelzeile mit
            Handlungen, darunter Details und Status nebeneinander ueber die volle
            Kartenbreite (keine leere Kartenhaelfte auf Desktop).
        --}}
        <div class="mt-10 space-y-4">
            @foreach ($objekte as $objekt)
                <x-hvm.card padding="none">
                    <x-hvm.list-row
                        :stacked="true"
                        :title="$objekt->label"
                        :subtitle="$objekt->address_line.', '.$objekt->postal_code.' '.$objekt->city">
                        <div class="grid gap-5 border-t border-hvm-linie pt-5 lg:grid-cols-12 lg:gap-8">
                            <div class="space-y-3 text-sm lg:col-span-4">
                                <p class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Objektdaten</p>
                                <p class="text-hvm-textschwarz">
                                    {{ $objekt->kind->label() }}, {{ $objekt->units_count }}
                                    {{ $objekt->units_count === 1 ? 'Einheit' : 'Einheiten' }}
                                </p>
                                <p class="text-hvm-textschwarz">
                                    @if ($objekt->landlord !== null)
                                        Vermieter: {{ $objekt->landlord->company_name !== null ? $objekt->landlord->company_name.', ' : '' }}{{ $objekt->landlord->sender_name }}
                                    @else
                                        Vermieter: noch nicht erfasst. Ohne Vermieter kann die Abrechnung nicht erzeugt werden.
                                    @endif
                                </p>
                            </div>

                            <div class="lg:col-span-8">
                                @include('portal.partials.status', ['status' => $hinweise[$objekt->getKey()]])
                            </div>
                        </div>

                        <x-slot:actions>
                            <x-hvm.button href="{{ route('portal.einheiten.index', ['property' => $objekt->getKey()]) }}"
                                          variant="secondary" size="sm">Einheiten</x-hvm.button>
                            <x-hvm.button href="{{ route('portal.objekte.vermieter.edit', ['property' => $objekt->getKey()]) }}"
                                          variant="secondary" size="sm">Vermieter</x-hvm.button>
                            <x-hvm.button href="{{ route('portal.objekte.edit', ['property' => $objekt->getKey()]) }}"
                                          variant="secondary" size="sm">Bearbeiten</x-hvm.button>
                            <form method="POST" action="{{ route('portal.objekte.destroy', ['property' => $objekt->getKey()]) }}">
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
@endsection
