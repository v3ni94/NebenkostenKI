<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Sammelt Logeintraege, damit Tests nachweisen koennen, dass in Logs kein
 * Dokumentinhalt landet.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  array<string, mixed>  $context
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_scalar($level) ? (string) $level : 'unbekannt',
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * Alle Meldungen und Kontextwerte als eine durchsuchbare Zeichenkette.
     */
    public function dump(): string
    {
        $parts = [];

        foreach ($this->records as $record) {
            $parts[] = $record['level'].' '.$record['message'].' '
                .(string) json_encode($record['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $parts);
    }

    /**
     * @return list<string>
     */
    public function contextKeys(): array
    {
        $keys = [];

        foreach ($this->records as $record) {
            foreach (array_keys($record['context']) as $key) {
                $keys[] = (string) $key;
            }
        }

        return array_values(array_unique($keys));
    }

    public function count(): int
    {
        return count($this->records);
    }
}
