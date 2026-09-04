<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * Eine fruehere temporaere Providerdatei ist noch nicht bestaetigt geloescht.
 *
 * Solange eine Providerdatei offen ist, darf keine weitere angelegt werden,
 * weil der Kurzzeitdatensatz genau eine Datei-ID fuehrt und eine zweite die
 * erste unauffindbar machen wuerde (Abschnitt 6.4, ADR-007). Der Zustand ist
 * betrieblich, nicht fachlich: Die Loeschung wird wiederholt, danach laeuft
 * die Auswertung normal weiter. Die Ausnahme fuehrt deshalb keinen Hinweis
 * auf ein Dateiformat und darf nicht als endgueltiger Fehler behandelt werden.
 *
 * DATENSCHUTZ: Es wird keine Datei-ID mitgefuehrt.
 */
final class ProviderFileNotReleasedException extends AiException
{
    public static function uploadBlocked(string $providerKey): self
    {
        return new self(sprintf(
            'Beim Provider "%s" ist eine fruehere temporaere Datei noch nicht bestaetigt geloescht. Es wird keine weitere Datei angelegt.',
            $providerKey,
        ));
    }

    public static function trackingConflict(string $providerKey): self
    {
        return new self(sprintf(
            'Beim Provider "%s" wurde eine zweite temporaere Datei angelegt, waehrend die erste noch offen ist. Die zweite Datei wird sofort geloescht, der Aufruf wird abgebrochen.',
            $providerKey,
        ));
    }
}
