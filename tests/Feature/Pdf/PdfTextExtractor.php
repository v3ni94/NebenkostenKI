<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

/**
 * Testhilfe: liest den sichtbaren Text und die Seitenzahl aus einem erzeugten
 * PDF.
 *
 * Die Anwendung rendert im UTF-8-Modus von mPDF mit eingebetteter
 * Unicode-Schrift. Textstrings liegen deshalb als UTF-16BE in den mit Flate
 * komprimierten Seiteninhalten. Der Extraktor dekomprimiert die Streams, löst
 * Oktalfolgen auf und wandelt nach UTF-8, damit Tests auf den Text prüfen
 * können. Zeichenketten ohne Nullbytes (Kernschriften, Metadaten) werden
 * weiterhin als Windows-1252 gelesen.
 */
final class PdfTextExtractor
{
    public static function text(string $pdf): string
    {
        $streams = self::streams($pdf);
        $text = '';

        foreach ($streams as $stream) {
            $text .= self::stringsOf($stream)."\n";
        }

        return $text;
    }

    /**
     * Anzahl der Seiten anhand der Seitenobjekte.
     */
    public static function pageCount(string $pdf): int
    {
        preg_match_all('#/Type\s*/Page(?![s/])#', $pdf, $matches);

        return count($matches[0]);
    }

    /**
     * Anzahl der Vorkommen eines Textes über alle Seiteninhalte hinweg.
     */
    public static function occurrences(string $pdf, string $needle): int
    {
        return substr_count(self::text($pdf), $needle);
    }

    /**
     * Dekomprimierte Inhaltsströme des Dokuments.
     *
     * @return list<string>
     */
    private static function streams(string $pdf): array
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $matches);

        $streams = [];

        foreach ($matches[1] as $raw) {
            $streams[] = self::inflate($raw);
        }

        return $streams;
    }

    /**
     * Dekomprimiert einen Inhaltsstrom. Die abschließende Zeilenschaltung vor
     * "endstream" gehört nicht zu den komprimierten Daten, ist aber nicht
     * eindeutig abgrenzbar; deshalb werden die möglichen Varianten geprüft.
     */
    private static function inflate(string $raw): string
    {
        foreach ([$raw, rtrim($raw, "\r\n"), substr($raw, 0, -1), substr($raw, 0, -2)] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $decoded = @gzuncompress($candidate);

            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $raw;
    }

    private static function stringsOf(string $stream): string
    {
        preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $stream, $matches);

        $parts = [];

        foreach ($matches[0] as $literal) {
            $parts[] = self::decode(substr($literal, 1, -1));
        }

        return implode(' ', $parts);
    }

    private static function decode(string $literal): string
    {
        $withoutOctal = preg_replace_callback(
            '/\\\\([0-7]{1,3})/',
            static fn (array $match): string => chr((int) octdec($match[1])),
            $literal
        );

        $decoded = is_string($withoutOctal) ? $withoutOctal : $literal;

        $decoded = str_replace(
            ['\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'],
            ["\n", "\r", "\t", '(', ')', '\\'],
            $decoded
        );

        $quelle = strlen($decoded) % 2 === 0 && str_contains($decoded, "\0") ? 'UTF-16BE' : 'Windows-1252';
        $utf8 = mb_convert_encoding($decoded, 'UTF-8', $quelle);

        return is_string($utf8) ? $utf8 : $decoded;
    }
}
