<?php

declare(strict_types=1);

namespace App\Services\Storage;

use ZipArchive;

/**
 * Bestimmt die Seitenzahl beziehungsweise die Blattzahl einer Datei.
 *
 * Abschnitt 6.3 Schritt 6 verlangt Seitenzahl und die unbedingt erforderlichen
 * technischen Metadaten, mehr nicht.
 *
 * DATENSCHUTZ: Es wird kein Text extrahiert, kein Seitenbild erzeugt und kein
 * Inhalt zurueckgegeben. Ist die Seitenzahl nicht sicher bestimmbar, bleibt sie
 * null. Es wird niemals geschaetzt (Grundsatz 5).
 *
 * Der Klartext wird als Strom gelesen. Einzige Ausnahme ist die XLSX-Zaehlung:
 * ZipArchive verlangt einen Dateipfad, siehe ReadableSource::withLocalPath().
 */
final class PageCounter
{
    /**
     * Gelesen wird hoechstens dieser Umfang, damit ein grosses PDF den
     * Speicher eines IONOS-Prozesses nicht sprengt.
     */
    private const MAX_SCAN_BYTES = 8 * 1024 * 1024;

    private const READ_BLOCK_BYTES = 1024 * 1024;

    public function count(string $absolutePath, FileCategory $category, string $mimeType): ?int
    {
        return $this->countSource(new PlainFileSource($absolutePath), $category, $mimeType);
    }

    public function countSource(ReadableSource $source, FileCategory $category, string $mimeType): ?int
    {
        if (! $source->exists()) {
            return null;
        }

        return match ($category) {
            FileCategory::PDF => $this->countPdfPages($source),
            FileCategory::BILD, FileCategory::HEIC => 1,
            FileCategory::TABELLE => $mimeType === 'text/csv'
                ? 1
                : $source->withLocalPath(fn (string $path): ?int => $this->countWorksheets($path)),
            FileCategory::ARCHIV => null,
        };
    }

    /**
     * Zaehlt Seitenobjekte im PDF. Kann die Zahl nicht sicher bestimmt werden,
     * wird null zurueckgegeben und in validation_issues eine Pruefaufgabe
     * erzeugt, statt einen Wert zu erfinden.
     */
    private function countPdfPages(ReadableSource $source): ?int
    {
        $contents = $this->readHead($source, self::MAX_SCAN_BYTES);

        if ($contents === '') {
            return null;
        }

        $pageObjects = preg_match_all('#/Type\s*/Page[^s]#', $contents);

        if (is_int($pageObjects) && $pageObjects > 0) {
            return $pageObjects;
        }

        // Alternativ traegt der Seitenbaum die Gesamtzahl. Der groesste Wert
        // ist die Wurzel des Baums.
        $matches = [];

        preg_match_all('#/Count\s+(\d+)#', $contents, $matches);

        $counts = array_filter(
            array_map('intval', $matches[1]),
            static fn (int $value): bool => $value > 0
        );

        return $counts === [] ? null : max($counts);
    }

    /**
     * Liest hoechstens $maxBytes vom Anfang des Klartextstroms.
     */
    private function readHead(ReadableSource $source, int $maxBytes): string
    {
        $stream = $source->openStream();
        $contents = '';

        try {
            while (strlen($contents) < $maxBytes && ! feof($stream)) {
                $block = fread($stream, min(self::READ_BLOCK_BYTES, $maxBytes - strlen($contents)));

                if ($block === false || $block === '') {
                    break;
                }

                $contents .= $block;
            }
        } finally {
            fclose($stream);
        }

        return $contents;
    }

    /**
     * Zaehlt die Arbeitsblaetter einer XLSX-Datei ueber das ZIP-Verzeichnis.
     * Die Datei wird dabei nicht entpackt und nicht gelesen.
     */
    private function countWorksheets(string $absolutePath): ?int
    {
        $archive = new ZipArchive;

        if ($archive->open($absolutePath, ZipArchive::RDONLY) !== true) {
            return null;
        }

        $sheets = 0;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);

            if (is_string($name) && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1) {
                $sheets++;
            }
        }

        $archive->close();

        return $sheets > 0 ? $sheets : null;
    }
}
