<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status einer Zahlung.
 *
 * Die Finalisierung wird ausschliesslich durch einen verifizierten Webhook
 * freigeschaltet. Ein Browser-Redirect ist niemals Zahlungsnachweis.
 */
enum PaymentStatus: string
{
    case ERSTELLT = 'ERSTELLT';
    case AUSSTEHEND = 'AUSSTEHEND';
    case BEZAHLT = 'BEZAHLT';
    case FEHLGESCHLAGEN = 'FEHLGESCHLAGEN';
    case ABGEBROCHEN = 'ABGEBROCHEN';
    case ABGELAUFEN = 'ABGELAUFEN';
    case ERSTATTET = 'ERSTATTET';
    case TEILWEISE_ERSTATTET = 'TEILWEISE_ERSTATTET';
    case ANGEFOCHTEN = 'ANGEFOCHTEN';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::ERSTELLT => 'Checkout erstellt',
            self::AUSSTEHEND => 'Zahlung ausstehend',
            self::BEZAHLT => 'Bezahlt',
            self::FEHLGESCHLAGEN => 'Fehlgeschlagen',
            self::ABGEBROCHEN => 'Abgebrochen',
            self::ABGELAUFEN => 'Abgelaufen',
            self::ERSTATTET => 'Erstattet',
            self::TEILWEISE_ERSTATTET => 'Teilweise erstattet',
            self::ANGEFOCHTEN => 'Angefochten',
        };
    }

    /**
     * Zahlung ist bestaetigt und berechtigt zur Finalisierung.
     */
    public function unlocksFinalization(): bool
    {
        return $this === self::BEZAHLT;
    }
}
