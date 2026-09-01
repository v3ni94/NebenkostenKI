<?php

declare(strict_types=1);

namespace App\Application\Payment\Exceptions;

use RuntimeException;

/**
 * Der Checkout darf nicht eingeleitet werden.
 *
 * Jede Ausloesung benennt genau den fehlenden Schritt. Die Meldung ist ein
 * deutscher Nutzertext in Sie-Ansprache und wird als Formularfehler
 * ausgegeben, niemals als Stacktrace.
 */
final class CheckoutNotAllowedException extends RuntimeException
{
    public static function wrongStatus(): self
    {
        return new self(
            'Eine Zahlung ist erst möglich, wenn die Vorschau vorliegt. '
            .'Bitte schließen Sie die vorherigen Schritte ab.'
        );
    }

    public static function reviewMissing(): self
    {
        return new self(
            'Bitte bestätigen Sie zuerst, dass Sie alle Werte, Umlageschlüssel und Ergebnisse geprüft haben '
            .'und als Vermieter für die Abrechnung verantwortlich sind.'
        );
    }

    public static function immediatePerformanceConsentMissing(): self
    {
        return new self(
            'Bitte bestätigen Sie die sofortige Ausführung des Vertrags. '
            .'Ohne diese Bestätigung kann die Zahlung nicht eingeleitet werden.'
        );
    }

    public static function termsConsentMissing(): self
    {
        return new self(
            'Bitte bestätigen Sie die Allgemeinen Geschäftsbedingungen und die Datenschutzerklärung.'
        );
    }

    public static function alreadyPaid(): self
    {
        return new self(
            'Dieser Abrechnungslauf ist bereits bezahlt. Ihre Abrechnungen stehen im Downloadbereich bereit.'
        );
    }

    public static function snapshotMissing(): self
    {
        return new self(
            'Für diesen Abrechnungslauf liegt kein Berechnungsstand vor. '
            .'Bitte erstellen Sie zuerst die Vorschau.'
        );
    }

    public static function operatorMasterdataMissing(): self
    {
        return new self(
            'Die Zahlung ist derzeit nicht freigegeben, weil die Rechnungsangaben des Betreibers noch nicht '
            .'vollständig bestätigt sind. Bitte wenden Sie sich an den Support.'
        );
    }
}
