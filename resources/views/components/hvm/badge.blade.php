{{--
    Kurzes Etikett des HVM-Designsystems.

    Varianten: neutral, akzent, info, success, warning, error

    Die Bedeutung steht immer im Text. Die Statusvarianten tragen zusaetzlich
    ein Symbol (Uebernahme aus Konzept B), damit der Zustand auch ohne
    Farbwahrnehmung erkennbar ist. Zuordnung (verbindlich):
      success  check-circle  (Erledigt)
      warning  eye           (Bitte pruefen)
      info     inbox         (Fehlt noch)
      error    alert         (Blockiert die Abrechnung)

    Optional:
      icon   Name eines x-hvm.icon, ueberschreibt das Standardsymbol;
             false unterdrueckt das Symbol
      dot    veraltet, entspricht icon=false wenn false (Kompatibilitaet)

    Auf .hvm-dark-Flaechen wechseln neutral und akzent automatisch auf
    transparente Flaeche mit hellem Text.
--}}
@props([
    'variant' => 'neutral',
    'icon' => null,
    'dot' => null,
])

@php
    $farben = match ($variant) {
        'akzent' => 'border-hvm-orange/60 bg-hvm-orange-soft text-hvm-textschwarz [.hvm-dark_&]:border-hvm-orange/70 [.hvm-dark_&]:bg-hvm-orange/15 [.hvm-dark_&]:text-white',
        'info' => 'border-status-info/25 bg-status-info-soft text-status-info',
        'success' => 'border-status-success/25 bg-status-success-soft text-status-success',
        'warning' => 'border-status-warning/25 bg-status-warning-soft text-status-warning',
        'error' => 'border-status-error/25 bg-status-error-soft text-status-error',
        default => 'border-hvm-linie bg-hvm-canvas-deep text-hvm-textschwarz [.hvm-dark_&]:border-white/30 [.hvm-dark_&]:bg-transparent [.hvm-dark_&]:text-white',
    };

    $standardSymbol = match ($variant) {
        'success' => 'check-circle',
        'warning' => 'eye',
        'info' => 'inbox',
        'error' => 'alert',
        default => null,
    };

    $symbol = $icon === false || $dot === false ? null : ($icon ?? $standardSymbol);
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold leading-5', $farben]) }}>
    @if ($symbol !== null)
        <x-hvm.icon :name="$symbol" class="h-3.5 w-3.5" />
    @endif
    {{ $slot }}
</span>
