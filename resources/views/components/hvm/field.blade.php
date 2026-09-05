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
      id            Element-ID, Standard = name. Kommt ein Feldname auf einer
                    Seite mehrfach vor (Listen von Formularen), immer eine
                    eindeutige ID mit Praefix setzen.
      label         Beschriftung (Pflicht, sofern kein Slot labelHtml). Bei
                    radio-group und checkbox-group die Legende der Gruppe.
      labelHidden   true blendet die Beschriftung visuell aus (sr-only), z. B.
                    fuer Inline-Formulare in Tabellenzellen mit placeholder.
      labelSize     sm (Standard) oder lg. Nur Gruppen: lg stellt die Legende
                    als Abschnittstitel dar (text-lg), wenn die Gruppe einen
                    ganzen Formularabschnitt bildet.
      type          text, email, password, number, date, tel, url, textarea,
                    select, checkbox, radio-group, checkbox-group
                    (bei select und textarea liefert der Slot den Inhalt)
      value         Vorbelegung, Standard old(name). Bei checkbox der
                    Formularwert (Standard "1"), bei Gruppen der gewaehlte
                    Wert bzw. die Liste der gewaehlten Werte.
      checked       Nur checkbox: true setzt das Feld an (Standard: old(name))
      options       Nur radio-group und checkbox-group: Array Wert => Text,
                    oder Wert => ['label' => ..., 'hint' => ..., 'id' => ...]
                    (id optional, Standard {id}-{index})
      inline        Nur Gruppen: true stellt die Optionen nebeneinander
      hint          Hilfetext zum Feld
      hintPosition  above (Standard, unter dem Label) oder below (unter dem
                    Eingabefeld, fuer lange Hinweise). Bei checkbox steht der
                    Hilfetext immer unter der Beschriftung.
      align         Nur checkbox: center (Standard) oder start. Bei start
                    steht das Kaestchen an der ersten Textzeile. Beschriftungen
                    ab 80 Zeichen oder mit Hilfetext werden automatisch oben
                    ausgerichtet.
      required      true setzt required und markiert das Label
      autocomplete  Wert des autocomplete-Attributs
      optional      true zeigt "optional" statt Pflichtmarkierung
      errorKey      Schluessel im Fehlerbeutel, Standard name
      errors        false unterdrueckt die Fehleranzeige des Feldes (fuer
                    Wiederholformulare mit gleichen Feldnamen; die Fehler
                    stehen dann nur in der Sammelmeldung des Layouts)
      wrapperClass  Klassen fuer das umschliessende Element (div bzw.
                    fieldset), z. B. "sm:col-span-2" in Rastern

    Slots:
      default    Inhalt von select und textarea bzw. weitere Optionen bei Gruppen
      labelHtml  Beschriftung mit Markup (z. B. Link auf die Datenschutz-
                 erklaerung) anstelle von label

    Alle uebrigen Attribute (placeholder, min, max, step, autofocus,
    inputmode, pattern, ...) werden an das Eingabefeld durchgereicht.

    Fehlerstruktur: <p id="{id}-fehler"> mit aria-describedby und
    aria-invalid="true" am Feld (bei Gruppen am fieldset), identisch zum
    bisherigen Muster. Innerhalb von .hvm-dark wechseln Label und Hilfetext
    automatisch auf Weiss bzw. Hellgrau.
--}}
@props([
    'name',
    'label' => null,
    'labelHidden' => false,
    'labelSize' => 'sm',
    'id' => null,
    'type' => 'text',
    'value' => null,
    'checked' => null,
    'options' => [],
    'inline' => false,
    'hint' => null,
    'hintPosition' => 'above',
    'align' => null,
    'required' => false,
    'autocomplete' => null,
    'optional' => false,
    'errorKey' => null,
    'errors' => true,
    'wrapperClass' => '',
])

