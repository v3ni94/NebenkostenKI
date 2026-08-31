{{--
    Kurzes Etikett des HVM-Designsystems.

    Varianten: neutral, akzent, info, success, warning, error

    Auch beim Etikett steht die Bedeutung immer im Text. Die Farbe ist nur
    zusaetzliche Information.
--}}
@props([
    'variant' => 'neutral',
])

@php
    $farben = match ($variant) {
        'akzent' => 'border-hvm-orange-dark bg-hvm-orange-soft text-hvm-textschwarz',
        'info' => 'border-status-info bg-status-info-soft text-status-info',
        'success' => 'border-status-success bg-status-success-soft text-status-success',
        'warning' => 'border-status-warning bg-status-warning-soft text-status-warning',
        'error' => 'border-status-error bg-status-error-soft text-status-error',
        default => 'border-hvm-mittelgrau bg-hvm-umrissgrau text-hvm-textschwarz',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold', $farben]) }}>
    {{ $slot }}
</span>
