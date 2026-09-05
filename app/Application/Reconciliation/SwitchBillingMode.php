<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Enums\BillingMode;
use App\Models\BillingRun;

/**
 * Wechsel des Abrechnungswegs (Abschnitt 5.3).
 *
 * VERBINDLICH: Eine falsche Wegwahl darf keine strukturierten
 * Extraktionsdaten loeschen. Der Wechsel aendert ausschliesslich den Modus des
 * Abrechnungslaufs. Die ausgelesenen Inhaltsdaten (extracted_fields), die
 * Dokumente und die bereits getroffenen Nutzerentscheidungen bleiben
 * unveraendert; die Kostenpositionen werden anschliessend neu eingeordnet.
 *
 * Die Neueinordnung uebernimmt ReconcileBillingRun. Sie ersetzt nur noch nicht
 * entschiedene Vorschlaege.
 */
final class SwitchBillingMode
{
    public function __construct(private readonly ReconcileBillingRun $reconcile) {}

    public function switch(BillingRun $billingRun, BillingMode $mode, bool $reconcile = true): BillingRun
    {
        $current = $billingRun->getAttribute('mode');

        if ($current === $mode) {
            return $billingRun;
        }

        $billingRun->forceFill(['mode' => $mode])->save();

        if ($reconcile) {
            $this->reconcile->run($billingRun);
        }

        return $billingRun->refresh();
    }
}
