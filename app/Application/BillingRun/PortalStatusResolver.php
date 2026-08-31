<?php

declare(strict_types=1);

namespace App\Application\BillingRun;

use App\Domain\Period\DatePeriodRange;
use App\Enums\BillingRunStatus;
use App\Models\BillingRun;
use App\Models\Property;
use App\Models\Unit;

/**
 * Uebersetzt technische Zustaende in die vier Statuskategorien der Oberflaeche.
 *
 * Vorgabe des Masterprompts, Abschnitt 9: Das Dashboard zeigt statt technischer
 * Fehlermeldungen eine klare Liste. Der Nutzer erfaehrt, was zu tun ist, nicht
 * welcher interne Status gesetzt ist.
 *
 * Zuordnung der Laufstatus:
 *
 *   Erledigt                   FINALIZED, PAID, FINALIZING
 *                              Fuer den Nutzer ist nichts mehr zu tun.
 *   Bitte pruefen              REVIEW_REQUIRED, CALCULATED, PREVIEW_READY
 *                              Es liegen Ergebnisse vor, die zu bestaetigen sind.
 *   Fehlt noch                 DRAFT, UPLOADING, EXTRACTING,
 *                              READY_FOR_CALCULATION, CHECKOUT_PENDING
 *   Blockiert die Abrechnung   FAILED, CANCELLED
 */
class PortalStatusResolver
{
    public function __construct(private readonly OccupancyTimeline $timeline) {}

    public function forBillingRun(BillingRun $billingRun): PortalStatus
    {
        $status = $billingRun->getAttribute('status');
        $status = $status instanceof BillingRunStatus ? $status : BillingRunStatus::DRAFT;

        return match ($status) {
            BillingRunStatus::FINALIZED => new PortalStatus(
                PortalStatusCategory::ERLEDIGT,
                'Die Abrechnungen sind erstellt und stehen zum Download bereit.'
            ),
            BillingRunStatus::PAID, BillingRunStatus::FINALIZING => new PortalStatus(
                PortalStatusCategory::ERLEDIGT,
                'Die Zahlung ist bestätigt. Die Abrechnungen werden erstellt, Sie erhalten eine E-Mail.'
            ),
            BillingRunStatus::REVIEW_REQUIRED => new PortalStatus(
                PortalStatusCategory::BITTE_PRUEFEN,
                'Einzelne Angaben sind zu prüfen, bevor die Abrechnung weitergeht.'
            ),
            BillingRunStatus::CALCULATED => new PortalStatus(
                PortalStatusCategory::BITTE_PRUEFEN,
                'Die Berechnung liegt vor. Bitte prüfen Sie das Ergebnis.'
            ),
            BillingRunStatus::PREVIEW_READY => new PortalStatus(
                PortalStatusCategory::BITTE_PRUEFEN,
                'Die Vorschau liegt bereit. Bitte prüfen Sie alle Werte und bestätigen Sie sie.'
            ),
            BillingRunStatus::DRAFT => new PortalStatus(
                PortalStatusCategory::FEHLT_NOCH,
                'Der Abrechnungslauf ist angelegt. Es fehlen noch Ihre Unterlagen.'
            ),
            BillingRunStatus::UPLOADING => new PortalStatus(
                PortalStatusCategory::FEHLT_NOCH,
                'Es fehlen noch Unterlagen. Sie können jederzeit weitere hochladen.'
            ),
            BillingRunStatus::EXTRACTING => new PortalStatus(
                PortalStatusCategory::FEHLT_NOCH,
                'Ihre Unterlagen werden ausgewertet. Sie können die Seite verlassen und später zurückkehren.'
            ),
            BillingRunStatus::READY_FOR_CALCULATION => new PortalStatus(
                PortalStatusCategory::FEHLT_NOCH,
                'Alle Angaben liegen vor. Die Berechnung steht noch aus.'
            ),
            BillingRunStatus::CHECKOUT_PENDING => new PortalStatus(
                PortalStatusCategory::FEHLT_NOCH,
                'Die Zahlung ist eingeleitet und noch nicht bestätigt.'
            ),
            BillingRunStatus::FAILED => new PortalStatus(
                PortalStatusCategory::BLOCKIERT,
                $this->fehlerhinweis($billingRun)
            ),
            BillingRunStatus::CANCELLED => new PortalStatus(
                PortalStatusCategory::BLOCKIERT,
                'Der Abrechnungslauf ist abgebrochen. Sie können einen neuen Lauf anlegen.'
            ),
        };
    }

