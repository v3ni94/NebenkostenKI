<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Enums\AiCallPurpose;
use App\Services\Ai\Dto\AiRequestContext;
use App\Services\Ai\Dto\DocumentPayload;
use App\Services\Ai\Prompts\PromptDefinition;
use App\Services\Ai\Schemas\SchemaDefinition;
use LogicException;
use SensitiveParameter;

/**
 * Alles, was ein Provider fuer einen schemagebundenen Aufruf braucht.
 *
 * Entweder ein Dokument oder ein Textinput ist gesetzt. Der Textinput wird
 * ausschliesslich fuer den Abgleich verwendet und enthaelt bereits validierte
 * strukturierte Extraktionsdaten, keine Originaldatei.
 */
final class SchemaCallPlan
{
    public function __construct(
        public readonly AiCallPurpose $purpose,
        public readonly string $model,
        public readonly PromptDefinition $prompt,
        public readonly SchemaDefinition $schema,
        public readonly AiRequestContext $context,
        public readonly ?DocumentPayload $document = null,
        #[SensitiveParameter]
        private readonly ?string $textInput = null,
    ) {}

    public function textInput(): ?string
    {
        return $this->textInput;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function __debugInfo(): array
    {
        return [
            'purpose' => $this->purpose->value,
            'model' => $this->model,
            'promptVersion' => $this->prompt->version,
            'schemaKey' => $this->schema->key,
            'documentLabel' => $this->document?->neutralLabel,
            'textInput' => $this->textInput === null ? null : '[redigiert]',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'SchemaCallPlan darf nicht serialisiert werden. Er fuehrt Dokumentinhalte mit.'
        );
    }
}
