{{--
    Zaehlwerte je Status als kompakte Kacheln in einer Karte.

    Erwartet:
      $titel   Ueberschrift
      $werte   array<string, int>
--}}
<x-hvm.card :title="$titel">
    @if ($werte === [])
        <p class="text-sm text-hvm-text-sekundaer">Kein Eintrag.</p>
    @else
        <div class="grid grid-cols-2 gap-3">
            @foreach ($werte as $schluessel => $anzahl)
                <x-hvm.stat size="sm" tone="canvas" :icon="false" :label="$schluessel" :value="$anzahl" />
            @endforeach
        </div>
    @endif
</x-hvm.card>
