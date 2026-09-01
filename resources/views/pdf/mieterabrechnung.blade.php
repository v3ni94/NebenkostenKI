{{--
    MIETERABRECHNUNG (Abschnitt 14.1 und 11.1)

    Absender und inhaltlich Verantwortlicher ist der Vermieter, nicht die
    Hausverwaltung Müller GmbH. Diese Vorlage enthält deshalb bewusst KEIN
    Logo, KEINE Kennlinie und KEINE Farbwerte der Hausverwaltung. Einzige
    Erwähnung der Plattform ist die dezente Fußzeile aus
    config('smartabrechnen.pdf.tenant_footer').

    Diese Vorlage rechnet nicht. Alle Beträge, Schlüssel, Zähler, Nenner und
    Zeitanteile stammen unverändert aus dem UnitStatementResult.

    @var \App\Services\Pdf\View\TenantStatementView $view
    @var string $bodyFont
--}}
@include('pdf.partials.stil')

<div class="absenderzeile">{{ $view->sender->address->senderLine() }}</div>

@include('pdf.partials.anschrift', ['adresse' => $view->recipient])

<table class="infoblock">
    <tr>
        <td class="bezeichnung">Objekt</td>
        <td>{{ $view->subject->propertyLabel }}{{ $view->subject->propertyAddressLine !== null ? ', '.$view->subject->propertyAddressLine : '' }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Einheit</td>
        <td>{{ $view->subject->unitLabel ?? $view->result->unitLabel }}{{ $view->subject->unitPosition !== null ? ', '.$view->subject->unitPosition : '' }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Mietverhältnis</td>
        <td>{{ $view->result->tenantLabel }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Abrechnungszeitraum</td>
        <td>{{ $view->result->billingPeriod->format() }} ({{ $view->result->billingPeriod->days() }} Tage)</td>
    </tr>
    <tr>
        <td class="bezeichnung">Nutzungszeitraum</td>
        <td>{{ $view->result->usagePeriod->format() }} ({{ $view->result->usageDays() }} Tage)</td>
    </tr>
    <tr>
        <td class="bezeichnung">Datum</td>
        <td>{{ \App\Services\Pdf\Support\GermanDate::format($view->statementDate) }}</td>
    </tr>
    @if ($view->sender->contactPhone || $view->sender->contactEmail)
        <tr>
            <td class="bezeichnung">Kontakt</td>
            <td>{{ trim(implode(', ', array_filter([$view->sender->contactPhone, $view->sender->contactEmail]))) }}</td>
        </tr>
    @endif
</table>

<h1>{{ $view->subjectLine() }}</h1>

<p>
    Sehr geehrte Damen und Herren, nachstehend erhalten Sie die Abrechnung der Betriebskosten
    für den oben genannten Zeitraum. Die Verteilung der Kosten ist je Kostenart nachvollziehbar
    ausgewiesen.
</p>

<table class="kosten">
    <thead>
        <tr>
            <th>Kostenart</th>
            <th class="betrag">Gesamtkosten EUR</th>
            <th>Verteilerschlüssel</th>
            <th>Ihr Anteil</th>
            <th class="betrag">Betrag EUR</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($view->regularLines() as $zeile)
            <tr>
                <td>
                    {{ $zeile->categoryLabel }}
                    @if ($zeile->includedByOverride)
                        <br><span class="rechenweg">Einbezogen aufgrund Ihrer ausdrücklichen Entscheidung{{ $zeile->inclusionOverrideReason !== null ? ': '.$zeile->inclusionOverrideReason : '' }}</span>
                    @endif
                    @if ($zeile->allocabilityStatus !== \App\Domain\Calculation\AllocabilityStatus::ALLOCABLE)
                        <br><span class="rechenweg">Status: {{ $zeile->allocabilityStatus->label() }}</span>
                    @endif
                </td>
                <td class="betrag">{{ $zeile->totalCost->formatAmount() }}</td>
                <td>
                    {{ $zeile->allocationKeyLabel }}
                    <br><span class="rechenweg">{{ $zeile->allocationExplanation }}</span>
                </td>
                <td>
                    <span class="rechenweg">
                        Ihr Anteil {{ $zeile->numerator }} von {{ $zeile->denominator }}<br>
                        Zeitanteil {{ $zeile->timeFactor->explanation() }}
                        @if ($zeile->roundingAdjustmentCent !== 0)
                            <br>Rundungsausgleich {{ \App\Domain\Money\Money::fromCents($zeile->roundingAdjustmentCent)->formatAmount() }}
                        @endif
                        @if ($zeile->substituteDistributionConfirmed)
                            <br>Bestätigte Ersatzverteilung, keine Zwischenablesung vorhanden
                        @endif
                    </span>
                </td>
                <td class="betrag">{{ $zeile->share->formatAmount() }}</td>
            </tr>
        @endforeach
        <tr class="zwischensumme">
            <td colspan="4">Zwischensumme ohne Heizkosten</td>
            <td class="betrag">{{ $view->subtotalWithoutHeating()->formatAmount() }}</td>
        </tr>
    </tbody>
</table>

@if ($view->hasHeatingBlock())
    <h2>Heizkosten und Warmwasser</h2>
    <table class="kosten">
        <thead>
            <tr>
                <th>Kostenart</th>
                <th class="betrag">Gesamtkosten EUR</th>
                <th>Verteilerschlüssel</th>
                <th>Ihr Anteil</th>
                <th class="betrag">Betrag EUR</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($view->heatingLines() as $zeile)
                <tr>
                    <td>{{ $zeile->categoryLabel }}</td>
                    <td class="betrag">{{ $zeile->totalCost->formatAmount() }}</td>
                    <td>
                        {{ $zeile->allocationKeyLabel }}
                        <br><span class="rechenweg">{{ $zeile->allocationExplanation }}</span>
                    </td>
                    <td>
                        <span class="rechenweg">
                            Ihr Anteil {{ $zeile->numerator }} von {{ $zeile->denominator }}<br>
                            Zeitanteil {{ $zeile->timeFactor->explanation() }}
                            @if ($zeile->substituteDistributionConfirmed)
                                <br>Bestätigte Ersatzverteilung, keine Zwischenablesung vorhanden
                            @endif
                        </span>
                    </td>
                    <td class="betrag">{{ $zeile->share->formatAmount() }}</td>
                </tr>
            @endforeach
            <tr class="zwischensumme">
                <td colspan="4">Zwischensumme Heizkosten</td>
                <td class="betrag">{{ $view->heatingSubtotal()->formatAmount() }}</td>
            </tr>
        </tbody>
    </table>

    {{--
        TEXTBAUSTEIN ANWALTLICH FREIZUGEBEN.

        Sachlicher Vermerk fuer Fall B (Zentralheizung ohne externen
        Abrechner): Die Heizkosten je Einheit hat der Vermieter ermittelt; sie
        wurden unveraendert uebernommen. Keine Garantieaussage, keine
        Paragrafenangabe, keine Rechtsberatung im Einzelfall.
    --}}
    @if ($view->manualHeatingEntry)
        <p class="klein" data-vermerk="heizkosten-manuell">
            Die Heizkosten je Einheit wurden vom Vermieter ermittelt und in dieser Abrechnung unverändert
            übernommen.
        </p>
    @endif
@endif

<table class="ergebnis">
    <tr>
        <td>Summe der umlagefähigen Kosten</td>
        <td class="betrag">{{ $view->result->allocableTotal->format() }}</td>
    </tr>
    <tr>
        <td>Abzüglich Ihrer tatsächlich geleisteten Vorauszahlungen</td>
        <td class="betrag">{{ $view->result->prepaymentActual->format() }}</td>
    </tr>
    <tr class="hervorgehoben">
        @if ($view->result->isCredit())
            <td>Guthaben zu Ihren Gunsten</td>
            <td class="betrag">{{ $view->result->credit()->format() }}</td>
        @else
            <td>Nachzahlung</td>
            <td class="betrag">{{ $view->result->additionalPayment()->format() }}</td>
        @endif
    </tr>
</table>

@if ($view->result->isCredit())
    <p>
        Das Guthaben von {{ $view->result->credit()->format() }} wird Ihnen erstattet.
        Bitte teilen Sie dem Absender bei Bedarf Ihre aktuelle Bankverbindung mit.
    </p>
@elseif ($view->result->balance->isZero())
    <p>Die geleisteten Vorauszahlungen entsprechen den abgerechneten Kosten. Es ergibt sich kein Zahlungsbetrag.</p>
@else
    <p>
        Bitte überweisen Sie den Nachzahlungsbetrag von {{ $view->result->additionalPayment()->format() }}
        an den oben genannten Absender.
    </p>
@endif

@if ($view->bankAccount())
    @php($bank = $view->bankAccount())
    <table class="infoblock">
        <tr>
            <td class="bezeichnung">Zahlungsempfänger</td>
            <td>{{ $bank->accountHolder }}</td>
        </tr>
        <tr>
            <td class="bezeichnung">IBAN</td>
            <td>{{ $bank->iban }}</td>
        </tr>
        @if ($bank->bic)
            <tr>
                <td class="bezeichnung">BIC</td>
                <td>{{ $bank->bic }}</td>
            </tr>
        @endif
        @if ($bank->bankName)
            <tr>
                <td class="bezeichnung">Kreditinstitut</td>
                <td>{{ $bank->bankName }}</td>
            </tr>
        @endif
        @if ($bank->paymentReference)
            <tr>
                <td class="bezeichnung">Verwendungszweck</td>
                <td>{{ $bank->paymentReference }}</td>
            </tr>
        @endif
    </table>
@endif

@if ($view->notices() !== [])
    <div class="kennzeichnung">
        <strong>Kennzeichnungen zu dieser Abrechnung</strong>
        <ul>
            @foreach ($view->notices() as $hinweis)
                <li>{{ $hinweis }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($view->findings() !== [])
    <div class="kennzeichnung">
        <strong>Hinweise aus der Prüfung</strong>
        <ul>
            @foreach ($view->findings() as $befund)
                <li>{{ $befund->severity->label() }}: {{ $befund->message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@include('pdf.partials.rechtliche-hinweise')
