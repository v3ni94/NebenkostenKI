{{--
    Zaehlwerte als schlichte Definitionsliste.

    Erwartet:
      $titel   Ueberschrift
      $werte   array<string, int>
--}}
<x-hvm.card :title="$titel">
    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach ($werte as $schluessel => $anzahl)
            <div class="rounded border border-hvm-hellgrau bg-hvm-umrissgrau px-3 py-2">
                <dt class="text-xs text-hvm-anthrazit">{{ $schluessel }}</dt>
                <dd class="text-lg font-semibold">{{ $anzahl }}</dd>
            </div>
        @endforeach
    </dl>
</x-hvm.card>
