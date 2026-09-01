<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use RuntimeException;

/**
 * Eine Pruefaufgabe mit besonderem Schutzzweck ist nicht wegklickbar.
 */
final class RuleNotUserResolvableException extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf(
            'Die Prüfaufgabe "%s" kann nicht durch eine Nutzerentscheidung aufgelöst werden. Sie ist inhaltlich '
            .'zu klären.',
            $code
        ));
    }
}
