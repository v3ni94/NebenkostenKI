<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Crypto\EncryptingWriter;
use Closure;

/**
 * Schreibvorgang auf eine Datei im Kurzzeitbereich.
 *
 * Kapselt den verschluesselnden Writer und entfernt bei Abbruch die
 * unvollstaendige Zieldatei, damit kein unlesbarer Rest liegen bleibt.
 */
final class QuarantineFileWriter
{
    private bool $done = false;

    public function __construct(
        private readonly EncryptingWriter $writer,
        private readonly Closure $discard,
    ) {}

    public function write(string $plaintext): void
    {
        $this->writer->write($plaintext);
    }

    /**
     * @return int geschriebene Klartextbytes
     */
    public function finish(): int
    {
        $this->done = true;

        return $this->writer->finish();
    }

    /**
     * Verwirft den Vorgang und loescht die Zieldatei.
     */
    public function abort(): void
    {
        if ($this->done) {
            return;
        }

        $this->done = true;
        $this->writer->abort();
        ($this->discard)();
    }
}
