<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Interne Rolle des Betreibers. Nicht mit Kundenrollen vermischen.
 *
 * Adminsitzungen sind von Kundensitzungen getrennt, 2FA ist verpflichtend.
 */
enum AdminRole: string
{
    case ADMIN = 'ADMIN';
    case SUPPORT = 'SUPPORT';
    case FINANCE = 'FINANCE';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administration',
            self::SUPPORT => 'Support',
            self::FINANCE => 'Finanzen',
        };
    }
}
