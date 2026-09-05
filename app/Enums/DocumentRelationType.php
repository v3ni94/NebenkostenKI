<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Beziehung zwischen zwei Dokumenten eines Abrechnungslaufs.
 */
enum DocumentRelationType: string
{
    case DUBLETTE = 'DUBLETTE';
    case GUTSCHRIFT_ZU_RECHNUNG = 'GUTSCHRIFT_ZU_RECHNUNG';
    case ANLAGE_ZU_HAUPTDOKUMENT = 'ANLAGE_ZU_HAUPTDOKUMENT';
    case ERSETZT_DURCH = 'ERSETZT_DURCH';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::DUBLETTE => 'Mögliche Dublette',
            self::GUTSCHRIFT_ZU_RECHNUNG => 'Gutschrift zu Rechnung',
            self::ANLAGE_ZU_HAUPTDOKUMENT => 'Anlage zu Hauptdokument',
            self::ERSETZT_DURCH => 'Ersetzt durch',
        };
    }
}
