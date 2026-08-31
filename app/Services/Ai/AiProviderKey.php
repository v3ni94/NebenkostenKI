<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiProvider;

/**
 * Providerschluessel der KI-Schicht.
 *
 * Die Werte entsprechen den Konfigurationsschluesseln aus config/ai.php und
 * den zulaessigen Werten von AI_PRIMARY_PROVIDER und AI_FALLBACK_PROVIDER.
 *
 * FAKE ist ein eigener Schluessel und kein Provider im Sinne des Enums
 * App\Enums\AiProvider, weil er keinen Netzwerkaufruf ausfuehrt und in
 * ai_calls nicht als externe Verarbeitung erscheint. toAiProviderEnum()
 * liefert dafuer null.
 */
enum AiProviderKey: string
{
    case OPENAI = 'openai';
    case ANTHROPIC = 'anthropic';
    case FAKE = 'fake';

    public static function tryFromKey(?string $key): ?self
    {
        if ($key === null || $key === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($key)));
    }

    /**
     * Fuehrt der Schluessel zu einer Uebertragung an einen externen Provider?
     */
    public function isExternal(): bool
    {
        return $this !== self::FAKE;
    }

    public function toAiProviderEnum(): ?AiProvider
    {
        return match ($this) {
            self::OPENAI => AiProvider::OPENAI,
            self::ANTHROPIC => AiProvider::ANTHROPIC,
            self::FAKE => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OPENAI => 'OpenAI',
            self::ANTHROPIC => 'Anthropic',
            self::FAKE => 'Testprovider ohne Netzwerkaufruf',
        };
    }
}
