{{--
    Schluessel-Wert-Zeile fuer Definitionslisten im Adminbereich.

    Wird innerhalb von <dl class="divide-y divide-hvm-linie"> verwendet:
    Bezeichnung links in Sekundaerfarbe, Wert rechts in Textschwarz mit
    Tabellenziffern. Lange Werte brechen um, Betraege bleiben ganz.

    Props:
      label  Bezeichnung (Pflicht)
      mono   true stellt den Wert in Festbreitenschrift dar (Kennungen, Nummern)
--}}
@props([
    'label',
    'mono' => false,
])

<div {{ $attributes->class('flex flex-col gap-1 py-2.5 first:pt-0 last:pb-0 sm:flex-row sm:items-baseline sm:justify-between sm:gap-6') }}>
    <dt class="text-sm text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $label }}</dt>
    <dd class="{{ $mono ? 'font-mono text-xs' : 'text-sm font-medium' }} min-w-0 text-hvm-textschwarz tabular sm:text-right [.hvm-dark_&]:text-white">{{ $slot }}</dd>
</div>
