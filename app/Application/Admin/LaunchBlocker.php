<?php

declare(strict_types=1);

namespace App\Application\Admin;

/**
 * Ein einzelner Livegang-Blocker (Masterprompt 26).
 *
 * VERBINDLICH: Ein Blocker beschreibt ausschliesslich den tatsaechlich
 * festgestellten Zustand. Es wird keine Angabe erfunden und keine Freigabe
 * behauptet. Jeder Blocker nennt drei Dinge, damit er ohne Rueckfrage
 * bearbeitbar ist:
 *
 *   1. was fehlt          $missing
 *   2. welche Folge das hat  $consequence
 *   3. wer es bereitstellt   $responsible
 */
final readonly class LaunchBlocker
{
    public const string SCHWERE_BLOCKIEREND = 'blockierend';

    public const string SCHWERE_ENTSCHEIDUNG = 'entscheidung';

    public function __construct(
        public string $key,
        public string $area,
        public string $missing,
        public string $consequence,
        public string $responsible,
        public string $severity = self::SCHWERE_BLOCKIEREND,
    ) {}

    public function isBlocking(): bool
    {
        return $this->severity === self::SCHWERE_BLOCKIEREND;
    }
}
