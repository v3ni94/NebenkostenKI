{{--
    Formularfeld des HVM-Designsystems.

    Buendelt Label, Eingabefeld, Hilfetext und Fehleranzeige, damit alle
    Formulare der Anwendung gleich aufgebaut sind. Das Eingabefeld ist gross
    (min-h-12, Klasse .hvm-input), der Fokusring orange. Auswahlfelder haben
    eine Trefferflaeche von mindestens 44 px (Klasse .hvm-choice).

    Props:
      name          Feldname (Pflicht). Wird auch fuer @error und old() genutzt.
                    Bei checkbox-group ohne "[]" angeben, die Komponente
                    ergaenzt es.
      id            Element-ID, Standard = name
      label         Beschriftung (Pflicht). Bei radio-group und checkbox-group
                    die Legende der Gruppe.
      type          text, email, password, number, date, tel, url, textarea,
                    select, checkbox, radio-group, checkbox-group
                    (bei select und textarea liefert der Slot den Inhalt)
      value         Vorbelegung, Standard old(name). Bei checkbox der
                    Formularwert (Standard "1"), bei Gruppen der gewaehlte
                    Wert bzw. die Liste der gewaehlten Werte.
      checked       Nur checkbox: true setzt das Feld an (Standard: old(name))
      options       Nur radio-group und checkbox-group: Array Wert => Text,
                    oder Wert => ['label' => ..., 'hint' => ...]
      inline        Nur Gruppen: true stellt die Optionen nebeneinander
      hint          Hilfetext unter dem Label bzw. der Legende
      required      true setzt required und markiert das Label
      autocomplete  Wert des autocomplete-Attributs
      optional      true zeigt "optional" statt Pflichtmarkierung
      errorKey      Schluessel im Fehlerbeutel, Standard name

    Alle uebrigen Attribute (placeholder, min, max, step, autofocus,
    inputmode, pattern, ...) werden an das Eingabefeld durchgereicht.

    Fehlerstruktur: <p id="{id}-fehler"> mit aria-describedby und
    aria-invalid="true" am Feld (bei Gruppen am fieldset), identisch zum
    bisherigen Muster. Innerhalb von .hvm-dark wechseln Label und Hilfetext
    automatisch auf Weiss bzw. Hellgrau.
--}}
@props([
    'name',
    'label',
    'id' => null,
    'type' => 'text',
    'value' => null,
    'checked' => null,
    'options' => [],
    'inline' => false,
    'hint' => null,
    'required' => false,
    'autocomplete' => null,
    'optional' => false,
    'errorKey' => null,
])

@php
    $feldId = $id ?? preg_replace('/[^A-Za-z0-9_-]/', '-', $name);
    $fehlerId = $feldId.'-fehler';
    $hinweisId = $feldId.'-hinweis';
    $schluessel = $errorKey ?? rtrim($name, '[]');
    $hatFehler = $errors->has($schluessel);
    $fehlertext = $errors->first($schluessel);
    $istGruppe = in_array($type, ['radio-group', 'checkbox-group'], true);
    $istCheckbox = $type === 'checkbox';

    $beschreibung = trim(($hint !== null ? $hinweisId : '').' '.($hatFehler ? $fehlerId : ''));

    $labelKlassen = 'block text-sm font-semibold text-hvm-textschwarz [.hvm-dark_&]:text-white';
    $hinweisKlassen = 'mt-1 text-sm leading-relaxed text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau';

    if ($istGruppe) {
        $gruppenName = $type === 'checkbox-group' ? rtrim($name, '[]').'[]' : $name;
        $alt = old($schluessel, $value);
        $gewaehlt = $type === 'checkbox-group'
            ? array_map('strval', (array) ($alt ?? []))
            : ($alt !== null ? [(string) $alt] : []);
    } elseif ($istCheckbox) {
        $wert = $value ?? '1';
        $angehakt = $checked ?? (old($schluessel) !== null ? (string) old($schluessel) === (string) $wert : false);
        $feldAttribute = $attributes->merge([
            'id' => $feldId,
            'name' => $name,
            'class' => 'hvm-check',
        ]);
        if ($required) {
            $feldAttribute = $feldAttribute->merge(['required' => true]);
        }
        if ($beschreibung !== '') {
            $feldAttribute = $feldAttribute->merge(['aria-describedby' => $beschreibung]);
        }
        if ($hatFehler) {
            $feldAttribute = $feldAttribute->merge(['aria-invalid' => 'true']);
        }
    } else {
        $wert = $value ?? old($schluessel);
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
    }
@endphp

