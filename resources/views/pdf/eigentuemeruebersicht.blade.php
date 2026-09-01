{{--
    EIGENTÜMERÜBERSICHT, INTERNES BLATT JE ABRECHNUNGSLAUF (Abschnitt 14.2)

    Internes Dokument für den Eigentümer beziehungsweise Nutzer, nicht zum
    Versand an Mieter bestimmt. Neutral gestaltet, ohne Logo und ohne
    CI-Farben der Hausverwaltung.

    @var \App\Services\Pdf\View\OwnerOverviewView $view
    @var string $bodyFont
--}}
@include('pdf.partials.stil')

@php($ergebnis = $view->result)

<h1>{{ $view->subjectLine() }}</h1>
<p class="klein">Internes Übersichtsblatt, nicht zum Versand an Mieter bestimmt.</p>

<table class="infoblock">
    <tr>
        <td class="bezeichnung">Objekt</td>
        <td>{{ $ergebnis->propertyLabel }}{{ $view->propertyAddressLine !== null ? ', '.$view->propertyAddressLine : '' }}</td>
    </tr>
    @if ($view->owner)
        <tr>
            <td class="bezeichnung">Eigentümer</td>
            <td>{{ $view->owner->senderLine() }}</td>
        </tr>
    @endif
    <tr>
        <td class="bezeichnung">Abrechnungszeitraum</td>
        <td>{{ $ergebnis->billingPeriod->format() }} ({{ $ergebnis->billingPeriod->days() }} Tage)</td>
    </tr>
    @if ($view->billingRunReference)
        <tr>
            <td class="bezeichnung">Abrechnungslauf</td>
            <td>{{ $view->billingRunReference }}</td>
        </tr>
    @endif
    <tr>
        <td class="bezeichnung">Datum</td>
        <td>{{ \App\Services\Pdf\Support\GermanDate::format($view->generatedOn) }}</td>
    </tr>
</table>

<h2>Einheiten und Mietverhältnisse</h2>
<table class="kosten">
    <thead>
        <tr>
            <th>Einheit</th>
            <th>Mietverhältnis</th>
            <th>Nutzungszeitraum</th>
            <th class="betrag">Umlagefähige Kosten EUR</th>
            <th class="betrag">Vorauszahlungen EUR</th>
            <th class="betrag">Ergebnis EUR</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($ergebnis->statements as $abrechnung)
            <tr>
                <td>{{ $abrechnung->unitLabel }}</td>
                <td>{{ $abrechnung->tenantLabel }}</td>
                <td>{{ $abrechnung->usagePeriod->format() }}<br><span class="rechenweg">{{ $abrechnung->usageDays() }} Tage</span></td>
                <td class="betrag">{{ $abrechnung->allocableTotal->formatAmount() }}</td>
                <td class="betrag">{{ $abrechnung->prepaymentActual->formatAmount() }}</td>
                <td class="betrag">
                    {{ $abrechnung->balance->formatAmount() }}
                    <br><span class="rechenweg">{{ $abrechnung->isCredit() ? 'Guthaben Mieter' : ($abrechnung->balance->isZero() ? 'ausgeglichen' : 'Nachzahlung Mieter') }}</span>
                </td>
            </tr>
        @endforeach
        <tr class="summe">
            <td colspan="3">Summe der Mieterergebnisse</td>
            <td class="betrag">{{ $ergebnis->allocatedToTenantsTotal->formatAmount() }}</td>
            <td class="betrag"></td>
            <td class="betrag">{{ $ergebnis->tenantBalanceTotal()->formatAmount() }}</td>
        </tr>
    </tbody>
</table>

<h2>Leerstandsanteile zulasten Eigentümer</h2>
@if ($ergebnis->vacancyShares === [])
    <p>Für den Abrechnungszeitraum wurden keine Leerstandsanteile ermittelt.</p>
