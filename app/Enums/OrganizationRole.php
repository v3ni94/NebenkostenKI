<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kundenrolle innerhalb einer Organisation.
 *
 * Vollstaendig getrennt von den internen Adminrollen (siehe AdminRole).
 */
enum OrganizationRole: string
{
    case OWNER = 'OWNER';
    case MEMBER = 'MEMBER';
    case ACCOUNTING = 'ACCOUNTING';
    case READ_ONLY = 'READ_ONLY';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Inhaber',
            self::MEMBER => 'Mitarbeiter',
            self::ACCOUNTING => 'Buchhaltung',
            self::READ_ONLY => 'Nur Lesezugriff',
        };
    }

    /**
     * Darf abrechnungsrelevante Daten aendern.
     */
    public function mayWrite(): bool
    {
        return $this !== self::READ_ONLY;
    }

    /**
     * Darf Zahlungen ausloesen und Rechnungsdaten verwalten.
     */
    public function mayManageBilling(): bool
    {
        return $this === self::OWNER || $this === self::ACCOUNTING;
    }
}
