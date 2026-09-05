<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Status eines Ergebnisses der KI-Schicht.
 */
enum AiResultStatus: string
{
    /** Antwort war schemakonform und wurde serverseitig validiert. */
    case VALIDIERT = 'VALIDIERT';

    /**
     * Antwort war nach allen zulaessigen Reparaturversuchen nicht
     * schemakonform. Der Aufrufer bietet die manuelle Erfassung an.
     */
    case FEHLGESCHLAGEN = 'FEHLGESCHLAGEN';

    /**
     * Dual Review hat fachlich widersprechende Ergebnisse geliefert. Es wird
     * kein Mehrheitsentscheid getroffen, der Widerspruch geht an den
     * Aufrufer.
     */
    case KONFLIKT = 'KONFLIKT';

    public function label(): string
    {
        return match ($this) {
            self::VALIDIERT => 'Validiert',
            self::FEHLGESCHLAGEN => 'Nicht schemakonform, manuelle Erfassung erforderlich',
            self::KONFLIKT => 'Widerspruch zwischen zwei Providern',
        };
    }

    public function requiresManualEntry(): bool
    {
        return $this === self::FEHLGESCHLAGEN;
    }
}
