<?php

declare(strict_types=1);

namespace App\Services\Pdf\Watermark;

/**
 * Einstellungen des Vorschauwasserzeichens (Abschnitt 14.3).
 *
 * Der Text stammt aus config('smartabrechnen.pdf.watermark_text'), der
 * zusätzliche Fußzeilenvermerk aus config('smartabrechnen.pdf.watermark_footer').
 * Die Werte werden nicht im Code dupliziert.
 */
final readonly class WatermarkSettings
{
    private function __construct(
        public bool $enabled,
        public string $text,
        public string $footerNote,
        public float $alpha,
        public int $angle,
        public int $sizePercent,
    ) {}

    /**
     * Vorschau: großes diagonales, halbtransparentes Wasserzeichen auf jeder
     * Seite, serverseitig eingebrannt.
     */
    public static function preview(): self
    {
        return new self(
            true,
            self::configString('watermark_text', 'VORSCHAU'),
            self::configString('watermark_footer', 'Unbezahlte Vorschau'),
            0.14,
            45,
            110,
        );
    }

    /**
     * Finalversion: kein Wasserzeichen. Die Finalversion wird vollständig neu
     * erzeugt, niemals durch Entfernen eines Wasserzeichens.
     */
    public static function none(): self
    {
        return new self(false, '', '', 0.0, 45, 110);
    }

    private static function configString(string $key, string $fallback): string
    {
        $value = config('smartabrechnen.pdf.'.$key);

        return is_string($value) && trim($value) !== '' ? $value : $fallback;
    }
}