    /**
     * Status eines Objekts aus Sicht der Abrechnungsfaehigkeit.
     */
    public function forProperty(Property $property): PortalStatus
    {
        $einheiten = $property->units()->get();
        $details = [];
        $blockiert = false;
        $pruefen = false;

        if ($einheiten->isEmpty()) {
            return new PortalStatus(
                PortalStatusCategory::FEHLT_NOCH,
                'Für dieses Objekt ist noch keine Einheit erfasst.'
            );
        }

        foreach ($einheiten as $einheit) {
            if (! $einheit instanceof Unit) {
                continue;
            }

            $flaeche = $einheit->getAttribute('living_area_sqm');

            if ($flaeche === null) {
                $details[] = sprintf(
                    'Für die Einheit %s fehlt die Wohnfläche.',
                    $this->bezeichnung($einheit)
                );
            }

            foreach ($this->timeline->findings($einheit, $this->laufenderRahmen($property)) as $befund) {
                $details[] = $befund['text'];

                if ($befund['art'] === PortalStatusCategory::BLOCKIERT) {
                    $blockiert = true;
                }

                if ($befund['art'] === PortalStatusCategory::BITTE_PRUEFEN) {
                    $pruefen = true;
                }
            }
        }

        foreach ($this->plausibilityHints($property) as $hinweis) {
            $details[] = $hinweis;
            $pruefen = true;
        }

        if ($blockiert) {
            return new PortalStatus(
                PortalStatusCategory::BLOCKIERT,
                'Für dieses Objekt fehlen Angaben, ohne die keine Abrechnung erstellt werden kann.',
                $details
            );
        }

        if ($details !== []) {
            return new PortalStatus(
                $pruefen ? PortalStatusCategory::BITTE_PRUEFEN : PortalStatusCategory::FEHLT_NOCH,
                $pruefen
                    ? 'Bitte prüfen Sie die folgenden Angaben zu diesem Objekt.'
                    : 'Zu diesem Objekt fehlen noch Angaben.',
                $details
            );
        }

        return new PortalStatus(
            PortalStatusCategory::ERLEDIGT,
            'Die Objektdaten sind vollständig erfasst.'
        );
    }

    /**
     * Plausibilitaetspruefung der Flaechen- und Anteilssummen.
     *
     * Vorgabe des Masterprompts, Schritt 4: Plausibilitaetspruefung von
     * Flaechen- und Anteilssummen. Die Pruefung ist ausdruecklich ein Hinweis
     * und kein Blocker, weil Gemeinschaftsflaechen und Teileigentum
     * regelmaessig zu Abweichungen fuehren, die fachlich richtig sind.
     *
     * Gerechnet wird mit Zeichenketten und ganzzahligen Zwischenwerten, damit
     * keine binaere Gleitkommaungenauigkeit entsteht (ARCHITECTURE.md
     * Grundsatz 8). Flaechen werden in Quadratzentimeter, also mit vier
     * Nachkommastellen als Ganzzahl, verglichen.
     *
     * @return list<string>
     */
    public function plausibilityHints(Property $property): array
    {
        $hinweise = [];
        $einheiten = $property->units()->get();

        $summeWohnflaeche = 0;
        $summeBeheizt = 0;
        $summeMea = 0;
        $hatWohnflaeche = false;
        $hatMea = false;

        foreach ($einheiten as $einheit) {
            if (! $einheit instanceof Unit) {
                continue;
            }

            $wohn = $this->skaliert($einheit->getAttribute('living_area_sqm'), 4);
            $beheizt = $this->skaliert($einheit->getAttribute('heated_area_sqm'), 4);
            $mea = $this->skaliert($einheit->getAttribute('mea'), 6);

            if ($wohn !== null) {
                $summeWohnflaeche += $wohn;
                $hatWohnflaeche = true;
            }

            if ($beheizt !== null) {
                $summeBeheizt += $beheizt;
            }

            if ($mea !== null) {
                $summeMea += $mea;
                $hatMea = true;
            }
        }

        $gesamtWohnflaeche = $this->skaliert($property->getAttribute('total_living_area_sqm'), 4);

        if ($hatWohnflaeche && $gesamtWohnflaeche !== null && $gesamtWohnflaeche !== $summeWohnflaeche) {
            $hinweise[] = sprintf(
                'Die Summe der Einheitenflächen beträgt %s Quadratmeter, am Objekt sind %s Quadratmeter erfasst.',
                $this->deutscheZahl($summeWohnflaeche, 4),
                $this->deutscheZahl($gesamtWohnflaeche, 4)
            );
        }

        if ($summeBeheizt > $summeWohnflaeche && $hatWohnflaeche) {
            $hinweise[] = 'Die beheizte Fläche ist insgesamt größer als die Wohnfläche. Bitte prüfen Sie die Werte.';
        }

        $nenner = $this->skaliert($property->getAttribute('mea_denominator'), 6);

        if ($hatMea && $nenner !== null && $summeMea !== $nenner) {
            $hinweise[] = sprintf(
                'Die Summe der Miteigentumsanteile beträgt %s, der Nenner des Objekts lautet %s.',
                $this->deutscheZahl($summeMea, 6),
                $this->deutscheZahl($nenner, 6)
            );
        }

        if ($hatMea && $nenner === null) {
            $hinweise[] = 'Am Objekt fehlt der Nenner der Miteigentumsanteile. Ohne ihn ist der Anteil nicht prüfbar.';
        }

        return $hinweise;
    }

