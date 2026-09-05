{{--
    Fortschrittsbalken des HVM-Designsystems.

    Natives <progress>-Element, gestaltet ueber die Klasse .hvm-progress in
    app.css (Orange als Fortschrittsfarbe, Canvas deep als Bahn). Dadurch ist
    kein Inline-Style fuer die Breite noetig, und der Wert steht fuer
    Hilfstechnik im Element selbst. Der Prozentwert wird zusaetzlich als Text
    genannt, sofern kein eigener Text uebergeben wird.

    Props:
      value  Fortschritt (Pflicht), Zahl zwischen 0 und max
      max    Endwert, Standard 100
      label  Beschriftung fuer Hilfstechnik (aria-label), Standard "Fortschritt"
      text   sichtbarer Text ueber dem Balken (optional); false blendet die
             Standardangabe "N Prozent" aus
      size   sm (6 px, Standard) oder md (10 px)
--}}
@props([
    'value',
    'max' => 100,
    'label' => 'Fortschritt',
    'text' => null,
    'size' => 'sm',
])

@php
    $wert = max(0, min((float) $max, (float) $value));
    $prozent = $max > 0 ? (int) round($wert / $max * 100) : 0;
    $anzeige = $text === false ? null : ($text ?? $prozent.' Prozent');
@endphp

<div {{ $attributes->class('min-w-0') }}>
    @if ($anzeige !== null)
        <p class="mb-2 text-sm text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $anzeige }}</p>
    @endif
    <progress class="hvm-progress {{ $size === 'md' ? 'hvm-progress-md' : '' }}" value="{{ $wert }}" max="{{ $max }}" aria-label="{{ $label }}">{{ $prozent }} Prozent</progress>
</div>
