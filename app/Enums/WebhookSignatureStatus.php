<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ergebnis der Signaturpruefung eines eingehenden Webhooks.
 */
enum WebhookSignatureStatus: string
{
    case GUELTIG = 'GUELTIG';
    case UNGUELTIG = 'UNGUELTIG';
    case FEHLT = 'FEHLT';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::GUELTIG => 'Signatur gültig',
            self::UNGUELTIG => 'Signatur ungültig',
            self::FEHLT => 'Signatur fehlt',
        };
    }
}
