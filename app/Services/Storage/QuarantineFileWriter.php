<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Crypto\EncryptingWriter;
use Closure;

/**
 * Schreibvorgang auf eine Datei im Kurzzeitbereich.
 *
 * Kapselt den verschluesselnden Writer, schreibt in eine Zwischendatei und
 * macht das fertige Chiffrat erst mit finish() atomar unter dem Zielpfad
 * sichtbar. Bei Abbruch wird die Zwischendatei entfernt, damit kein
 * unlesbarer Rest liegen bleibt.
 */
final class QuarantineFileWriter
{
    private bool $done = false;

    /**
     * @param  Closure(): void  $discard  entfernt die Zwischendatei
     * @param  (Closure(): void)|null  $commit  verschiebt das fertige Chiffrat auf den Zielpfad
     */
    public function __construct(
        private readonly EncryptingWriter $writer,
        private readonly Closure $discard,
        private readonly ?Closure $commit = null,
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

        $written = $this->writer->finish();

        if ($this->commit !== null) {
            ($this->commit)();
        }

        return $written;
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
