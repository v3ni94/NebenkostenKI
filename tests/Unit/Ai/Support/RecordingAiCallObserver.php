<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\AiCallObserver;
use App\Services\Ai\Dto\AiCallMetadata;
use App\Services\Ai\Dto\ProviderFileDeletionOutcome;

/**
 * Zeichnet die Meldungen eines KI-Aufrufs auf, damit Tests Heartbeat,
 * Providerdatei und Abbruch nachweisen koennen.
 */
final class RecordingAiCallObserver implements AiCallObserver
{
    public int $heartbeats = 0;

    /** @var list<array{provider: string, fileId: string}> */
    public array $created = [];

    /** @var list<array{provider: string, fileId: string, outcome: ProviderFileDeletionOutcome}> */
    public array $released = [];

    /** @var list<AiCallMetadata> */
    public array $aborted = [];

    public bool $allowProviderFile = true;

    public function beforeProviderRequest(string $providerKey): void
    {
        $this->heartbeats++;
    }

    public function mayCreateProviderFile(string $providerKey): bool
    {
        return $this->allowProviderFile;
    }

    public function providerFileCreated(string $providerKey, string $providerFileId): void
    {
        $this->created[] = ['provider' => $providerKey, 'fileId' => $providerFileId];
    }

    public function providerFileReleased(string $providerKey, string $providerFileId, ProviderFileDeletionOutcome $outcome): void
    {
        $this->released[] = ['provider' => $providerKey, 'fileId' => $providerFileId, 'outcome' => $outcome];
    }

    public function providerCallAborted(AiCallMetadata $metadata): void
    {
        $this->aborted[] = $metadata;
    }
}
