{{--
    Zaehlwerte je Status als kompakte Kacheln in einer Karte.

    Die Schluessel sind persistierte Statuscodes. Angezeigt wird das deutsche
    Kurzlabel des jeweiligen Enums; der Code steht als title-Attribut fuer den
    Support bereit und erscheint nicht als Beschriftung.

    Erwartet:
      $titel   Ueberschrift
      $werte   array<string, int>
      $enum    Klassenname eines gestuetzten Enums mit label() (optional)
      $spalten Rasterklassen der Kacheln, Standard grid-cols-2; in Dreispalt-
               Karten grid-cols-2 lg:grid-cols-1 2xl:grid-cols-2, damit lange
               Labels nicht mitten im Wort brechen
--}}
@php
    $enum = $enum ?? null;
    $spalten = $spalten ?? 'grid-cols-2';
    $beschriftung = static function (string $code) use ($enum): string {
        if ($enum !== null && enum_exists($enum) && method_exists($enum, 'tryFrom')) {
            $fall = $enum::tryFrom($code);
            if ($fall !== null && method_exists($fall, 'label')) {
                return $fall->label();
            }
        }

        return $code;
    };
@endphp
<x-hvm.card :title="$titel">
    @if ($werte === [])
        <p class="text-sm text-hvm-text-sekundaer">Kein Eintrag.</p>
    @else
        <div class="grid {{ $spalten }} gap-3">
            @foreach ($werte as $schluessel => $anzahl)
                <x-hvm.stat size="sm" tone="canvas" :icon="false" :label="$beschriftung((string) $schluessel)" :value="$anzahl" :title="(string) $schluessel" />
            @endforeach
        </div>
    @endif
</x-hvm.card>
