{{--
    Statushinweis des HVM-Designsystems.

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
        'success' => 'border-status-success bg-status-success-soft',
        'warning' => 'border-status-warning bg-status-warning-soft',
        'error' => 'border-status-error bg-status-error-soft',
        default => 'border-status-info bg-status-info-soft',
    };

    $symbolfarbe = match ($variante) {
        'success' => 'text-status-success',
        'warning' => 'text-status-warning',
        'error' => 'text-status-error',
        default => 'text-status-info',
    };
@endphp

<div {{ $attributes->class(['rounded-lg border-l-4 p-4', $flaeche]) }}>
    <div class="flex items-start gap-3">
        <span class="{{ $symbolfarbe }} mt-0.5 shrink-0" aria-hidden="true">
            @switch($variante)
                @case('success')
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 1.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17Zm4.03 6.28-4.9 4.9a.9.9 0 0 1-1.27 0L5.97 10.8a.9.9 0 0 1 1.27-1.27l1.26 1.25 4.26-4.27a.9.9 0 0 1 1.27 1.27Z" />
                    </svg>
                    @break

                @case('warning')
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10.87 2.6a1 1 0 0 0-1.74 0L1.2 16.4A1 1 0 0 0 2.07 18h15.86a1 1 0 0 0 .87-1.5L10.87 2.6ZM9.1 7h1.8l-.2 5h-1.4l-.2-5Zm.9 8.6a1.05 1.05 0 1 1 0-2.1 1.05 1.05 0 0 1 0 2.1Z" />
                    </svg>
                    @break

                @case('error')
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 1.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17Zm3.16 10.39a.9.9 0 0 1-1.27 1.27L10 11.27l-1.89 1.89a.9.9 0 0 1-1.27-1.27L8.73 10 6.84 8.11A.9.9 0 0 1 8.11 6.84L10 8.73l1.89-1.89a.9.9 0 0 1 1.27 1.27L11.27 10l1.89 1.89Z" />
                    </svg>
                    @break

                @default
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 1.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17ZM9.1 5.6h1.8v1.8H9.1V5.6Zm0 3.2h1.8v5.6H9.1V8.8Z" />
                    </svg>
            @endswitch
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
