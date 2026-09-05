{{--
    ANLAGE NACH § 35a EStG (Abschnitt 12.4 und 14.1)

    Ausgewiesen werden ausschließlich nachgewiesene begünstigte Bestandteile,
    getrennt nach haushaltsnahen Dienstleistungen und Handwerkerleistungen.
    Materialkosten werden NICHT automatisch als Lohnanteil ausgegeben. Ist der
    Lohnanteil nicht ausgewiesen, erscheint die Position ohne Betrag mit dem
    Hinweis "Lohnanteil nicht ausgewiesen".

    ANWALTLICH UND STEUERLICH FREIZUGEBEN: Der einleitende und der abschließende
    Hinweistext ist vor Livegang zu prüfen und freizugeben. Er ist allgemeine
    Information und keine steuerliche Beratung im Einzelfall.

    @var \App\Services\Pdf\View\TenantStatementView $view
    @var string $bodyFont
--}}
@include('pdf.partials.stil')

@php
    $haushalt = $view->taxBenefitLines(\App\Domain\Calculation\TaxBenefitCategory::HOUSEHOLD_SERVICE);
    $handwerker = $view->taxBenefitLines(\App\Domain\Calculation\TaxBenefitCategory::CRAFTSMAN_SERVICE);
    $ohneAusweis = 'Lohnanteil nicht ausgewiesen';
@endphp

<div class="absenderzeile">{{ $view->sender->address->senderLine() }}</div>

<h1>Anlage nach Paragraf 35a EStG</h1>

<table class="infoblock">
    <tr>
        <td class="bezeichnung">Objekt</td>
        <td>{{ $view->subject->propertyLabel }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Einheit</td>
        <td>{{ $view->subject->unitLabel ?? $view->result->unitLabel }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Mietverhältnis</td>
        <td>{{ $view->result->tenantLabel }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Abrechnungszeitraum</td>
        <td>{{ $view->result->billingPeriod->format() }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Datum</td>
        <td>{{ \App\Services\Pdf\Support\GermanDate::format($view->statementDate) }}</td>
    </tr>
</table>

<p>
    Diese Anlage weist die auf Ihre Einheit anteilig entfallenden Aufwendungen für haushaltsnahe
    Dienstleistungen und für Handwerkerleistungen aus. Übernommen werden nur Bestandteile, die in
    den zugrunde liegenden Unterlagen ausdrücklich als Arbeits-, Maschinen- oder Fahrtkosten
    ausgewiesen sind. Materialkosten sind nicht begünstigt und werden nicht als Lohnanteil
    ausgewiesen.
</p>

<h2>Haushaltsnahe Dienstleistungen</h2>
@if ($haushalt === [])
    <p>Für den Abrechnungszeitraum wurden keine begünstigten haushaltsnahen Dienstleistungen nachgewiesen.</p>
@else
    <table class="kosten">
        <thead>
            <tr>
                <th>Kostenart</th>
                <th class="betrag">Ihr Anteil EUR</th>
                <th class="betrag">Davon Lohnanteil EUR</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($haushalt as $zeile)
                <tr>
                    <td>{{ $zeile->categoryLabel }}</td>
                    <td class="betrag">{{ $zeile->share->formatAmount() }}</td>
                    <td class="betrag">
                        @if ($zeile->laborShareDisclosed && $zeile->taxBenefitLaborShare !== null)
                            {{ $zeile->taxBenefitLaborShare->formatAmount() }}
                        @else
                            {{ $ohneAusweis }}
                        @endif
                    </td>
                </tr>
            @endforeach
            <tr class="summe">
                <td colspan="2">Summe begünstigter Lohnanteil, haushaltsnahe Dienstleistungen</td>
                <td class="betrag">{{ $view->result->taxBenefitHouseholdServices->format() }}</td>
            </tr>
        </tbody>
    </table>
@endif

<h2>Handwerkerleistungen</h2>
@if ($handwerker === [])
    <p>Für den Abrechnungszeitraum wurden keine begünstigten Handwerkerleistungen nachgewiesen.</p>
@else
    <table class="kosten">
        <thead>
            <tr>
                <th>Kostenart</th>
                <th class="betrag">Ihr Anteil EUR</th>
                <th class="betrag">Davon Lohnanteil EUR</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($handwerker as $zeile)
                <tr>
                    <td>{{ $zeile->categoryLabel }}</td>
                    <td class="betrag">{{ $zeile->share->formatAmount() }}</td>
                    <td class="betrag">
                        @if ($zeile->laborShareDisclosed && $zeile->taxBenefitLaborShare !== null)
                            {{ $zeile->taxBenefitLaborShare->formatAmount() }}
                        @else
                            {{ $ohneAusweis }}
                        @endif
                    </td>
                </tr>
            @endforeach
            <tr class="summe">
                <td colspan="2">Summe begünstigter Lohnanteil, Handwerkerleistungen</td>
                <td class="betrag">{{ $view->result->taxBenefitCraftsmanServices->format() }}</td>
            </tr>
        </tbody>
    </table>
@endif

<table class="ergebnis">
    <tr class="hervorgehoben">
        <td>Summe der ausgewiesenen begünstigten Lohnanteile</td>
        <td class="betrag">{{ $view->result->taxBenefitTotal()->format() }}</td>
    </tr>
</table>

<div class="hinweisblock">
    <p class="klein">
        Positionen mit dem Hinweis "{{ $ohneAusweis }}" enthalten möglicherweise begünstigte
        Bestandteile, die in den Unterlagen nicht getrennt ausgewiesen sind. Sie wurden deshalb nicht
        als Lohnanteil übernommen. Die steuerliche Berücksichtigung prüft Ihr Finanzamt
        beziehungsweise Ihre steuerliche Beratung; diese Anlage ist eine allgemeine Information und
        keine steuerliche Beratung im Einzelfall.
    </p>
</div>
