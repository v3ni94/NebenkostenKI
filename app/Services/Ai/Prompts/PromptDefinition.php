<?php

declare(strict_types=1);

namespace App\Services\Ai\Prompts;

use App\Enums\AiCallPurpose;

/**
 * Ein versionierter Systemprompt mit stabilem Hash.
 *
 * Version und Hash werden zu jedem KI-Aufruf protokolliert
 * (ai_prompt_versions, Abschnitt 10). Ein Promptwechsel ohne
 * Versionsanhebung ist nicht zulaessig, weil eine bezahlte Abrechnung
 * reproduzierbar bleiben muss.
 */
final class PromptDefinition
{
    private ?string $hash = null;

    public function __construct(
        public readonly AiCallPurpose $purpose,
        public readonly string $version,
        public readonly string $systemPrompt,
        public readonly string $userInstruction = '',
    ) {}

    public function hash(): string
    {
        return $this->hash ??= hash('sha256', $this->purpose->value.'|'.$this->version.'|'.$this->systemPrompt);
    }

    public function shortHash(): string
    {
        return substr($this->hash(), 0, 12);
    }
}
