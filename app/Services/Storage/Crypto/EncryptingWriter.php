<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

/**
 * Schreibt Klartext blockweise als Chiffrat in einen Zielstrom.
 *
 * Der Klartext wird intern bis zur Blockgroesse gepuffert und dann
 * verschluesselt ausgegeben. Eine 25-MB-Datei belegt damit zu keinem
 * Zeitpunkt mehr als etwa zwei Bloecke Arbeitsspeicher.
 */
interface EncryptingWriter
{
    public function write(string $plaintext): void;

    /**
     * Schreibt den Endblock, traegt die Klartextlaenge in den Vorspann ein
     * und schliesst den Zielstrom.
     *
     * @return int Anzahl der geschriebenen Klartextbytes
     */
    public function finish(): int;

    /**
     * Verwirft den Vorgang und schliesst den Zielstrom, ohne einen Endblock
     * zu schreiben. Die Datei bleibt unvollstaendig und ist nicht lesbar.
     */
    public function abort(): void;
}
