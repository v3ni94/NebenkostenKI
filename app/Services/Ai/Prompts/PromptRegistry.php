<?php

declare(strict_types=1);

namespace App\Services\Ai\Prompts;

use App\Services\Ai\Schemas\SchemaDefinition;

/**
 * Registry der versionierten Systemprompts.
 *
 * Der Sicherheitsbaustein wird einmal zentral uebergeben und in jeden Prompt
 * eingebaut. Damit ist technisch sichergestellt, dass kein Prompt der Schicht
 * ohne den Baustein aus Abschnitt 13.6 verschickt werden kann.
 *
 * Version und Hash jedes Prompts sind protokollierbar
 * (ai_prompt_versions, Abschnitt 10).
 */
final class PromptRegistry
{
    /** @var array<string, PromptDefinition> */
    private array $cache = [];

    public function __construct(
        private readonly string $securityPrompt,
    ) {}

    public function classification(SchemaDefinition $schema): PromptDefinition
    {
        return $this->cached(
            'klassifikation:'.$schema->key,
            fn (): PromptDefinition => (new DocumentClassificationPrompt($this->securityPrompt))->build($schema),
        );
    }

    public function extraction(SchemaDefinition $schema): PromptDefinition
    {
        return $this->cached(
            'extraktion:'.$schema->key,
            fn (): PromptDefinition => (new StructuredExtractionPrompt($this->securityPrompt, $schema->key))->build($schema),
        );
    }

    public function contractAnalysis(SchemaDefinition $schema): PromptDefinition
    {
        return $this->cached(
            'vertragsanalyse:'.$schema->key,
            fn (): PromptDefinition => (new ContractAnalysisPrompt($this->securityPrompt))->build($schema),
        );
    }

    public function priorStatementAnalysis(SchemaDefinition $schema): PromptDefinition
    {
        return $this->cached(
            'vorjahresanalyse:'.$schema->key,
            fn (): PromptDefinition => (new PriorStatementAnalysisPrompt($this->securityPrompt))->build($schema),
        );
    }

    public function reconciliation(SchemaDefinition $schema): PromptDefinition
    {
        return $this->cached(
            'reconciliation:'.$schema->key,
            fn (): PromptDefinition => (new ReconciliationPrompt($this->securityPrompt))->build($schema),
        );
    }

    /**
     * Der wirksame Sicherheitsbaustein, wie er in jeden Prompt eingebaut
     * wird. Fuer Tests und den Adminbereich.
     */
    public function securityBlock(): string
    {
        return (new DocumentClassificationPrompt($this->securityPrompt))->securityBlock();
    }

    /**
     * @param  callable(): PromptDefinition  $factory
     */
    private function cached(string $key, callable $factory): PromptDefinition
    {
        return $this->cache[$key] ??= $factory();
    }
}
