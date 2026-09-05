{{--
    Fortschrittsleiste des gefuehrten Ablaufs.

    Rendert die Schrittanzeige des Designsystems (x-hvm.stepper) ueber alle
    zwoelf Schritte. Die Statuskategorie steht immer als Text unter dem
    Schritt, Farbe ist nur zusaetzliche Information. Erreichbare Schritte sind
    verlinkt, die Schritte 1 bis 6 liegen in anderen Bausteinen, 11 und 12 in
    Zahlung und Abschluss.

    Zustand je Segment (nach Position und Kategorie):
      current  der angezeigte Schritt
      done     fachlich erledigt
      pending  liegt vor dem angezeigten Schritt, ist aber noch offen
      open     liegt dahinter und ist offen

    Der Wiedereinstiegshinweis erscheint nur, wenn der gespeicherte Stand vom
    angezeigten Schritt abweicht (WizardProgress::resumeHint), und zwar als
    Handlungsangebot mit Link auf den gespeicherten Schritt.

    Erwartet:
      $fortschritt     list<App\Application\Wizard\Dto\WizardStepView>
      $billingRun      App\Models\BillingRun|null
      $wiedereinstieg  string|null
--}}
@inject('wizardProgress', 'App\Application\Wizard\WizardProgress')

@php
    $fortschritt = $fortschritt ?? [];
    $billingRun = $billingRun ?? null;
    $wiedereinstieg = $wiedereinstieg ?? null;

    $aktuellePosition = 0;
    foreach ($fortschritt as $station) {
        if ($station->aktuell) {
            $aktuellePosition = $station->nummer();
        }
    }

    $schritte = [];

    foreach ($fortschritt as $station) {
        if ($station->aktuell) {
            $zustand = 'current';
        } elseif ($station->erledigt()) {
            $zustand = 'done';
        } elseif ($station->nummer() < $aktuellePosition) {
            $zustand = 'pending';
        } else {
            $zustand = 'open';
        }

        $schritt = [
            'label' => $station->label(),
            'state' => $zustand,
            'note' => $station->kategorie,
        ];

        if ($station->erreichbar && $billingRun !== null) {
            $schritt['href'] = route($station->step->routeName(), ['billingRun' => $billingRun->getKey()]);
        }

        $schritte[] = $schritt;
    }

    $wiedereinstiegUrl = null;
    if ($wiedereinstieg !== null && $billingRun !== null) {
        $gespeichert = $wizardProgress->currentStep($billingRun);
        $wiedereinstiegUrl = route($gespeichert->routeName(), ['billingRun' => $billingRun->getKey()]);
    }
@endphp

{{-- Zwoelf Schritte: die Komponente waehlt automatisch den Listenmodus (Schritte mit Kategorie unter den Segmenten). --}}
<x-hvm.stepper :steps="$schritte" label="Ihr Fortschritt">
    Jeder Schritt speichert sofort. Sie können jederzeit unterbrechen und später ohne Datenverlust fortfahren.
</x-hvm.stepper>

@if ($wiedereinstieg !== null)
    <x-hvm.alert variant="info" label="Hinweis" class="mt-4">
        <p>{{ $wiedereinstieg }}</p>
        @if ($wiedereinstiegUrl !== null)
            <div class="mt-3">
                <x-hvm.button href="{{ $wiedereinstiegUrl }}" variant="secondary" size="sm">
                    Dort fortfahren
                    <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                </x-hvm.button>
            </div>
        @endif
    </x-hvm.alert>
@endif
