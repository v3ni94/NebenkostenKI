<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Verarbeitungszustand eines Dokuments nach Abschnitt 6.3.
 *
 * Der Zustand beschreibt ausschliesslich die Verarbeitung der strukturierten
 * Daten. Die Originaldatei wird unabhaengig davon nach Abschluss oder
 * endgueltigem Fehler sofort geloescht.
 */
enum DocumentProcessingStatus: string
{
    case HOCHGELADEN = 'HOCHGELADEN';
    case SICHERHEITSPRUEFUNG = 'SICHERHEITSPRUEFUNG';
    case KLASSIFIZIERUNG = 'KLASSIFIZIERUNG';
    case EXTRAKTION = 'EXTRAKTION';
    case VALIDIERUNG = 'VALIDIERUNG';
    case ABGESCHLOSSEN = 'ABGESCHLOSSEN';
    case FEHLGESCHLAGEN = 'FEHLGESCHLAGEN';
    case ABGELEHNT = 'ABGELEHNT';
    case ABGEBROCHEN = 'ABGEBROCHEN';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::HOCHGELADEN => 'Hochgeladen',
            self::SICHERHEITSPRUEFUNG => 'Sicherheitsprüfung',
            self::KLASSIFIZIERUNG => 'Klassifizierung',
            self::EXTRAKTION => 'Datenauslesung',
            self::VALIDIERUNG => 'Validierung',
            self::ABGESCHLOSSEN => 'Auswertung abgeschlossen',
            self::FEHLGESCHLAGEN => 'Auswertung fehlgeschlagen',
            self::ABGELEHNT => 'Abgelehnt',
            self::ABGEBROCHEN => 'Abgebrochen',
        };
    }

    /**
     * Nach einem Endzustand darf keine Originaldatei mehr vorhanden sein.
     */
    public function requiresSourceDeletion(): bool
    {
        return match ($this) {
            self::ABGESCHLOSSEN, self::FEHLGESCHLAGEN,
            self::ABGELEHNT, self::ABGEBROCHEN => true,
            default => false,
        };
    }
}