    private function fehlerhinweis(BillingRun $billingRun): string
    {
        $meldung = $billingRun->getAttribute('failure_message');

        if (is_string($meldung) && trim($meldung) !== '') {
            return trim($meldung);
        }

        return 'Die Abrechnung konnte nicht abgeschlossen werden. Bitte prüfen Sie Ihre Angaben und Unterlagen.';
    }

    /**
     * Rahmenzeitraum fuer die Zeitachsenpruefung: der jeweils letzte
     * Abrechnungszeitraum des Objekts, sonst das Vorjahr.
     */
    private function laufenderRahmen(Property $property): DatePeriodRange
    {
        /** @var BillingRun|null $lauf */
        $lauf = $property->billingRuns()->orderByDesc('period_start')->first();

        if ($lauf instanceof BillingRun) {
            $start = $lauf->getAttribute('period_start');
            $ende = $lauf->getAttribute('period_end');

            if ($start !== null && $ende !== null) {
                return DatePeriodRange::fromIso(
                    substr((string) $start, 0, 10),
                    substr((string) $ende, 0, 10)
                );
            }
        }

        return DatePeriodRange::calendarYear((int) now()->format('Y') - 1);
    }

    private function bezeichnung(Unit $unit): string
    {
        $label = $unit->getAttribute('label');

        return is_string($label) && $label !== '' ? $label : 'ohne Bezeichnung';
    }

    /**
     * Wandelt einen Dezimalwert in eine Ganzzahl mit fester Skalierung um.
     */
    private function skaliert(mixed $wert, int $stellen): ?int
    {
        if ($wert === null) {
            return null;
        }

        if (! is_string($wert) && ! is_int($wert) && ! is_float($wert)) {
            return null;
        }

        $text = (string) $wert;

        if (! preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return null;
        }

        [$ganz, $bruch] = array_pad(explode('.', $text, 2), 2, '');
        $bruch = substr(str_pad($bruch, $stellen, '0'), 0, $stellen);

        return (int) ($ganz.$bruch);
    }

    private function deutscheZahl(int $skaliert, int $stellen): string
    {
        $teiler = 10 ** $stellen;
        $ganz = intdiv(abs($skaliert), $teiler);
        $bruch = abs($skaliert) % $teiler;

        $text = number_format($ganz, 0, ',', '.');
        $nachkomma = rtrim(str_pad((string) $bruch, $stellen, '0', STR_PAD_LEFT), '0');

        if ($nachkomma !== '') {
            $text .= ','.$nachkomma;
        }

        return ($skaliert < 0 ? '-' : '').$text;
    }
}
