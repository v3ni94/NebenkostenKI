<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

/**
 * Leseseite des libsodium-Verfahrens, siehe SodiumSecretstreamCipher.
 */
final class SodiumPlaintextReader implements PlaintextReader
{
    private string $state;

    private string $buffer = '';

    private int $delivered = 0;

    private bool $finalSeen = false;

    private bool $closed = false;

    private readonly CiphertextHeader $header;

    /**
     * @param  resource  $ciphertext
     *
     * @throws CipherIntegrityException
     */
    public function __construct(private $ciphertext, string $fileKey)
    {
        $this->header = CiphertextHeader::read($ciphertext);

        if ($this->header->cipherId !== SodiumSecretstreamCipher::ID) {
            throw CipherIntegrityException::unsupportedCipher($this->header->cipherId);
        }

        $streamHeader = $this->readExactly(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

        if (strlen($streamHeader) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw CipherIntegrityException::truncated();
        }

        $this->state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $fileKey);
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

        $state = $this->state;
        $buffer = $this->buffer;
        sodium_memzero($state);
        sodium_memzero($buffer);

        $this->state = '';
        $this->buffer = '';
    }

    /**
     * @throws CipherIntegrityException
     */
    private function pullBlock(): void
    {
        $block = $this->readExactly(
            SodiumSecretstreamCipher::BLOCK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
        );

        if (strlen($block) < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
            throw CipherIntegrityException::truncated();
        }

        $result = sodium_crypto_secretstream_xchacha20poly1305_pull(
            $this->state,
            $block,
            $this->header->additionalData(),
        );

        if ($result === false) {
            throw CipherIntegrityException::authenticationFailed();
        }

        [$plaintext, $tag] = $result;

        $this->buffer .= $plaintext;

        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            $this->finalSeen = true;

            // Nach dem Endblock darf nichts mehr folgen.
            if ($this->readExactly(1) !== '') {
                throw CipherIntegrityException::trailingData();
            }

            if ($this->delivered + strlen($this->buffer) !== $this->header->plaintextLength) {
                throw CipherIntegrityException::lengthMismatch();
            }
        }
    }

    /**
     * Liest bis zu $bytes Byte und gibt bei Dateiende weniger zurueck.
     */
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
