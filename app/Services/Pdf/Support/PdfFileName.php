<?php

declare(strict_types=1);

namespace App\Services\Pdf\Support;

use Illuminate\Support\Str;

/**
 * Neutrale, vom System vergebene Dateinamen.
 *
 * Es wird niemals ein Originaldateiname übernommen. Umlaute werden ersetzt,
 * Sonderzeichen entfernt; der Name bleibt für den Nutzer sprechend.
 */
final class PdfFileName
{
    public static function build(string $prefix, string ...$parts): string
    {
        $segments = [$prefix, ...$parts];
        $clean = [];

        foreach ($segments as $segment) {
            $slug = Str::slug($segment, '-', 'de');

            if ($slug !== '') {
                $clean[] = $slug;
            }
        }

        $name = implode('-', $clean);

        return ($name === '' ? 'dokument' : $name).'.pdf';
    }

    public static function zip(string $prefix, string ...$parts): string
    {
        return Str::replaceLast('.pdf', '.zip', self::build($prefix, ...$parts));
    }
}
