<?php

declare(strict_types=1);

namespace App\Application\Documents\Dto;

/**
 * Anlass einer Quelldatenloeschung nach Abschnitt 6.3 Schritte 15 bis 17.
 */
enum DeletionReason: string
{
    case EXTRAKTION_ABGESCHLOSSEN = 'EXTRAKTION_ABGESCHLOSSEN';
    case ENDGUELTIGER_FEHLER = 'ENDGUELTIGER_FEHLER';
    case TTL_ABGELAUFEN = 'TTL_ABGELAUFEN';
    case ABGEBROCHEN_DURCH_NUTZER = 'ABGEBROCHEN_DURCH_NUTZER';
    case WIEDERHOLUNG = 'WIEDERHOLUNG';

    public function label(): string
    {
        return match ($this) {
            self::EXTRAKTION_ABGESCHLOSSEN => 'Auswertung abgeschlossen',
            self::ENDGUELTIGER_FEHLER => 'Endgültiger Fehler',
            self::TTL_ABGELAUFEN => 'Aufbewahrungsfrist abgelaufen',
            self::ABGEBROCHEN_DURCH_NUTZER => 'Vom Nutzer abgebrochen',
            self::WIEDERHOLUNG => 'Erneuter Löschversuch',
        };
    }
}
