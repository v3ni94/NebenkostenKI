{{--
    Verarbeitungsstand je Dokument.

    DATENSCHUTZ: Nur neutrale Quellenbezeichnung, Dokumentart, Status,
    Seitenzahl und Loeschstatus. Kein Dateiname, kein Vorschaubild, kein Abruf
    der Originaldatei.

    Der Loeschstatus wird ausdruecklich angezeigt. Der Nutzer soll sehen, dass
    seine Originaldatei tatsaechlich entfernt wurde, und nicht nur darauf
    vertrauen muessen.
--}}
@if ($dokumente === [])
    <x-hvm.card class="mt-4">
        <p>
            Es sind noch keine Unterlagen hochgeladen. Beginnen Sie mit der Hausgeldabrechnung oder
            der Betriebskostenaufstellung, alles Weitere können Sie jederzeit ergänzen.
        </p>
    </x-hvm.card>
@else
    <div class="mt-4 overflow-x-auto" data-upload-status-list>
        <table class="hvm-table-zebra w-full border-collapse text-left text-base">
            <caption class="sr-only">Verarbeitungsstand der hochgeladenen Unterlagen</caption>
            <thead>
                <tr class="bg-hvm-orange-soft">
                    <th scope="col" class="px-3 py-2 font-semibold">Quelle</th>
                    <th scope="col" class="px-3 py-2 font-semibold">Dokumentart</th>
                    <th scope="col" class="px-3 py-2 font-semibold">Verarbeitung</th>
                    <th scope="col" class="px-3 py-2 font-semibold">Seiten</th>
                    <th scope="col" class="px-3 py-2 font-semibold">Originaldatei</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($dokumente as $dokument)
                    <tr class="border-b border-hvm-hellgrau align-top">
                        <td class="px-3 py-3 font-semibold">
                            {{ $dokument['quellenbezeichnung'] }}
                            @if ($dokument['dublette'])
                                <span class="mt-1 block">
                                    <x-hvm.badge variant="warning">Mögliche Dublette</x-hvm.badge>
                                </span>
                            @endif
                        </td>
                        <td class="px-3 py-3">{{ $dokument['dokumentart'] ?? 'Noch nicht bestimmt' }}</td>
                        <td class="px-3 py-3">
                            <x-hvm.badge
                                :variant="match ($dokument['status']) {
                                    'ABGESCHLOSSEN' => 'success',
                                    'FEHLGESCHLAGEN', 'ABGELEHNT', 'ABGEBROCHEN' => 'error',
                                    default => 'info',
                                }"
                            >
                                {{ $dokument['statustext'] ?? 'Unbekannt' }}
                            </x-hvm.badge>

                            @if ($dokument['fehlermeldung'] !== null)
                                <p class="mt-2 text-sm text-hvm-textschwarz">{{ $dokument['fehlermeldung'] }}</p>
                            @endif
                        </td>
                        <td class="px-3 py-3">{{ $dokument['seiten'] ?? 'Nicht bestimmt' }}</td>
                        <td class="px-3 py-3">
                            <x-hvm.badge
                                :variant="match ($dokument['loeschstatus']) {
                                    'ERFOLGREICH' => 'success',
                                    'FEHLGESCHLAGEN', 'UEBERFAELLIG' => 'error',
                                    default => 'neutral',
                                }"
                            >
                                {{ $dokument['loeschstatustext'] ?? 'Unbekannt' }}
                            </x-hvm.badge>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
