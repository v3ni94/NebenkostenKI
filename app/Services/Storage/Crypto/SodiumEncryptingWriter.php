<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

use RuntimeException;

/**
 * Schreibseite des libsodium-Verfahrens, siehe SodiumSecretstreamCipher.
 */
final class SodiumEncryptingWriter implements EncryptingWriter
{
    private string $state;

    private string $buffer = '';

    private int $written = 0;

    private bool $closed = false;

    private readonly CiphertextHeader $header;

    /**
     * @param  resource  $target
     */
    public function __construct(private $target, string $fileKey)
    {
        [$this->state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);

        $this->header = new CiphertextHeader(SodiumSecretstreamCipher::ID, 0);

        $this->emit($this->header->encode());
        $this->emit($streamHeader);
    }

    public function write(string $plaintext): void
    {
        $this->assertOpen();

        $this->buffer .= $plaintext;

        // Es wird nur ausgegeben, solange nach dem Block noch Daten bleiben.
        // So ist garantiert, dass der Endblock beim Abschluss die restlichen
        // Bytes traegt und niemals ein voller Block als Endblock fehlt.
        while (strlen($this->buffer) > SodiumSecretstreamCipher::BLOCK_BYTES) {
            $block = substr($this->buffer, 0, SodiumSecretstreamCipher::BLOCK_BYTES);
            $this->buffer = substr($this->buffer, SodiumSecretstreamCipher::BLOCK_BYTES);

            $this->push($block, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
        }
    }

    public function finish(): int
    {
        $this->assertOpen();

        $this->push($this->buffer, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        $this->buffer = '';

        $header = new CiphertextHeader(SodiumSecretstreamCipher::ID, $this->written);

        if (fseek($this->target, 0) !== 0) {
            throw new RuntimeException('Der Vorspann des Chiffrats konnte nicht nachgetragen werden.');
        }

        $this->emit($header->encode());

        $this->close();

        return $this->written;
    }

    public function abort(): void
    {
        if (! $this->closed) {
            $this->close();
        }
    }

    private function push(string $block, int $tag): void
    {
        $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
            $this->state,
            $block,
            $this->header->additionalData(),
            $tag,
        );

        $this->emit($ciphertext);

        $this->written += strlen($block);
    }

    private function emit(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        $result = fwrite($this->target, $bytes);

        if ($result !== strlen($bytes)) {
            throw new RuntimeException('Das Chiffrat konnte nicht vollstaendig geschrieben werden.');
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new RuntimeException('Der Schreibvorgang ist bereits abgeschlossen.');
        }
    }

    private function close(): void
    {
        $this->closed = true;

        fflush($this->target);
        fclose($this->target);

        // Schluesselzustand und Restpuffer werden ueberschrieben, bevor die
        // Referenzen freigegeben werden.
        $state = $this->state;
        $buffer = $this->buffer;
        sodium_memzero($state);
        sodium_memzero($buffer);

        $this->state = '';
        $this->buffer = '';
    }
}