@php
    $feldId = $id ?? preg_replace('/[^A-Za-z0-9_-]/', '-', $name);
    $fehlerId = $feldId.'-fehler';
    $hinweisId = $feldId.'-hinweis';
    $schluessel = $errorKey ?? rtrim($name, '[]');
    // Das Prop "errors" ueberlagert die geteilte Variable $errors (ViewErrorBag);
    // ohne Angabe liegt der Fehlerbeutel in $errors, sonst kommt er aus view().
    $fehlerbeutel = $errors instanceof \Illuminate\Support\ViewErrorBag ? $errors : view()->shared('errors');
    $fehlerAktiv = $errors !== false;
    $hatFehler = $fehlerAktiv && $fehlerbeutel instanceof \Illuminate\Support\ViewErrorBag && $fehlerbeutel->has($schluessel);
    $fehlertext = $hatFehler ? $fehlerbeutel->first($schluessel) : '';
    $istGruppe = in_array($type, ['radio-group', 'checkbox-group'], true);
    $istCheckbox = $type === 'checkbox';
    $hatLabelHtml = isset($labelHtml) && $labelHtml->isNotEmpty();
    $labelText = $label ?? '';
    $hinweisUnten = $hint !== null && $hintPosition === 'below';

    $beschreibung = trim(($hint !== null ? $hinweisId : '').' '.($hatFehler ? $fehlerId : ''));

    $grosseLegende = $istGruppe && $labelSize === 'lg';
    $labelKlassen = $grosseLegende
        ? 'block text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl [.hvm-dark_&]:text-white'
        : 'block text-sm font-semibold text-hvm-textschwarz [.hvm-dark_&]:text-white';
    $hinweisKlassen = ($hinweisUnten || $grosseLegende ? 'mt-2' : 'mt-1').' text-sm leading-relaxed text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau'.($grosseLegende ? ' max-w-prose' : '');
    $labelZeile = $labelHidden ? 'sr-only' : 'flex items-baseline justify-between gap-3';

    if ($istGruppe) {
        $gruppenName = $type === 'checkbox-group' ? rtrim($name, '[]').'[]' : $name;
        $alt = old($schluessel, $value);
        $gewaehlt = $type === 'checkbox-group'
            ? array_map('strval', (array) ($alt ?? []))
            : ($alt !== null ? [(string) $alt] : []);
    } elseif ($istCheckbox) {
        $wert = $value ?? '1';
        $angehakt = $checked ?? (old($schluessel) !== null ? (string) old($schluessel) === (string) $wert : false);
        $obenAusrichten = $align === 'start' || ($align === null && ($hint !== null || $hatLabelHtml || mb_strlen($labelText) >= 80));
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
    <fieldset {{ $attributes->class(['min-w-0', $wrapperClass]) }}
              @if ($beschreibung !== '') aria-describedby="{{ $beschreibung }}" @endif
              @if ($hatFehler) aria-invalid="true" @endif>
        <div class="{{ $labelZeile }}">
            <legend class="{{ $labelKlassen }}">
                {{ $hatLabelHtml ? $labelHtml : $labelText }}@if ($required)<span class="sr-only"> (Pflichtfeld)</span>@endif
            </legend>
            @if ($optional && ! $required)
                <span class="text-xs text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">optional</span>
            @endif
        </div>

        @if ($hint !== null && ! $hinweisUnten)
            <p id="{{ $hinweisId }}" class="{{ $hinweisKlassen }}">{{ $hint }}</p>
        @endif

        <div class="{{ $grosseLegende ? 'mt-4' : 'mt-2' }} {{ $inline ? 'flex flex-wrap gap-x-6 gap-y-1' : 'flex flex-col gap-1' }}">
            @foreach ($options as $optionWert => $option)
                @php
                    $optionText = is_array($option) ? ($option['label'] ?? $optionWert) : $option;
                    $optionHinweis = is_array($option) ? ($option['hint'] ?? null) : null;
                    $optionId = is_array($option) && isset($option['id']) ? $option['id'] : $feldId.'-'.$loop->index;
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
                        <span class="block {{ $optionHinweis !== null ? 'font-semibold' : '' }}">{{ $optionText }}</span>
                        @if ($optionHinweis !== null)
                            <span class="block text-xs leading-relaxed text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $optionHinweis }}</span>
                        @endif
                    </span>
                </label>
            @endforeach
            {{ $slot }}
        </div>

        @if ($hinweisUnten)
            <p id="{{ $hinweisId }}" class="{{ $hinweisKlassen }}">{{ $hint }}</p>
        @endif

        @if ($hatFehler)
            <p id="{{ $fehlerId }}" class="mt-2 flex items-start gap-1.5 text-sm font-medium text-status-error">
                <x-hvm.icon name="warning" class="mt-0.5 h-4 w-4" />
                <span>{{ $fehlertext }}</span>
            </p>
        @endif
    </fieldset>
@elseif ($istCheckbox)
    {{-- Einzelnes Kontrollkaestchen: Label umschliesst das Feld (44 px Trefferflaeche). --}}
    <div class="min-w-0 {{ $wrapperClass }}">
        <label for="{{ $feldId }}" class="hvm-choice [.hvm-dark_&]:text-white [.hvm-dark_&]:hover:bg-white/10 {{ $obenAusrichten ? 'items-start' : '' }}">
            <input type="checkbox" value="{{ $wert }}" {{ $feldAttribute->class(['mt-0.5' => $obenAusrichten]) }} @checked($angehakt)>
            <span class="min-w-0">
                <span class="block font-medium">{{ $hatLabelHtml ? $labelHtml : $labelText }}@if ($required)<span class="sr-only"> (Pflichtfeld)</span>@endif</span>
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
    <div class="min-w-0 {{ $wrapperClass }}">
        <div class="{{ $labelZeile }}">
            <label for="{{ $feldId }}" class="{{ $labelKlassen }}">
                {{ $hatLabelHtml ? $labelHtml : $labelText }}@if ($required)<span class="sr-only"> (Pflichtfeld)</span>@endif
            </label>
            @if ($optional && ! $required)
                <span class="text-xs text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">optional</span>
            @endif
        </div>

        @if ($hint !== null && ! $hinweisUnten)
            <p id="{{ $hinweisId }}" class="{{ $hinweisKlassen }}">{{ $hint }}</p>
        @endif

        <div class="{{ $labelHidden ? '' : 'mt-2' }}">
            @if ($type === 'textarea')
                <textarea {{ $feldAttribute }}>{{ $wert }}</textarea>
            @elseif ($type === 'select')
                <select {{ $feldAttribute }}>{{ $slot }}</select>
            @else
                <input type="{{ $type }}" value="{{ $wert }}" {{ $feldAttribute }}>
            @endif
        </div>

        @if ($hinweisUnten)
            <p id="{{ $hinweisId }}" class="{{ $hinweisKlassen }}">{{ $hint }}</p>
        @endif

        @if ($hatFehler)
            <p id="{{ $fehlerId }}" class="mt-2 flex items-start gap-1.5 text-sm font-medium text-status-error">
                <x-hvm.icon name="warning" class="mt-0.5 h-4 w-4" />
                <span>{{ $fehlertext }}</span>
            </p>
        @endif
    </div>
@endif
