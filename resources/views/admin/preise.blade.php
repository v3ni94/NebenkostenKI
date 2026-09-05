{{--
    Preis je Mieterabrechnung und Umsatzsteuersatz.

    VERBINDLICH: Eine Aenderung wirkt ausschliesslich auf neue
    Berechnungsstaende. Bestehende Snapshots bleiben reproduzierbar.
--}}
@extends('layouts.admin')

@section('titel', 'Preise')

@section('content')
    <x-hvm.page-header
        eyebrow="Preise"
        title="Preis und Umsatzsteuer"
        lead="Eine Änderung an Preis, Regel oder Prompt wirkt ausschließlich auf neue Berechnungsstände. Bestehende Berechnungsstände bleiben unverändert und reproduzierbar." />

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-hvm.card title="Geltender Stand" eyebrow="Konfiguration" class="min-w-0">
            <dl class="divide-y divide-hvm-linie">
                <x-hvm.rollout-admin-kv label="Preis je Mieterabrechnung (brutto)">{{ \App\Application\Admin\PricingSettings::formatCent($zustand['preis_je_abrechnung_cent']) }}</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Grundpreis (brutto)">{{ \App\Application\Admin\PricingSettings::formatCent($zustand['grundpreis_cent']) }}</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Umsatzsteuersatz">{{ $zustand['steuersatz_prozent'] }} Prozent</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Zulässiger Korridor">
                    {{ \App\Application\Admin\PricingSettings::formatCent($zustand['korridor_min_cent']) }}
                    bis
                    {{ \App\Application\Admin\PricingSettings::formatCent($zustand['korridor_max_cent']) }}
                </x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Korrektur nach Zahlung">nicht verfügbar</x-hvm.rollout-admin-kv>
                <x-hvm.rollout-admin-kv label="Geschützte Berechnungsstände">{{ $geschuetzte_staende }}</x-hvm.rollout-admin-kv>
            </dl>

            <p class="mt-5 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                Eine Korrektur eines bezahlten Abrechnungslaufs ist zum Start nicht verfügbar. Korrekturen erfolgen
                über einen neuen Abrechnungslauf zum regulären Preis. Die Einstellung PRICE_CORRECTION_FREE_DAYS hat
                derzeit keine Wirkung.
            </p>

            @unless ($zustand['im_korridor'])
                <div class="mt-5">
                    <x-hvm.alert variant="warning" label="Achtung" title="Preis außerhalb des Korridors">
                        Der geltende Preis liegt außerhalb des zulässigen Korridors der Adminkonfiguration.
                    </x-hvm.alert>
                </div>
            @endunless
        </x-hvm.card>

        <x-hvm.card title="Geplanten Preis prüfen" eyebrow="Prüfung" :kennlinie="true" class="min-w-0 rounded-3xl">
            <p class="text-sm leading-relaxed text-hvm-text-sekundaer">{{ $zustand['persistenz'] }}</p>

            <form method="POST" action="{{ route('admin.preise.pruefen') }}" class="mt-6 space-y-6">
                @csrf
                <x-hvm.field
                    name="preis_brutto_cent"
                    label="Bruttopreis je Mieterabrechnung in Cent"
                    type="number"
                    :required="true"
                    :min="$zustand['korridor_min_cent']"
                    :max="$zustand['korridor_max_cent']"
                    :value="old('preis_brutto_cent', $zustand['preis_je_abrechnung_cent'])" />
                <x-hvm.button type="submit" variant="primary">Prüfen</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>
@endsection
