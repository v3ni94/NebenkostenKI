<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Zweck einer protokollierten Zustimmung zu Rechtstexten oder Erklaerungen.
 */
enum LegalDocumentPurpose: string
{
    case AGB = 'AGB';
    case DATENSCHUTZ = 'DATENSCHUTZ';
    case WIDERRUF = 'WIDERRUF';
    case SOFORTIGE_VERTRAGSAUSFUEHRUNG = 'SOFORTIGE_VERTRAGSAUSFUEHRUNG';
    case ABRECHNUNGSVERANTWORTUNG = 'ABRECHNUNGSVERANTWORTUNG';
    case DATENVERARBEITUNG_KI = 'DATENVERARBEITUNG_KI';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::AGB => 'Allgemeine Geschäftsbedingungen',
            self::DATENSCHUTZ => 'Datenschutzerklärung',
            self::WIDERRUF => 'Widerrufsbelehrung',
            self::SOFORTIGE_VERTRAGSAUSFUEHRUNG => 'Sofortige Vertragsausführung',
            self::ABRECHNUNGSVERANTWORTUNG => 'Verantwortung für die Abrechnung',
            self::DATENVERARBEITUNG_KI => 'Verarbeitung durch KI-Dienstleister',
        };
    }
}
