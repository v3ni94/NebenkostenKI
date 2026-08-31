<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use RuntimeException;
use ZipArchive;

/**
 * Erzeugt technisch gueltige Beispieldateien fuer die Pruefkette.
 *
 * VERBINDLICH: Die Inhalte sind frei erfunden und enthalten keine echten
 * Personen, Anschriften, Aktenzeichen oder Bankverbindungen. Es werden keine
 * echten Belege in die Testsuite gelegt.
 */
final class SampleFiles
{
    /**
     * Minimales, strukturell gueltiges PDF mit der gewuenschten Seitenzahl.
     */
    public static function pdf(int $pages = 1): string
    {
        $objects = "%PDF-1.4\n"
            ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            .'2 0 obj<</Type/Pages/Count '.$pages.'/Kids[';

        for ($page = 0; $page < $pages; $page++) {
            $objects .= ($page + 3).' 0 R ';
        }

        $objects .= "]>>endobj\n";

        for ($page = 0; $page < $pages; $page++) {
            $objects .= ($page + 3)." 0 obj<</Type/Page /Parent 2 0 R>>endobj\n";
        }

        return $objects.'trailer<</Root 1 0 R/Size '.($pages + 3).">>\nstartxref\n0\n%%EOF\n";
    }

    /**
     * Echtes PNG, erzeugt ueber GD.
     */
    public static function png(int $width = 12, int $height = 8): string
    {
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('GD konnte kein Testbild erzeugen.');
        }

        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }

    /**
     * Echtes JPEG, erzeugt ueber GD.
     */
    public static function jpeg(int $width = 12, int $height = 8): string
    {
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('GD konnte kein Testbild erzeugen.');
        }

        ob_start();
        imagejpeg($image, null, 80);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }

    public static function csv(): string
    {
        return "Einheit;Flaeche;Vorauszahlung\n"
            ."WE 1;72,50;1200,00\n"
            ."WE 2;58,00;960,00\n";
    }

    /**
     * HEIC-Container mit gueltigem ftyp-Kopf. Der Bildinhalt wird nicht
     * benoetigt, weil die Pruefkette nur den Container erkennt und die
     * Umwandlung an Imagick uebergibt.
     */
    public static function heic(): string
    {
        return pack('N', 24)
            .'ftyp'
            .'heic'
            .pack('N', 0)
            .'heicmif1'
            .pack('N', 40)
            .'mdat'
            .str_repeat("\x00", 32);
    }

    /**
     * XLSX als ZIP-Container mit der erwarteten Grundstruktur.
     */
    public static function xlsx(int $sheets = 1): string
    {
        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships/>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook/>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships/>',
        ];

        for ($sheet = 1; $sheet <= $sheets; $sheet++) {
            $entries['xl/worksheets/sheet'.$sheet.'.xml'] = '<?xml version="1.0"?><worksheet/>';
        }

        return self::zip($entries);
    }

    /**
     * @param  array<string, string>  $entries
     */
    public static function zip(array $entries): string
    {
        $path = self::temporaryPath('zip');
        $archive = new ZipArchive;

        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Das Testarchiv konnte nicht erzeugt werden.');
        }

        foreach ($entries as $name => $contents) {
            $archive->addFromString($name, $contents);
        }

        $archive->close();

        $result = (string) file_get_contents($path);

        unlink($path);

        return $result;
    }

    /**
     * Archiv mit extremem Kompressionsverhaeltnis. Der Eintrag bleibt unter dem
     * Dateilimit, sprengt aber das zulaessige Verhaeltnis deutlich.
     */
    public static function zipBomb(): string
    {
        return self::zip(['gross.pdf' => '%PDF-1.4'.str_repeat("\x00", 4 * 1024 * 1024)]);
    }

    public static function zipWithTraversal(): string
    {
        return self::zip([
            'harmlos.pdf' => self::pdf(),
            '../ausserhalb.pdf' => self::pdf(),
        ]);
    }

    public static function nestedZip(): string
    {
        return self::zip([
            'innen.zip' => self::zip(['a.pdf' => self::pdf()]),
        ]);
    }

    /**
     * PHP-Quelltext, der als PDF ausgegeben wird. Klassische MIME-Taeuschung.
     */
    public static function phpDisguisedAsPdf(): string
    {
        return "<?php echo 'nicht ausfuehren'; ?>\n".str_repeat('A', 64);
    }

    public static function temporaryPath(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sa-test');

        if ($path === false) {
            throw new RuntimeException('Es konnte keine temporaere Datei angelegt werden.');
        }

        return $path.'.'.$extension;
    }

    /**
     * Schreibt einen Inhalt in eine temporaere Datei und gibt den Pfad zurueck.
     */
    public static function write(string $contents, string $extension = 'bin'): string
    {
        $path = self::temporaryPath($extension);

        file_put_contents($path, $contents);

        return $path;
    }
}
