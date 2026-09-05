<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status der Loeschung von Originaldateien, Seitenbildern und Providerdateien.
 *
 * FEHLGESCHLAGEN und UEBERFAELLIG sind im Adminbereich als kritischer
 * Datenschutzalarm anzuzeigen und erneut zu bearbeiten.
 */
enum DeletionStatus: string
{
    case OFFEN = 'OFFEN';
    case IN_ARBEIT = 'IN_ARBEIT';
    case ERFOLGREICH = 'ERFOLGREICH';
    case FEHLGESCHLAGEN = 'FEHLGESCHLAGEN';
    case UEBERFAELLIG = 'UEBERFAELLIG';
    case NICHT_ERFORDERLICH = 'NICHT_ERFORDERLICH';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::OFFEN => 'Löschung offen',
            self::IN_ARBEIT => 'Löschung läuft',
            self::ERFOLGREICH => 'Gelöscht',
            self::FEHLGESCHLAGEN => 'Löschung fehlgeschlagen',
            self::UEBERFAELLIG => 'Löschung überfällig',
            self::NICHT_ERFORDERLICH => 'Keine Löschung erforderlich',
        };
    }

    /**
     * Erfordert einen Datenschutzalarm im Adminbereich.
     */
    public function isPrivacyAlert(): bool
    {
        return match ($this) {
            self::FEHLGESCHLAGEN, self::UEBERFAELLIG => true,
            default => false,
        };
    }
}
