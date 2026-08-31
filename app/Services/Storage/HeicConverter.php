<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Exceptions\UploadRejectedException;
use Throwable;

/**
 * Sichere serverseitige Umwandlung von HEIC nach JPEG (Abschnitt 6.1).
 *
 * ENTSCHEIDUNG: Es wird ausschliesslich Imagick mit HEIC-Delegate verwendet.
 * GD kann HEIC nicht lesen, und ein externes Kommandozeilenwerkzeug wird
 * bewusst nicht aufgerufen, weil auf IONOS Webhosting weder ein Binary noch
 * proc_open zugesichert sind und ein Shellaufruf mit Nutzerdaten eine
 * zusaetzliche Angriffsflaeche waere.
 *
 * Ist Imagick nicht verfuegbar, wird die Datei NICHT still abgelehnt. Der
 * Nutzer erhaelt eine klare deutsche Handlungsanweisung, wie er das Foto auf
 * seinem Geraet als JPG speichert (UploadErrorCode::HEIC_KONVERTER_FEHLT).
 *
 * DATENSCHUTZ: Die Konvertierung schreibt ausschliesslich in den
 * Kurzzeitbereich unter dem Praefix des Uploads und wird gemeinsam mit dem
 * Original geloescht. EXIF-Daten werden dabei ausdruecklich entfernt und nicht
 * weitergegeben (Abschnitt 6.4).
 */
final class HeicConverter
{
    private const IMAGICK_CLASS = 'Imagick';

    public function isAvailable(): bool
    {
        if (! class_exists(self::IMAGICK_CLASS)) {
            return false;
        }

        if (! is_callable([self::IMAGICK_CLASS, 'queryFormats'])) {
            return false;
        }

        try {
            $formats = call_user_func([self::IMAGICK_CLASS, 'queryFormats'], 'HEI*');
        } catch (Throwable) {
            return false;
        }

        return is_array($formats) && $formats !== [];
    }

    /**
     * Deutsche Fallback-Anweisung, wenn keine Umwandlung moeglich ist.
     */
    public function fallbackInstruction(): string
    {
        return UploadErrorCode::HEIC_KONVERTER_FEHLT->message();
    }

    /**
     * Wandelt eine HEIC-Datei in ein JPEG um und gibt die Groesse des
     * Ergebnisses zurueck.
     *
     * @throws UploadRejectedException wenn kein Konverter vorhanden ist oder
     *                                 die Umwandlung fehlschlaegt
     */
    public function convertToJpeg(string $sourceAbsolutePath, string $targetAbsolutePath): int
    {
        if (! $this->isAvailable()) {
            throw UploadRejectedException::because(UploadErrorCode::HEIC_KONVERTER_FEHLT);
        }

        try {
            $imagick = $this->createImagick($sourceAbsolutePath);

            // EXIF, GPS und Profile werden entfernt, bevor das Bild die
            // Verarbeitung erreicht.
            call_user_func([$imagick, 'stripImage']);
            call_user_func([$imagick, 'setImageFormat'], 'jpeg');
            call_user_func([$imagick, 'setImageCompressionQuality'], 88);
            call_user_func([$imagick, 'writeImage'], $targetAbsolutePath);
            call_user_func([$imagick, 'clear']);
        } catch (Throwable) {
            throw UploadRejectedException::because(UploadErrorCode::STRUKTUR_UNGUELTIG);
        }

        if (! is_file($targetAbsolutePath)) {
            throw UploadRejectedException::because(UploadErrorCode::STRUKTUR_UNGUELTIG);
        }

        return (int) filesize($targetAbsolutePath);
    }

    /**
     * Erzeugt die Imagick-Instanz ueber den Klassennamen, damit die
     * Anwendung ohne die Erweiterung analysierbar und lauffaehig bleibt.
     */
    private function createImagick(string $sourceAbsolutePath): object
    {
        /** @var class-string $class */
        $class = self::IMAGICK_CLASS;

        return new $class($sourceAbsolutePath);
    }
}
