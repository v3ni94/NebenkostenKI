<?php

declare(strict_types=1);

namespace App\Services\Payment\Exceptions;

use RuntimeException;

/**
 * Die Zahlungsanbindung ist nicht vollstaendig konfiguriert.
 *
 * Der Name der fehlenden Umgebungsvariable ist eine technische Angabe und
 * enthaelt keinen Schluesselwert. Die Meldung erreicht den Nutzer nicht als
 * Stacktrace; die Oberflaeche zeigt einen sachlichen deutschen Hinweis.
 */
final class PaymentConfigurationException extends RuntimeException
{
    public static function missing(string $envKey): self
    {
        return new self(sprintf(
            'Die Zahlungsanbindung ist nicht vollständig konfiguriert. Es fehlt die Umgebungsvariable %s. '
            .'Ohne diesen Wert wird kein Zahlungsvorgang eingeleitet.',
            $envKey
        ));
    }
}
