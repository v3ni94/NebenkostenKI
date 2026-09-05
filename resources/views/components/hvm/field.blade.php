{{--
    Formularfeld des HVM-Designsystems (Konzept A).

    Buendelt Label, Eingabefeld, Hilfetext und Fehleranzeige, damit alle
    Formulare der Anwendung gleich aufgebaut sind. Das Eingabefeld ist gross
    (min-h-12, Klasse .hvm-input), der Fokusring orange.

    Props:
      name          Feldname (Pflicht). Wird auch fuer @error und old() genutzt.
      id            Element-ID, Standard = name
      label         Beschriftung (Pflicht)
      type          text, email, password, number, date, tel, url, textarea,
                    select (bei select und textarea liefert der Slot den Inhalt)
      value         Vorbelegung, Standard old(name)
      hint          Hilfetext unter dem Label
      required      true setzt required und markiert das Label
      autocomplete  Wert des autocomplete-Attributs
      optional      true zeigt "optional" statt Pflichtmarkierung
      inputAttributes  zusaetzliche Attribute nur fuer das Eingabefeld

    Alle uebrigen Attribute (placeholder, min, max, step, autofocus,
    inputmode, pattern, ...) werden an das Eingabefeld durchgereicht.

    Fehlerstruktur: <p id="{id}-fehler"> mit aria-describedby und
    aria-invalid="true" am Feld, identisch zum bisherigen Muster.
--}}
@props([
    'name',
    'label',
    'id' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'required' => false,
    'autocomplete' => null,
    'optional' => false,
])

@php
    $feldId = $id ?? $name;
    $fehlerId = $feldId.'-fehler';
    $hinweisId = $feldId.'-hinweis';
    $wert = $value ?? old($name);
    $hatFehler = $errors->has($name);

    $beschreibung = trim(($hint !== null ? $hinweisId : '').' '.($hatFehler ? $fehlerId : ''));

    $feldAttribute = $attributes->merge([
        'id' => $feldId,
        'name' => $name,
        'class' => 'hvm-input',
    ]);

    if ($required) {
        $feldAttribute = $feldAttribute->merge(['required' => true]);
    }
    if ($autocomplete !== null) {
        $feldAttribute = $feldAttribute->merge(['autocomplete' => $autocomplete]);
    }
    if ($beschreibung !== '') {
        $feldAttribute = $feldAttribute->merge(['aria-describedby' => $beschreibung]);
    }
    if ($hatFehler) {
        $feldAttribute = $feldAttribute->merge(['aria-invalid' => 'true']);
    }
@endphp

<div class="min-w-0">
    <div class="flex items-baseline justify-between gap-3">
        <label for="{{ $feldId }}" class="block text-sm font-semibold text-hvm-textschwarz">
            {{ $label }}
        </label>
        @if ($optional && ! $required)
            <span class="text-xs text-hvm-text-sekundaer">optional</span>
        @endif
    </div>

    @if ($hint !== null)
        <p id="{{ $hinweisId }}" class="mt-1 text-sm leading-relaxed text-hvm-text-sekundaer">{{ $hint }}</p>
    @endif

    <div class="mt-2">
        @if ($type === 'textarea')
            <textarea {{ $feldAttribute }}>{{ $wert }}</textarea>
        @elseif ($type === 'select')
            <select {{ $feldAttribute }}>{{ $slot }}</select>
        @else
            <input type="{{ $type }}" value="{{ $wert }}" {{ $feldAttribute }}>
        @endif
    </div>

    @error($name)
        <p id="{{ $fehlerId }}" class="mt-2 flex items-start gap-1.5 text-sm font-medium text-status-error">
            <x-hvm.icon name="warning" class="mt-0.5 h-4 w-4" />
            <span>{{ $message }}</span>
        </p>
    @enderror
</div>
