{{--
    Statushinweis des HVM-Designsystems (Konzept A).

    Verbindliche Regel: Ein Status wird niemals allein ueber die Farbe
    kommuniziert. Jede Variante traegt zusaetzlich ein Symbol und ein
    ausgeschriebenes Statuswort.

    Varianten: info, success, warning, error
--}}
@props([
    'variant' => 'info',
    'label' => null,
    'title' => null,
])

@php
    $variante = in_array($variant, ['info', 'success', 'warning', 'error'], true) ? $variant : 'info';

    $statuswort = $label ?? match ($variante) {
        'success' => 'Erledigt',
        'warning' => 'Achtung',
        'error' => 'Fehler',
        default => 'Hinweis',
    };

    $flaeche = match ($variante) {
        'success' => 'border-status-success/25 bg-status-success-soft',
        'warning' => 'border-status-warning/25 bg-status-warning-soft',
        'error' => 'border-status-error/25 bg-status-error-soft',
        default => 'border-status-info/25 bg-status-info-soft',
    };

    $symbolfarbe = match ($variante) {
        'success' => 'text-status-success',
        'warning' => 'text-status-warning',
        'error' => 'text-status-error',
        default => 'text-status-info',
    };

    $symbol = match ($variante) {
        'success' => 'check-circle',
        'warning' => 'warning',
        'error' => 'x-circle',
        default => 'info',
    };
@endphp

<div {{ $attributes->class(['rounded-2xl border p-5', $flaeche]) }}>
    <div class="flex items-start gap-3">
        <span class="{{ $symbolfarbe }} mt-0.5 shrink-0" aria-hidden="true">
            <x-hvm.icon :name="$symbol" class="h-5 w-5" />
        </span>

        <div class="min-w-0">
            <p class="text-sm font-semibold {{ $symbolfarbe }}">
                {{ $statuswort }}@if ($title !== null): {{ $title }}@endif
            </p>
            <div class="mt-1 text-sm leading-relaxed text-hvm-textschwarz">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
