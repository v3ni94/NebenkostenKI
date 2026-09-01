<?php

declare(strict_types=1);

namespace App\Application\FollowUpYear;

use App\Enums\CostItemStatus;
use App\Mail\Format;
use App\Models\BillingRun;

/**
 * Vorjahresvergleich (Masterprompt 8.3).
 *
 * VERBINDLICH: Vorjahreswerte sind ausschliesslich Vergleichswerte. Sie werden
 * NIEMALS als neue Kosten in den Folgejahreslauf geschrieben. Diese Klasse
 * liest deshalb nur und schreibt nichts. Sie legt keine Kostenposition an und
 * veraendert keinen Betrag.
 *
 * Grundlage sind ausschliesslich bestaetigte Kostenpositionen des
 * Vorjahreslaufs. Verworfene und noch nicht bestaetigte Positionen bleiben
 * aussen vor, weil sie keinen belastbaren Vergleich ergeben.
 *
 * Betraege sind Integer in Cent.
 */
class PriorYearComparison
{
    /**
     * Vergleichssummen je Kostenkategorie in Cent.
     *
     * @return array<string, int>
     */
    public function jeKategorie(BillingRun $vorjahr): array
    {
        $summen = [];

        $positionen = $vorjahr->costItems()
            ->where('status', CostItemStatus::BESTAETIGT->value)
            ->whereNotNull('cost_category_id')
            ->get(['cost_category_id', 'amount_cent']);

        foreach ($positionen as $position) {
            $kategorie = $position->getAttribute('cost_category_id');

            if (! is_string($kategorie)) {
                continue;
            }

            $summen[$kategorie] = ($summen[$kategorie] ?? 0) + (int) $position->getAttribute('amount_cent');
        }

        return $summen;
    }

    public function gesamtCent(BillingRun $vorjahr): int
    {
        return array_sum($this->jeKategorie($vorjahr));
    }

    /**
     * Vergleichshinweis fuer die Oberflaeche, immer als Vergleich benannt.
     */
    public function hinweis(BillingRun $vorjahr): string
    {
        return sprintf(
            'Vergleich %d: bestätigte Kosten %s. Der Wert dient nur dem Vergleich und ist keine Kosten'
            .'position des neuen Jahres.',
            (int) $vorjahr->getAttribute('billing_year'),
            Format::betrag($this->gesamtCent($vorjahr)),
        );
    }
}
