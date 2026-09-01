<?php

declare(strict_types=1);

namespace App\Application\FollowUpYear;

use App\Models\BillingRun;

/**
 * Ergebnis der Folgejahresuebernahme.
 *
 * Der Bericht sagt ausdruecklich, was uebernommen wurde und was der Nutzer fuer
 * das neue Jahr erneut bestaetigen muss. Kostenpositionen sind niemals Teil der
 * Uebernahme.
 */
final class CarryOverResult
{
    /**
     * @param  list<string>  $einheiten  Kennungen der uebernommenen Einheiten
     * @param  list<string>  $mietverhaeltnisse  Kennungen der fortgefuehrten Mietverhaeltnisse
     * @param  list<string>  $verteilerschluessel  Kennungen der neu angelegten Verteilerschluessel
     * @param  list<string>  $kostenkategorien  Kennungen der uebernommenen Kostenkategorien
     */
    public function __construct(
        public readonly BillingRun $lauf,
        public readonly BillingRun $vorjahr,
        public readonly bool $neuAngelegt,
        public readonly array $einheiten = [],
        public readonly array $mietverhaeltnisse = [],
        public readonly array $verteilerschluessel = [],
        public readonly array $kostenkategorien = [],
    ) {}

    public function hinweis(): string
    {
        return CarriedOver::HINWEIS;
    }

    /**
     * Kurzfassung fuer Oberflaeche und Protokoll, ohne Kundendaten.
     */
    public function zusammenfassung(): string
    {
        return sprintf(
            '%s: %d Einheiten, %d laufende Mietverhältnisse, %d Verteilerschlüssel, %d Kostenkategorien. '
            .'Belege, Vorauszahlungen, Zählerstände und Heizkosten bestätigen Sie für das neue Jahr erneut.',
            CarriedOver::HINWEIS,
            count($this->einheiten),
            count($this->mietverhaeltnisse),
            count($this->verteilerschluessel),
            count($this->kostenkategorien),
        );
    }
}
