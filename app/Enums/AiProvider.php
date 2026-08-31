<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Providerabstraktion der KI-Anbindung. Auswahl erfolgt ueber ENV.
 */
enum AiProvider: string
{
    case OPENAI = 'OPENAI';
    case ANTHROPIC = 'ANTHROPIC';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::OPENAI => 'OpenAI',
            self::ANTHROPIC => 'Anthropic',
        };
    }
}