@else
    <table class="kosten">
        <thead>
            <tr>
                <th>Einheit</th>
                <th>Art</th>
                <th>Zeitraum</th>
                <th class="betrag">Tage</th>
                <th class="betrag">Anteil EUR</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ergebnis->vacancyShares as $leerstand)
                <tr>
                    <td>{{ $leerstand->unitLabel }}</td>
                    <td>{{ $leerstand->kind->label() }}</td>
                    <td>{{ $leerstand->period->format() }}</td>
                    <td class="betrag">{{ $leerstand->days() }}</td>
                    <td class="betrag">{{ $leerstand->total->formatAmount() }}</td>
                </tr>
            @endforeach
            <tr class="summe">
                <td colspan="4">Summe Leerstandsanteile</td>
                <td class="betrag">{{ $ergebnis->vacancyTotal->formatAmount() }}</td>
            </tr>
        </tbody>
    </table>
@endif

<h2>Ausgeschlossene Kosten</h2>
@if ($ergebnis->excludedCosts === [])
    <p>Es wurden keine Kostenpositionen von der Umlage ausgeschlossen.</p>
@else
    <table class="kosten">
        <thead>
            <tr>
                <th>Kostenart</th>
                <th>Umlagestatus</th>
                <th>Grund</th>
                <th class="betrag">Betrag EUR</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ergebnis->excludedCosts as $ausgeschlossen)
                <tr>
                    <td>{{ $ausgeschlossen->categoryLabel }}</td>
                    <td>{{ $ausgeschlossen->allocabilityStatus->label() }}</td>
                    <td>{{ $ausgeschlossen->reason }}</td>
                    <td class="betrag">{{ $ausgeschlossen->amount->formatAmount() }}</td>
                </tr>
            @endforeach
            <tr class="summe">
                <td colspan="3">Summe ausgeschlossene Kosten</td>
                <td class="betrag">{{ $ergebnis->excludedCostTotal->formatAmount() }}</td>
            </tr>
        </tbody>
    </table>
@endif

@if ($ergebnis->residualShares !== [])
    <h2>Nicht verteilte Restanteile</h2>
    <table class="kosten">
        <thead>
            <tr>
                <th>Kostenart</th>
                <th>Erläuterung</th>
                <th class="betrag">Gesamtkosten EUR</th>
                <th class="betrag">Restanteil EUR</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ergebnis->residualShares as $rest)
                <tr>
                    <td>{{ $rest->categoryLabel }}</td>
                    <td>{{ $rest->explanation }}</td>
                    <td class="betrag">{{ $rest->totalCost->formatAmount() }}</td>
                    <td class="betrag">{{ $rest->amount->formatAmount() }}</td>
                </tr>
            @endforeach
            <tr class="summe">
                <td colspan="3">Summe Restanteile</td>
                <td class="betrag">{{ $ergebnis->residualTotal->formatAmount() }}</td>
            </tr>
        </tbody>
    </table>
@endif

<h2>Gesamtsummen und Prüfsummen</h2>
<table class="ergebnis">
    <tr>
        <td>Kosten des Laufs insgesamt</td>
        <td class="betrag">{{ $ergebnis->grossCostTotal()->format() }}</td>
    </tr>
    <tr>
        <td>Davon einbezogene Kosten</td>
        <td class="betrag">{{ $ergebnis->includedCostTotal->format() }}</td>
    </tr>
    <tr>
        <td>Auf Mieter verteilt</td>
        <td class="betrag">{{ $ergebnis->allocatedToTenantsTotal->format() }}</td>
    </tr>
    <tr>
        <td>Leerstandsanteile zulasten Eigentümer</td>
        <td class="betrag">{{ $ergebnis->vacancyTotal->format() }}</td>
    </tr>
    <tr>
        <td>Nicht verteilte Restanteile</td>
        <td class="betrag">{{ $ergebnis->residualTotal->format() }}</td>
    </tr>
    <tr>
        <td>Ausgeschlossene Kosten</td>
        <td class="betrag">{{ $ergebnis->excludedCostTotal->format() }}</td>
    </tr>
    <tr class="hervorgehoben">
        <td>Vom Eigentümer zu tragen</td>
        <td class="betrag">{{ $ergebnis->ownerBurdenTotal()->format() }}</td>
    </tr>
    <tr>
        <td>Prüfsumme Verteilung</td>
        <td class="betrag">
            {{ $ergebnis->isBalanced() ? 'ausgeglichen' : 'Abweichung '.$ergebnis->checksumDifference()->format() }}
        </td>
    </tr>
