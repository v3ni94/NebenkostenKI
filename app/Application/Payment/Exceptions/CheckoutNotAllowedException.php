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

    public static function paymentAlreadyReceived(): self
    {
        return new self(
            'Zu diesem Abrechnungslauf liegt bereits ein Zahlungseingang vor, der noch zugeordnet wird. '
            .'Bitte zahlen Sie nicht erneut und wenden Sie sich an den Support.'
        );
    }

    public static function billingAddressMissing(): self
    {
        return new self(
            'Für die Rechnung fehlt Ihre vollständige Rechnungsanschrift (Straße und Hausnummer, Postleitzahl, '
            .'Ort). Bitte ergänzen Sie sie unter Konto, bevor Sie die Zahlung einleiten.'
        );
    }

    public static function previewInvalid(): self
    {
        return new self(
            'Für den aktuellen Stand liegt keine gültige Vorschau vor. Seit der letzten Vorschau wurden Daten '
            .'geändert. Bitte erzeugen Sie die Vorschau in Schritt 10 neu, prüfen Sie sie und bestätigen Sie '
            .'sie erneut.'
        );
    }

    public static function blocked(string $grund): self
    {
        return new self(
            'Eine Zahlung ist derzeit nicht möglich, weil noch ein Punkt offen ist: '.$grund
            .' Bitte beheben Sie ihn und erzeugen Sie die Vorschau anschließend neu.'
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
