<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Zweck eines KI-Aufrufs. Steuert Modellwahl und Kostenkontrolle.
 */
enum AiCallPurpose: string
{
    case KLASSIFIKATION = 'KLASSIFIKATION';
    case EXTRAKTION = 'EXTRAKTION';
    case VERTRAGSANALYSE = 'VERTRAGSANALYSE';
    case VORJAHRESANALYSE = 'VORJAHRESANALYSE';
    case RECONCILIATION = 'RECONCILIATION';
    case HEALTHCHECK = 'HEALTHCHECK';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::KLASSIFIKATION => 'Dokumentklassifikation',
            self::EXTRAKTION => 'Strukturierte Extraktion',
            self::VERTRAGSANALYSE => 'Vertragsanalyse',
            self::VORJAHRESANALYSE => 'Analyse der Vorjahresabrechnung',
            self::RECONCILIATION => 'Abgleich mehrerer Dokumente',
            self::HEALTHCHECK => 'Verfügbarkeitsprüfung',
        };
    }
}
