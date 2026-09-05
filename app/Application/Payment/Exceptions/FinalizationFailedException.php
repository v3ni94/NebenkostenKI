<?php

declare(strict_types=1);

namespace App\Application\Payment\Exceptions;

use RuntimeException;

/**
 * Die Finalisierung konnte nicht abgeschlossen werden.
 *
 * Eine bestaetigte Zahlung bleibt bestehen. Der Lauf wird auf FAILED gesetzt
 * und kann erneut finalisiert werden; die Statusmaschine laesst diesen Weg
 * ausdruecklich nur bei bestaetigter Zahlung zu.
 */
final class FinalizationFailedException extends RuntimeException
{
    public static function paymentNotConfirmed(): self
    {
        return new self(
            'Die Finalisierung wurde nicht freigeschaltet, weil keine bestätigte Zahlung vorliegt. '
            .'Freigeschaltet wird ausschließlich über die signaturgeprüfte Rückmeldung des Zahlungsanbieters.'
        );
    }

    public static function snapshotMissing(): self
    {
        return new self(
            'Zu diesem Abrechnungslauf liegt kein Berechnungsstand vor. Es werden keine Abrechnungen erzeugt.'
        );
    }

    public static function viewsUnavailable(): self
    {
        return new self(
            'Die Aufbereitung des gesperrten Berechnungsstandes ist nicht verfügbar. '
            .'Die Finalisierung wurde abgebrochen, damit keine Abrechnung aus ersatzweise gebildeten Werten entsteht.'
        );
    }

    public static function alreadyInProgress(): self
    {
        return new self(
            'Die Finalisierung dieses Abrechnungslaufs ist bereits in Bearbeitung. '
            .'Es wird kein zweiter Durchlauf gestartet.'
        );
    }

    public static function withoutStatements(): self
    {
        return new self(
            'Der gesperrte Berechnungsstand enthält keine Mieterabrechnung. Es wird keine Finalversion erzeugt.'
        );
    }
}
