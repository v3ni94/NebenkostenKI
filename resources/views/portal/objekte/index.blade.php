@extends('layouts.portal')

@section('titel', 'Objekte')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-hvm.section-heading
            title="Objekte"
            lead="Ein Objekt ist die Liegenschaft, für die abgerechnet wird. Zu jedem Objekt gehören eine oder mehrere Einheiten." />
        <x-hvm.button href="{{ route('portal.objekte.create') }}" variant="primary">Objekt anlegen</x-hvm.button>
    </div>

    @if ($objekte === [])
        <x-hvm.card class="mt-8">
            <p>Sie haben noch kein Objekt angelegt.</p>
        </x-hvm.card>
    @else
        <div class="mt-8 space-y-4">
            @foreach ($objekte as $objekt)
                <x-hvm.card>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold text-hvm-anthrazit">{{ $objekt->label }}</h3>
                            <p class="mt-1 text-sm text-hvm-textschwarz">
                                {{ $objekt->address_line }}, {{ $objekt->postal_code }} {{ $objekt->city }}
                            </p>
                            <p class="mt-1 text-sm text-hvm-anthrazit">
                                {{ $objekt->kind->label() }}, {{ $objekt->units_count }}
                                {{ $objekt->units_count === 1 ? 'Einheit' : 'Einheiten' }}
                            </p>
                            <p class="mt-1 text-sm text-hvm-anthrazit">
                                @if ($objekt->landlord !== null)
                                    Vermieter: {{ $objekt->landlord->company_name !== null ? $objekt->landlord->company_name.', ' : '' }}{{ $objekt->landlord->sender_name }}
                                @else
                                    Vermieter: noch nicht erfasst. Ohne Vermieter kann die Abrechnung nicht erzeugt werden.
                                @endif
                            </p>
                            <div class="mt-3">
                                @include('portal.partials.status', ['status' => $hinweise[$objekt->getKey()]])
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
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
                        </div>
                    </div>
                </x-hvm.card>
            @endforeach
        </div>
    @endif
@endsection
