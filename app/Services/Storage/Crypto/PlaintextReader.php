<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

/**
 * Liest Klartext blockweise aus einem Chiffratstrom.
 *
 * Der Leser ist strikt sequentiell. Jeder Block wird beim Lesen
 * authentifiziert; ein manipulierter, abgeschnittener oder verlaengerter Strom
 * fuehrt zu einer CipherIntegrityException statt zu Muelldaten.
 */
interface PlaintextReader
{
    /**
     * @return string bis zu $maxBytes Klartext, leer am Ende des Stroms
     *
     * @throws CipherIntegrityException
     */
    public function read(int $maxBytes): string;

    public function eof(): bool;

    /**
     * Klartextlaenge laut Vorspann.
     */
    public function size(): int;

    public function close(): void;
}
