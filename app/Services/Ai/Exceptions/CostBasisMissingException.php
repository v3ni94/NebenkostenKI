<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * Das Tagesbudget ist aktiv, fuer das Modell fehlt aber die Kalkulationsbasis.
 *
 * Ohne Kalkulationsbasis kann der Aufruf nicht gegen das Budget geprueft
 * werden. Ein Durchlassen waere eine stille Annahme zum Nulltarif und wuerde
 * das Tagesbudget aushebeln (Abschnitt 13.8). Der Aufruf wird deshalb nicht
 * ausgefuehrt. Der Zustand ist betrieblich und durch den Betreiber zu beheben:
 * Kalkulationsbasis in ai.cost_basis_us_cent_per_million_tokens ergaenzen oder
 * das Tagesbudget bewusst abschalten.
 */
final class CostBasisMissingException extends AiException
{
    public static function forModel(string $providerKey, string $model): self
    {
        return new self(sprintf(
            'Fuer das Modell "%s" des Providers "%s" ist keine Kalkulationsbasis in ai.cost_basis_us_cent_per_million_tokens hinterlegt. Das Tagesbudget ist aktiv und kann ohne Kalkulationsbasis nicht geprueft werden. Der Aufruf wurde nicht ausgefuehrt. Bitte ergaenzen Sie die Kalkulationsbasis oder schalten Sie das Tagesbudget bewusst ab.',
            $model,
            $providerKey,
        ));
    }
}
