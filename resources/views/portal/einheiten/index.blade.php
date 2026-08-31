@extends('layouts.portal')

@section('titel', 'Einheiten')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-hvm.section-heading
            eyebrow="{{ $objekt->label }}"
            title="Einheiten"
            lead="Erfassen Sie je Einheit Fläche, beheizte Fläche, Miteigentumsanteil und Ihre individuellen Schlüsselwerte." />
        <x-hvm.button href="{{ route('portal.einheiten.create', ['property' => $objekt->getKey()]) }}" variant="primary">
            Einheit anlegen
        </x-hvm.button>
    </div>

    @if ($plausibilitaet !== [])
        <div class="mt-6">
            <x-hvm.alert variant="warning" label="Bitte prüfen" title="Hinweis zur Plausibilität">
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
        </div>
    @endif

    @if ($einheiten === [])
        <x-hvm.card class="mt-8">
            <p>Für dieses Objekt ist noch keine Einheit erfasst.</p>
        </x-hvm.card>
    @else
        <div class="mt-8 overflow-x-auto">
            <table class="w-full min-w-[48rem] border-collapse text-sm">
                <caption class="sr-only">Einheiten des Objekts {{ $objekt->label }}</caption>
                <thead>
                    <tr class="bg-hvm-orange-soft text-left">
                        <th scope="col" class="border-b border-hvm-mittelgrau px-3 py-2">Bezeichnung</th>
                        <th scope="col" class="border-b border-hvm-mittelgrau px-3 py-2">Lage</th>
                        <th scope="col" class="border-b border-hvm-mittelgrau px-3 py-2">Wohnfläche</th>
                        <th scope="col" class="border-b border-hvm-mittelgrau px-3 py-2">Beheizt</th>
                        <th scope="col" class="border-b border-hvm-mittelgrau px-3 py-2">Anteil</th>
                        <th scope="col" class="border-b border-hvm-mittelgrau px-3 py-2">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($einheiten as $einheit)
                        <tr class="odd:bg-white even:bg-hvm-umrissgrau">
                            <td class="border-b border-hvm-hellgrau px-3 py-2 font-semibold">{{ $einheit->label }}</td>
                            <td class="border-b border-hvm-hellgrau px-3 py-2">{{ $einheit->location ?? 'Ohne Angabe' }}</td>
                            <td class="border-b border-hvm-hellgrau px-3 py-2">
                                {{ $einheit->living_area_sqm !== null ? number_format((float) $einheit->living_area_sqm, 2, ',', '.').' m²' : 'Fehlt noch' }}
                            </td>
                            <td class="border-b border-hvm-hellgrau px-3 py-2">
                                {{ $einheit->heated_area_sqm !== null ? number_format((float) $einheit->heated_area_sqm, 2, ',', '.').' m²' : 'Ohne Angabe' }}
                            </td>
                            <td class="border-b border-hvm-hellgrau px-3 py-2">
                                {{ $einheit->mea !== null ? number_format((float) $einheit->mea, 2, ',', '.') : 'Ohne Angabe' }}
                            </td>
                            <td class="border-b border-hvm-hellgrau px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <a class="underline underline-offset-2"
                                       href="{{ route('portal.mietverhaeltnisse.index', ['unit' => $einheit->getKey()]) }}">
                                        Mietverhältnisse
                                    </a>
                                    <a class="underline underline-offset-2"
                                       href="{{ route('portal.einheiten.edit', ['unit' => $einheit->getKey()]) }}">
                                        Bearbeiten
                                    </a>
                                    <form method="POST" action="{{ route('portal.einheiten.destroy', ['unit' => $einheit->getKey()]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="underline underline-offset-2">Entfernen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="mt-8">
        <a class="font-medium underline underline-offset-2" href="{{ route('portal.objekte.index') }}">
            Zurück zur Objektliste
        </a>
    </p>
@endsection
