{{--
    Kurzes Etikett des HVM-Designsystems (Konzept A).

    Varianten: neutral, akzent, info, success, warning, error

    Die Bedeutung steht immer im Text. Die Statusvarianten tragen zusaetzlich
    einen Statuspunkt, damit der Zustand auch ohne Farbwahrnehmung als
    "Status" erkennbar ist. Die Farbe ist nur zusaetzliche Information.

    Optional:
      dot   false unterdrueckt den Statuspunkt
--}}
@props([
    'variant' => 'neutral',
    'dot' => null,
])

@php
    $farben = match ($variant) {
        'akzent' => 'border-hvm-orange/60 bg-hvm-orange-soft text-hvm-textschwarz',
        'info' => 'border-status-info/25 bg-status-info-soft text-status-info',
        'success' => 'border-status-success/25 bg-status-success-soft text-status-success',
        'warning' => 'border-status-warning/25 bg-status-warning-soft text-status-warning',
        'error' => 'border-status-error/25 bg-status-error-soft text-status-error',
        default => 'border-hvm-linie bg-hvm-canvas-deep text-hvm-textschwarz',
    };

    $punkt = match ($variant) {
        'info' => 'bg-status-info',
        'success' => 'bg-status-success',
        'warning' => 'bg-status-warning',
        'error' => 'bg-status-error',
        'akzent' => 'bg-hvm-orange-dark',
        default => null,
    };

    $zeigePunkt = $dot ?? ($punkt !== null);
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold leading-5', $farben]) }}>
    @if ($zeigePunkt && $punkt !== null)
        <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $punkt }}" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
