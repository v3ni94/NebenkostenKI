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
        <dl class="grid grid-cols-2 gap-3">
            @foreach ($werte as $schluessel => $anzahl)
                <x-hvm.rollout-admin-kennzahl :label="$schluessel">{{ $anzahl }}</x-hvm.rollout-admin-kennzahl>
            @endforeach
        </dl>
    @endif
</x-hvm.card>
