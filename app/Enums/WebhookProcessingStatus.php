<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Verarbeitungsstand eines Webhook-Events. Verarbeitung ist idempotent.
 */
enum WebhookProcessingStatus: string
{
    case EMPFANGEN = 'EMPFANGEN';
    case VERARBEITET = 'VERARBEITET';
    case IGNORIERT = 'IGNORIERT';
    case FEHLGESCHLAGEN = 'FEHLGESCHLAGEN';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::EMPFANGEN => 'Empfangen',
            self::VERARBEITET => 'Verarbeitet',
            self::IGNORIERT => 'Ignoriert',
            self::FEHLGESCHLAGEN => 'Fehlgeschlagen',
        };
    }
}
