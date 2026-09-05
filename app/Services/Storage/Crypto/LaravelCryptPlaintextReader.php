<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * Leseseite der Rueckfallebene, siehe LaravelCryptBlockCipher.
 */
final class LaravelCryptPlaintextReader implements PlaintextReader
{
    private string $buffer = '';

    private int $delivered = 0;

    private int $expectedIndex = 0;

    private bool $finalSeen = false;

    private bool $closed = false;

    private readonly CiphertextHeader $header;

    /**
     * @param  resource  $ciphertext
     *
     * @throws CipherIntegrityException
     */
    public function __construct(private $ciphertext, private readonly Encrypter $encrypter)
    {
        $this->header = CiphertextHeader::read($ciphertext);

        if ($this->header->cipherId !== LaravelCryptBlockCipher::ID) {
            throw CipherIntegrityException::unsupportedCipher($this->header->cipherId);
        }
    }

    public function read(int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        while (strlen($this->buffer) < $maxBytes && ! $this->finalSeen) {
            $this->pullBlock();
        }

        $out = substr($this->buffer, 0, $maxBytes);
        $this->buffer = substr($this->buffer, strlen($out));
        $this->delivered += strlen($out);

        return $out;
    }

    public function eof(): bool
    {
        return $this->finalSeen && $this->buffer === '';
    }

    public function size(): int
    {
        return $this->header->plaintextLength;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if (is_resource($this->ciphertext)) {
            fclose($this->ciphertext);
        }

        $this->buffer = '';
    }

    /**
     * @throws CipherIntegrityException
     */
    private function pullBlock(): void
    {
        $lengthBytes = $this->readExactly(4);

        if (strlen($lengthBytes) !== 4) {
            throw CipherIntegrityException::truncated();
        }

        $length = unpack('N', $lengthBytes);

        if ($length === false) {
            throw CipherIntegrityException::truncated();
        }

        $block = $this->readExactly((int) $length[1]);

        if (strlen($block) !== (int) $length[1]) {
            throw CipherIntegrityException::truncated();
        }

        try {
            $payload = $this->encrypter->decryptString($block);
        } catch (DecryptException) {
            throw CipherIntegrityException::authenticationFailed();
        }

        if (strlen($payload) < 9) {
            throw CipherIntegrityException::authenticationFailed();
        }

        $index = unpack('J', $payload, 0);
        $final = unpack('C', $payload, 8);

        if ($index === false || $final === false || (int) $index[1] !== $this->expectedIndex) {
            throw CipherIntegrityException::authenticationFailed();
        }

        $this->expectedIndex++;
        $this->buffer .= substr($payload, 9);

        if ((int) $final[1] === 1) {
            $this->finalSeen = true;

            if ($this->readExactly(1) !== '') {
                throw CipherIntegrityException::trailingData();
            }

            if ($this->delivered + strlen($this->buffer) !== $this->header->plaintextLength) {
                throw CipherIntegrityException::lengthMismatch();
            }
        }
    }

    private function readExactly(int $bytes): string
    {
        $out = '';

        while (strlen($out) < $bytes && ! feof($this->ciphertext)) {
            $chunk = fread($this->ciphertext, $bytes - strlen($out));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $out .= $chunk;
        }

        return $out;
    }
}
