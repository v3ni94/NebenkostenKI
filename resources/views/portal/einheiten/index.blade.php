@extends('layouts.portal')

@section('titel', 'Einheiten')

@section('content')
    <x-hvm.page-header
        eyebrow="{{ $objekt->label }}"
        title="Einheiten"
        lead="Erfassen Sie je Einheit Fläche, beheizte Fläche, Miteigentumsanteil und Ihre individuellen Schlüsselwerte."
        :back="route('portal.objekte.index')"
        backLabel="Zurück zur Objektliste">
        <x-slot:actions>
            <x-hvm.button href="{{ route('portal.einheiten.create', ['property' => $objekt->getKey()]) }}" variant="primary">
                <x-hvm.icon name="plus" class="h-4 w-4" />
                Einheit anlegen
            </x-hvm.button>
        </x-slot:actions>
    </x-hvm.page-header>

    @if ($plausibilitaet !== [])
        <x-hvm.alert class="mt-8" variant="warning" label="Bitte prüfen" title="Hinweis zur Plausibilität">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($plausibilitaet as $hinweis)
                    <li>{{ $hinweis }}</li>
                @endforeach
            </ul>
            <p class="mt-2">
                Abweichungen sind nicht zwingend ein Fehler. Gemeinschaftsflächen und Teileigentum führen
                regelmäßig zu abweichenden Summen. Bitte prüfen Sie die Werte.
            </p>
        </x-hvm.alert>
    @endif

    @if ($einheiten === [])
        <x-hvm.empty-state class="mt-10" icon="key" title="Noch keine Einheit">
            <p>Für dieses Objekt ist noch keine Einheit erfasst.</p>
            <x-slot:action>
                <x-hvm.button href="{{ route('portal.einheiten.create', ['property' => $objekt->getKey()]) }}" variant="secondary">
                    Einheit anlegen
                </x-hvm.button>
            </x-slot:action>
        </x-hvm.empty-state>
    @else
        {{--
            Desktop als Tabelle, unter 640 px gestapelt: jede Einheit wird ein
            Block, die Bezeichnung die Ueberschrift, jede Zelle zeigt ihr data-label.
        --}}
        <div class="mt-10 overflow-hidden rounded-3xl border border-hvm-linie bg-white">
            <table class="hvm-table hvm-table-zebra hvm-table-stack text-base">
                <caption class="sr-only">Einheiten des Objekts {{ $objekt->label }}</caption>
                <thead>
                    <tr>
                        <th scope="col">Bezeichnung</th>
                        <th scope="col">Lage</th>
                        <th scope="col" class="betrag">Wohnfläche</th>
                        <th scope="col" class="betrag">Beheizt</th>
                        <th scope="col" class="betrag">Anteil</th>
                        <th scope="col"><span class="sr-only">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($einheiten as $einheit)
                        <tr>
                            <th scope="row" class="font-medium">{{ $einheit->label }}</th>
                            <td class="text-hvm-text-sekundaer" data-label="Lage">{{ $einheit->location ?? 'Ohne Angabe' }}</td>
                            <td class="betrag" data-label="Wohnfläche">
                                {{ $einheit->living_area_sqm !== null ? number_format((float) $einheit->living_area_sqm, 2, ',', '.').' m²' : 'Fehlt noch' }}
                            </td>
                            <td class="betrag" data-label="Beheizt">
                                {{ $einheit->heated_area_sqm !== null ? number_format((float) $einheit->heated_area_sqm, 2, ',', '.').' m²' : 'Ohne Angabe' }}
                            </td>
                            <td class="betrag" data-label="Anteil">
                                {{ $einheit->mea !== null ? number_format((float) $einheit->mea, 2, ',', '.') : 'Ohne Angabe' }}
                            </td>
                            <td data-label="Aktionen">
                                <div class="flex flex-wrap gap-2 sm:justify-end">
                                    <x-hvm.button href="{{ route('portal.mietverhaeltnisse.index', ['unit' => $einheit->getKey()]) }}"
                                                  variant="secondary" size="sm">Mietverhältnisse</x-hvm.button>
                                    <x-hvm.button href="{{ route('portal.einheiten.edit', ['unit' => $einheit->getKey()]) }}"
                                                  variant="secondary" size="sm">Bearbeiten</x-hvm.button>
                                    <form method="POST" action="{{ route('portal.einheiten.destroy', ['unit' => $einheit->getKey()]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-hvm.button type="submit" variant="danger" size="sm">
                                            <x-hvm.icon name="trash" class="h-4 w-4" />
                                            Entfernen
                                        </x-hvm.button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
