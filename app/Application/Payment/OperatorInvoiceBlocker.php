<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Services\Pdf\Support\OperatorDetails;

/**
 * Abfragbarer Blockerzustand der produktiven Rechnungserzeugung
 * (Abschnitt 2.1, 15.2).
 *
 * VERBINDLICH: Solange Steuernummer beziehungsweise Umsatzsteuer-Identifikations-
 * nummer und Bankverbindung fehlen oder die Stammdaten nicht ausdruecklich
 * bestaetigt sind, ist die produktive Rechnungserzeugung blockiert. Die
 * fehlenden Angaben werden NIEMALS erfunden oder ersatzweise gesetzt; die
 * Rechnung wuerde stattdessen den sichtbaren Platzhalter aus
 * config('smartabrechnen.operator.placeholder_text') drucken.
 *
 * Diese Klasse ist die eine Stelle, die den Zustand beantwortet. Der
 * Adminbereich der Phase 5 zeigt genau diese Angaben als Livegang-Blocker an.
 */
final class OperatorInvoiceBlocker
{
    /**
     * True, solange keine produktive Rechnung erzeugt werden darf.
     */
    public function isBlocked(): bool
    {
        return $this->details()->isLaunchBlocked();
    }

    /**
     * Fehlende Pflichtangaben in deutscher Beschriftung.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        return $this->details()->missingMandatoryFields();
    }

    public function masterdataConfirmed(): bool
    {
        return $this->details()->masterdataConfirmed();
    }

    /**
     * Vollstaendiger Zustand fuer die Anzeige im Adminbereich.
     *
     * @return array{
     *     blockiert: bool,
     *     stammdaten_bestaetigt: bool,
     *     fehlende_angaben: list<string>,
     *     platzhalter: string,
     *     hinweis: string
     * }
     */
    public function state(): array
    {
        $details = $this->details();
        $missing = $details->missingMandatoryFields();

        return [
            'blockiert' => $details->isLaunchBlocked(),
            'stammdaten_bestaetigt' => $details->masterdataConfirmed(),
            'fehlende_angaben' => $missing,
            'platzhalter' => $details->placeholderText,
            'hinweis' => $this->hint($missing, $details->masterdataConfirmed()),
        ];
    }

    /**
     * @param  list<string>  $missing
     */
    private function hint(array $missing, bool $confirmed): string
    {
        if ($missing === [] && $confirmed) {
            return 'Die Pflichtangaben des Betreibers sind vollständig und ausdrücklich bestätigt.';
        }

        if ($missing === []) {
            return 'Die Pflichtangaben sind vollständig, aber noch nicht ausdrücklich bestätigt. '
                .'Bis zur Bestätigung wird keine produktive Rechnung erzeugt.';
        }

        return sprintf(
            'Die produktive Rechnungserzeugung ist blockiert. Es fehlen: %s. '
            .'Die Angaben werden ausschließlich aus der Konfiguration übernommen und nicht ergänzt.',
            implode(', ', $missing),
        );
    }

    private function details(): OperatorDetails
    {
        return OperatorDetails::fromConfig();
    }
}