</table>

<h2>Prüfwarnungen</h2>
@if ($view->findings === [])
    <p>Es liegen keine offenen Prüfhinweise vor.</p>
@else
    <table class="kosten">
        <thead>
            <tr>
                <th>Schweregrad</th>
                <th>Prüfung</th>
                <th>Hinweis</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($view->findings as $befund)
                <tr>
                    <td>{{ $befund->severity->label() }}</td>
                    <td>{{ $befund->code->value }}</td>
                    <td>{{ $befund->message }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{--
    TEXTBAUSTEIN ANWALTLICH FREIZUGEBEN.

    Vermerk fuer Fall B (Zentralheizung ohne externen Abrechner): erfasste
    Herkunft der Berechnung und der Hinweis, dass keine Pruefung durch die
    Plattform erfolgt ist. Keine Garantieaussage, keine Paragrafenangabe.
--}}
@if ($view->manualHeatingEntry)
    <h2>Manuell erfasste Heizkosten</h2>
    <p data-vermerk="heizkosten-manuell">
        Die Heizkosten je Einheit wurden vom Vermieter selbst ermittelt und unverändert übernommen. Eine Prüfung
        der Verteilung nach Grund- und Verbrauchskosten sowie der CO2-Kostenaufteilung ist durch die Plattform
        nicht erfolgt. Verantwortlich für die Richtigkeit der Werte ist der Vermieter.
    </p>
    <table class="infoblock">
        <tr>
            <td class="bezeichnung">Herkunft der Berechnung</td>
            <td>{{ $view->manualHeatingOrigin ?? 'Keine Angabe erfasst.' }}</td>
        </tr>
    </table>
@endif

<h2>Manuelle Entscheidungen</h2>
@if ($view->manualDecisions === [])
    <p>Es wurden keine manuellen Entscheidungen erfasst.</p>
@else
    <table class="kosten">
        <thead>
            <tr>
                <th>Sachverhalt</th>
                <th>Entscheidung</th>
                <th>Begründung</th>
                <th>Entschieden</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($view->manualDecisions as $entscheidung)
                <tr>
                    <td>{{ $entscheidung->topic }}</td>
                    <td>{{ $entscheidung->decision }}</td>
                    <td>{{ $entscheidung->reason ?? '' }}</td>
                    <td>
                        {{ \App\Services\Pdf\Support\GermanDate::format($entscheidung->decidedAt) }}
                        @if ($entscheidung->decidedBy)
                            <br><span class="rechenweg">{{ $entscheidung->decidedBy }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<h2>Dokumentenübersicht</h2>
@if ($view->documents === [])
    <p>Für diesen Lauf sind bisher keine Dokumente erzeugt worden.</p>
@else
    <table class="kosten">
        <thead>
            <tr>
                <th>Dokument</th>
                <th>Variante</th>
                <th>Empfänger</th>
                <th>Erzeugt am</th>
                <th class="betrag">Seiten</th>
                <th>Prüfsumme SHA-256</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($view->documents as $dokument)
                <tr>
                    <td>{{ $dokument->kindLabel }}</td>
                    <td>{{ $dokument->variantLabel }}</td>
                    <td>{{ $dokument->recipientLabel ?? '' }}</td>
                    <td>{{ \App\Services\Pdf\Support\GermanDate::format($dokument->generatedAt) }}</td>
                    <td class="betrag">{{ $dokument->pageCount ?? '' }}</td>
                    <td><span class="rechenweg">{{ $dokument->shortSha256() }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
