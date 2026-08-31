<?php

declare(strict_types=1);

namespace App\Application\BillingRun;

use App\Enums\BillingRunStatus;
use RuntimeException;

/**
 * Unzulaessiger Statuswechsel eines Abrechnungslaufs.
 *
 * Die Meldung ist bewusst fachlich und deutsch formuliert. Sie darf einem
 * Nutzer angezeigt werden und enthaelt keine technischen Details.
 */
class IllegalStatusTransitionException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly BillingRunStatus $from,
        public readonly BillingRunStatus $to,
    ) {
        parent::__construct($message);
    }

    public static function forTransition(BillingRunStatus $from, BillingRunStatus $to): self
    {
        return new self(
            sprintf(
                'Der Abrechnungslauf kann nicht von "%s" nach "%s" wechseln.',
                $from->label(),
                $to->label()
            ),
            $from,
            $to
        );
    }

    public static function terminal(BillingRunStatus $from, BillingRunStatus $to): self
    {
        return new self(
            sprintf(
                'Der Abrechnungslauf ist mit "%s" abgeschlossen und kann nicht mehr geändert werden. '
                .'Eine Korrektur erzeugt eine neue Version und lässt die bestehende Abrechnung unverändert.',
                $from->label()
            ),
            $from,
            $to
        );
    }

    public static function afterPayment(BillingRunStatus $from, BillingRunStatus $to): self
    {
        return new self(
            sprintf(
                'Nach der bestätigten Zahlung ist keine Rückkehr in den Bearbeitungsstatus "%s" möglich. '
                .'Eine Korrektur erzeugt eine neue Version und lässt den bezahlten Stand unverändert.',
                $to->label()
            ),
            $from,
            $to
        );
    }

    public static function paymentMissing(BillingRunStatus $from, BillingRunStatus $to): self
    {
        return new self(
            'Die Finalisierung setzt eine bestätigte Zahlung voraus.',
            $from,
            $to
        );
    }
}
