{{--
    Verarbeitungsstand je Dokument.

    DATENSCHUTZ: Nur neutrale Quellenbezeichnung, Dokumentart, Status,
    Seitenzahl und Loeschstatus. Kein Dateiname, kein Vorschaubild, kein Abruf
    der Originaldatei.

    Der Loeschstatus wird ausdruecklich angezeigt. Der Nutzer soll sehen, dass
    seine Originaldatei tatsaechlich entfernt wurde, und nicht nur darauf
    vertrauen muessen.

    Darstellung: Tabelle des Designsystems, auf Desktop klassisch, unter 640 px
    gestapelt (jede Zelle mit ihrer Spaltenbeschriftung). Status immer als
    Text plus Symbol.
--}}
@if ($dokumente === [])
    <x-hvm.empty-state icon="upload" title="Noch keine Unterlagen">
        <p>
            Es sind noch keine Unterlagen hochgeladen. Beginnen Sie mit der Hausgeldabrechnung oder
            der Betriebskostenaufstellung, alles Weitere können Sie jederzeit ergänzen.
        </p>
    </x-hvm.empty-state>
@else
    <div class="overflow-hidden rounded-3xl border border-hvm-linie bg-white" data-upload-status-list>
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-base">
            <caption class="sr-only">Verarbeitungsstand der hochgeladenen Unterlagen</caption>
            <thead>
                <tr>
                    <th scope="col">Quelle</th>
                    <th scope="col">Dokumentart</th>
                    <th scope="col">Verarbeitung</th>
                    <th scope="col">Seiten</th>
                    <th scope="col">Originaldatei</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($dokumente as $dokument)
                    <tr>
                        <th scope="row" class="font-medium">
                            {{ $dokument['quellenbezeichnung'] }}
                            @if ($dokument['dublette'])
                                <span class="mt-2 block">
                                    <x-hvm.badge variant="warning">Mögliche Dublette</x-hvm.badge>
                                </span>
                            @endif
                        </th>
                        <td data-label="Dokumentart">{{ $dokument['dokumentart'] ?? 'Noch nicht bestimmt' }}</td>
                        <td data-label="Verarbeitung">
                            @php
                                $verarbeitung = match ($dokument['status']) {
                                    'ABGESCHLOSSEN' => ['success', null],
                                    'FEHLGESCHLAGEN', 'ABGELEHNT', 'ABGEBROCHEN' => ['error', null],
                                    default => ['info', 'clock'],
                                };
                            @endphp
                            <x-hvm.badge :variant="$verarbeitung[0]" :icon="$verarbeitung[1]">
                                {{ $dokument['statustext'] ?? 'Unbekannt' }}
                            </x-hvm.badge>

                            @if ($dokument['fehlermeldung'] !== null)
                                <p class="mt-2 text-sm leading-relaxed text-hvm-textschwarz">{{ $dokument['fehlermeldung'] }}</p>
                            @endif
                        </td>
                        <td data-label="Seiten" class="tabular">{{ $dokument['seiten'] ?? 'Nicht bestimmt' }}</td>
                        <td data-label="Originaldatei">
                            @php
                                $loeschung = match ($dokument['loeschstatus']) {
                                    'ERFOLGREICH' => ['success', null],
                                    'FEHLGESCHLAGEN', 'UEBERFAELLIG' => ['error', null],
                                    default => ['neutral', 'clock'],
                                };
                            @endphp
                            <x-hvm.badge :variant="$loeschung[0]" :icon="$loeschung[1]">
                                {{ $dokument['loeschstatustext'] ?? 'Unbekannt' }}
                            </x-hvm.badge>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
