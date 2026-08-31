<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Zahlungsanbieter. Aktuell ausschliesslich Stripe Checkout.
 */
enum PaymentProvider: string
{
    case STRIPE = 'STRIPE';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::STRIPE => 'Stripe',
        };
    }
}
