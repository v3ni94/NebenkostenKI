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
        <div class="mt-10 space-y-4">
            @foreach ($objekte as $objekt)
                <x-hvm.card padding="none">
                    <x-hvm.list-row
                        :title="$objekt->label"
                        :subtitle="$objekt->address_line.', '.$objekt->postal_code.' '.$objekt->city">
                        <div class="space-y-1 text-sm text-hvm-text-sekundaer">
                            <p>
                                {{ $objekt->kind->label() }}, {{ $objekt->units_count }}
                                {{ $objekt->units_count === 1 ? 'Einheit' : 'Einheiten' }}
                            </p>
                            <p>
                                @if ($objekt->landlord !== null)
                                    Vermieter: {{ $objekt->landlord->company_name !== null ? $objekt->landlord->company_name.', ' : '' }}{{ $objekt->landlord->sender_name }}
                                @else
                                    Vermieter: noch nicht erfasst. Ohne Vermieter kann die Abrechnung nicht erzeugt werden.
                                @endif
                            </p>
                        </div>

                        <div class="mt-4 border-t border-hvm-linie pt-4">
                            @include('portal.partials.status', ['status' => $hinweise[$objekt->getKey()]])
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
                                <x-hvm.button type="submit" variant="ghost" size="sm">Entfernen</x-hvm.button>
                            </form>
                        </x-slot:actions>
                    </x-hvm.list-row>
                </x-hvm.card>
            @endforeach
        </div>
    @endif
@endsection
