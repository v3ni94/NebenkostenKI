{{--
    Fortschrittsleiste des gefuehrten Ablaufs.

    Rendert die Schrittanzeige des Designsystems (x-hvm.stepper). Die
    Statuskategorie steht immer als Text unter dem Schritt, Farbe ist nur
    zusaetzliche Information. Erreichbare Schritte sind verlinkt, die
    Schritte 1 bis 6 liegen in anderen Bausteinen.

    Erwartet:
      $fortschritt     list<App\Application\Wizard\Dto\WizardStepView>
      $billingRun      App\Models\BillingRun|null
      $wiedereinstieg  string|null
--}}
@props([
    'fortschritt' => [],
    'billingRun' => null,
    'wiedereinstieg' => null,
])

@php
    $schritte = [];

    foreach ($fortschritt as $station) {
        $schritt = [
            'label' => $station->label(),
            'state' => $station->aktuell ? 'current' : ($station->erledigt() ? 'done' : 'open'),
            'note' => $station->kategorie,
        ];

        if ($station->erreichbar && $billingRun !== null) {
            $schritt['href'] = route($station->step->routeName(), ['billingRun' => $billingRun->getKey()]);
        }

        $schritte[] = $schritt;
    }
@endphp

{{-- Kompakt: bei zehn Schritten wuerden die Beschriftungen unter den Segmenten in Silben brechen. --}}
<x-hvm.stepper :steps="$schritte" label="Ihr Fortschritt" :compact="true">
    @if ($wiedereinstieg !== null)
        <p class="text-hvm-textschwarz">{{ $wiedereinstieg }}</p>
    @endif

    <p class="{{ $wiedereinstieg !== null ? 'mt-1' : '' }}">
        Jeder Schritt speichert sofort. Sie können jederzeit unterbrechen und später ohne Datenverlust fortfahren.
    </p>
</x-hvm.stepper>
