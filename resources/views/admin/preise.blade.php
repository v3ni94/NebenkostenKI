{{--
    Preis je Mieterabrechnung und Umsatzsteuersatz.

    VERBINDLICH: Eine Aenderung wirkt ausschliesslich auf neue
    Berechnungsstaende. Bestehende Snapshots bleiben reproduzierbar.
--}}
@extends('layouts.admin')

@section('titel', 'Preise')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Preis und Umsatzsteuer"
        lead="Eine Änderung an Preis, Regel oder Prompt wirkt ausschließlich auf neue Berechnungsstände. Bestehende Berechnungsstände bleiben unverändert und reproduzierbar." />

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Geltender Stand">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>Preis je Mieterabrechnung (brutto)</dt>
                    <dd>{{ \App\Application\Admin\PricingSettings::formatCent($zustand['preis_je_abrechnung_cent']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Grundpreis (brutto)</dt>
                    <dd>{{ \App\Application\Admin\PricingSettings::formatCent($zustand['grundpreis_cent']) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Umsatzsteuersatz</dt>
                    <dd>{{ $zustand['steuersatz_prozent'] }} Prozent</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Zulässiger Korridor</dt>
                    <dd>
                        {{ \App\Application\Admin\PricingSettings::formatCent($zustand['korridor_min_cent']) }}
                        bis
                        {{ \App\Application\Admin\PricingSettings::formatCent($zustand['korridor_max_cent']) }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt>Korrektur nach Zahlung</dt>
                    <dd>nicht verfügbar</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Geschützte Berechnungsstände</dt>
                    <dd>{{ $geschuetzte_staende }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-sm text-hvm-anthrazit">
                Eine Korrektur eines bezahlten Abrechnungslaufs ist zum Start nicht verfügbar. Korrekturen erfolgen
                über einen neuen Abrechnungslauf zum regulären Preis. Die Einstellung PRICE_CORRECTION_FREE_DAYS hat
                derzeit keine Wirkung.
            </p>

            @unless ($zustand['im_korridor'])
                <div class="mt-4">
                    <x-hvm.alert variant="warning" label="Achtung" title="Preis außerhalb des Korridors">
                        Der geltende Preis liegt außerhalb des zulässigen Korridors der Adminkonfiguration.
                    </x-hvm.alert>
                </div>
            @endunless
        </x-hvm.card>

        <x-hvm.card title="Geplanten Preis prüfen">
            <p class="text-sm text-hvm-anthrazit">{{ $zustand['persistenz'] }}</p>

            <form method="POST" action="{{ route('admin.preise.pruefen') }}" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label for="preis_brutto_cent" class="block text-sm font-semibold">
                        Bruttopreis je Mieterabrechnung in Cent
                    </label>
                    <input type="number" id="preis_brutto_cent" name="preis_brutto_cent" required
                           min="{{ $zustand['korridor_min_cent'] }}" max="{{ $zustand['korridor_max_cent'] }}"
                           value="{{ old('preis_brutto_cent', $zustand['preis_je_abrechnung_cent']) }}"
                           class="mt-2 w-full rounded border border-hvm-mittelgrau px-3 py-2">
                </div>
                <x-hvm.button type="submit" variant="secondary">Prüfen</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>
@endsection
