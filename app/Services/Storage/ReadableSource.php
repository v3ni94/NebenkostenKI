<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Eine pruefbare Datei, unabhaengig davon, ob sie als Klartextdatei (Tests,
 * lokale Werkzeuge) oder als Chiffrat im Kurzzeitbereich vorliegt.
 *
 * Die Pruefer (MimeGuard, PageCounter, ArchiveGuard, FingerprintFactory)
 * arbeiten ausschliesslich ueber diese Schnittstelle. Sie sehen niemals den
 * Pfad des Chiffrats und lesen den Klartext ausschliesslich als Strom.
 */
interface ReadableSource
{
    public function exists(): bool;

    /**
     * Klartextgroesse in Byte.
     */
    public function byteSize(): int;

    /**
     * Sequentieller Klartextstrom. Der Aufrufer schliesst ihn mit fclose().
     *
     * @return resource
     */
    public function openStream();

    /**
     * Stellt den Klartext fuer die Dauer des Aufrufs als lokale Datei bereit.
     *
     * AUSNAHME, nur fuer Bibliotheken, die zwingend einen Dateipfad brauchen
     * (ZipArchive). Fuer eine verschluesselte Quelle entsteht dabei kurzzeitig
     * eine Klartextkopie, die unmittelbar nach dem Aufruf ueberschrieben und
     * geloescht wird, siehe TemporaryUploadStorage::withDecryptedCopy().
     *
     * @template T
     *
     * @param  callable(string): T  $callback  erhaelt den absoluten Pfad
     * @return T
     */
    public function withLocalPath(callable $callback): mixed;
}
