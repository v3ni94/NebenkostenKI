<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Schreibseite der Rueckfallebene, siehe LaravelCryptBlockCipher.
 */
final class LaravelCryptEncryptingWriter implements EncryptingWriter
{
    private string $buffer = '';

    private int $written = 0;

    private int $blockIndex = 0;

    private bool $closed = false;

    /**
     * @param  resource  $target
     */
    public function __construct(private $target, private readonly Encrypter $encrypter)
    {
        $this->emit((new CiphertextHeader(LaravelCryptBlockCipher::ID, 0))->encode());
    }

    public function write(string $plaintext): void
    {
        $this->assertOpen();

        $this->buffer .= $plaintext;

        while (strlen($this->buffer) > LaravelCryptBlockCipher::BLOCK_BYTES) {
            $block = substr($this->buffer, 0, LaravelCryptBlockCipher::BLOCK_BYTES);
            $this->buffer = substr($this->buffer, LaravelCryptBlockCipher::BLOCK_BYTES);

            $this->push($block, false);
        }
    }

    public function finish(): int
    {
        $this->assertOpen();

        $this->push($this->buffer, true);
        $this->buffer = '';

        if (fseek($this->target, 0) !== 0) {
            throw new RuntimeException('Der Vorspann des Chiffrats konnte nicht nachgetragen werden.');
        }

        $this->emit((new CiphertextHeader(LaravelCryptBlockCipher::ID, $this->written))->encode());

        $this->close();

        return $this->written;
    }

    public function abort(): void
    {
        if (! $this->closed) {
            $this->close();
        }
    }

    private function push(string $block, bool $final): void
    {
        $payload = pack('J', $this->blockIndex).pack('C', $final ? 1 : 0).$block;
        $ciphertext = $this->encrypter->encryptString($payload);

        $this->emit(pack('N', strlen($ciphertext)).$ciphertext);

        $this->blockIndex++;
        $this->written += strlen($block);
    }

    private function emit(string $bytes): void
    {
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

        $this->buffer = '';
    }
}