@if ($istGruppe)
    {{-- Radio- oder Checkbox-Gruppe: fieldset mit Legende, Optionen mit 44 px Trefferflaeche. --}}
    <fieldset {{ $attributes->class('min-w-0') }}
              @if ($beschreibung !== '') aria-describedby="{{ $beschreibung }}" @endif
              @if ($hatFehler) aria-invalid="true" @endif>
        <div class="flex items-baseline justify-between gap-3">
            <legend class="{{ $labelKlassen }}">
                {{ $label }}@if ($required)<span class="sr-only"> (Pflichtfeld)</span>@endif
            </legend>
            @if ($optional && ! $required)
                <span class="text-xs text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">optional</span>
            @endif
        </div>

        @if ($hint !== null)
            <p id="{{ $hinweisId }}" class="{{ $hinweisKlassen }}">{{ $hint }}</p>
        @endif

        <div class="mt-2 {{ $inline ? 'flex flex-wrap gap-x-6 gap-y-1' : 'flex flex-col gap-1' }}">
            @foreach ($options as $optionWert => $option)
                @php
                    $optionText = is_array($option) ? ($option['label'] ?? $optionWert) : $option;
                    $optionHinweis = is_array($option) ? ($option['hint'] ?? null) : null;
                    $optionId = $feldId.'-'.$loop->index;
                    $optionAngehakt = in_array((string) $optionWert, $gewaehlt, true);
                @endphp
                <label for="{{ $optionId }}" class="hvm-choice [.hvm-dark_&]:text-white [.hvm-dark_&]:hover:bg-white/10 {{ $optionHinweis !== null ? 'items-start' : '' }}">
                    <input id="{{ $optionId }}"
                           type="{{ $type === 'radio-group' ? 'radio' : 'checkbox' }}"
                           name="{{ $gruppenName }}"
                           value="{{ $optionWert }}"
                           class="hvm-check {{ $optionHinweis !== null ? 'mt-0.5' : '' }}"
                           @checked($optionAngehakt)
                           @if ($required && $type === 'radio-group') required @endif>
                    <span class="min-w-0">
                        <span class="block">{{ $optionText }}</span>
                        @if ($optionHinweis !== null)
                            <span class="block text-xs text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $optionHinweis }}</span>
                        @endif
                    </span>
                </label>
            @endforeach
            {{ $slot }}
        </div>

        @if ($hatFehler)
            <p id="{{ $fehlerId }}" class="mt-2 flex items-start gap-1.5 text-sm font-medium text-status-error">
                <x-hvm.icon name="warning" class="mt-0.5 h-4 w-4" />
                <span>{{ $fehlertext }}</span>
            </p>
        @endif
    </fieldset>
@elseif ($istCheckbox)
    {{-- Einzelnes Kontrollkaestchen: Label umschliesst das Feld (44 px Trefferflaeche). --}}
    <div class="min-w-0">
        <label for="{{ $feldId }}" class="hvm-choice [.hvm-dark_&]:text-white [.hvm-dark_&]:hover:bg-white/10 {{ $hint !== null ? 'items-start' : '' }}">
            <input type="checkbox" value="{{ $wert }}" {{ $feldAttribute->class(['mt-0.5' => $hint !== null]) }} @checked($angehakt)>
            <span class="min-w-0">
                <span class="block font-medium">{{ $label }}@if ($required)<span class="sr-only"> (Pflichtfeld)</span>@endif</span>
                @if ($hint !== null)
                    <span id="{{ $hinweisId }}" class="block text-xs leading-relaxed text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $hint }}</span>
                @endif
            </span>
        </label>

        @if ($hatFehler)
            <p id="{{ $fehlerId }}" class="mt-1 flex items-start gap-1.5 text-sm font-medium text-status-error">
                <x-hvm.icon name="warning" class="mt-0.5 h-4 w-4" />
                <span>{{ $fehlertext }}</span>
            </p>
        @endif
    </div>
@else
    <div class="min-w-0">
        <div class="flex items-baseline justify-between gap-3">
            <label for="{{ $feldId }}" class="{{ $labelKlassen }}">
                {{ $label }}@if ($required)<span class="sr-only"> (Pflichtfeld)</span>@endif
            </label>
            @if ($optional && ! $required)
                <span class="text-xs text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">optional</span>
            @endif
        </div>

        @if ($hint !== null)
            <p id="{{ $hinweisId }}" class="{{ $hinweisKlassen }}">{{ $hint }}</p>
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

        @if ($hatFehler)
            <p id="{{ $fehlerId }}" class="mt-2 flex items-start gap-1.5 text-sm font-medium text-status-error">
                <x-hvm.icon name="warning" class="mt-0.5 h-4 w-4" />
                <span>{{ $fehlertext }}</span>
            </p>
        @endif
    </div>
@endif
