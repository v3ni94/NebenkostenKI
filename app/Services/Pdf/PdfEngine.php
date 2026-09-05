<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Services\Pdf\Watermark\WatermarkStamp;
use DateTimeImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Throwable;

/**
 * Einziger Renderweg von HTML zu PDF (ADR-005).
 *
 * Verwendet wird mPDF in reinem PHP, ohne Chromium und ohne Node-Laufzeit,
 * damit die Anwendung auf IONOS Webhosting betrieben werden kann.
 *
 * SCHRIFT: mPDF läuft im UTF-8-Modus mit der eingebetteten Unicode-Schrift
 * DejaVu Sans aus dem mPDF-Paket (vendor/mpdf/mpdf/ttfonts). Der frühere
 * Kernschriftmodus ohne Einbettung hätte jeden Textknoten nach Windows-1252
 * umkodiert; Namen und Adressen mit Zeichen außerhalb dieses Zeichensatzes
 * (zum Beispiel Yılmaz, Łukasz, Ștefan) wären im Dokument zu "?" geworden.
 * Eine Mieterabrechnung ist ein rechtlich relevantes Dokument, ein falsch
 * geschriebener Empfängername ist nicht hinnehmbar. Die Schrift wird als
 * Teilmenge eingebettet, die Dateien bleiben klein und der Text durchsuchbar.
 * Die Vorlagen nennen weiterhin Helvetica und Arial; mPDF bildet beide auf
 * DejaVu Sans ab.
 *
 * PLATZHALTER: mPDF ersetzt die Zeichenketten {PAGENO}, {nb}, {nbpg} und
 * {DATE ...} nicht nur in Kopf- und Fußzeile, sondern in jedem Seiteninhalt.
 * Nutzertexte (Objektname, Mietername, Verwendungszweck) dürfen dadurch
 * nicht verändert werden. renderHtml() trennt solche Zeichenketten im Body
 * deshalb vor der Übergabe an mPDF so auf, dass die Ersetzung nicht mehr
 * greift; die Fußzeile verwendet die echten Platzhalter weiterhin.
 *
 * PDF/A: Es wird ausdrücklich KEINE PDF/A-Konformität behauptet oder
 * ausgewiesen. mPDF unterstützt PDF/A-1b nur eingeschränkt und setzt unter
 * anderem eingebettete Schriften mit vollständigem Farbprofil voraus. Solange
 * eine zuverlässige, geprüfte PDF/A-Erzeugung nicht nachgewiesen ist, wird
 * weder ein PDF/A-Kennzeichen gesetzt noch in Oberfläche, E-Mail oder
 * Dokumentation eine PDF/A-Konformität zugesagt (Abschnitt 3.6).
 *
 * Layout: konservatives, tabellenorientiertes CSS. Weder Flexbox noch Grid
 * noch CSS-Variablen werden in den PDF-Vorlagen verwendet.
 */
final class PdfEngine
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly WatermarkStamp $watermark = new WatermarkStamp,
    ) {}

    /**
     * Rendert eine Blade-Vorlage zu einem PDF.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data, PdfRenderOptions $options): PdfDocument
    {
        try {
            $html = $this->views->make($view, $data)->render();
        } catch (Throwable $exception) {
            throw PdfException::engineFailure($view, $exception->getMessage());
        }

        return $this->renderHtml($html, $options, $view);
    }

    /**
     * Rendert bereits erzeugtes HTML. Wird von den Renderern und von Tests
     * verwendet; der Weg ist identisch mit render().
     */
    public function renderHtml(string $html, PdfRenderOptions $options, string $templateName = 'inline'): PdfDocument
    {
        // mPDF veraendert die Zeichensatzeinstellungen von mbstring waehrend
        // des Renderns. Bleibt das bestehen, werden spaeter gelesene Werte
        // falsch kodiert. Die Einstellungen werden deshalb hier gesichert und
        // in jedem Fall wiederhergestellt.
        $mbEncoding = mb_internal_encoding();
        $mbRegexEncoding = mb_regex_encoding();

        try {
            $mpdf = $this->createMpdf($options);
            $this->watermark->applyTo($mpdf, $options->watermark);
            $mpdf->SetHTMLFooter($this->footerHtml($options));
            $mpdf->WriteHTML(self::neutralisePlaceholders($html));
            $pageCount = $mpdf->page;
            $contents = $mpdf->Output('', 'S');
        } catch (Throwable $exception) {
            throw PdfException::engineFailure($templateName, $exception->getMessage());
        } finally {
            if (is_string($mbEncoding)) {
                mb_internal_encoding($mbEncoding);
            }

            if (is_string($mbRegexEncoding)) {
                @mb_regex_encoding($mbRegexEncoding);
            }
        }

        if (! is_string($contents) || $contents === '') {
            throw PdfException::emptyOutput($templateName);
        }

        if (! str_starts_with($contents, '%PDF-')) {
            throw PdfException::invalidOutput($templateName);
        }

        return new PdfDocument(
            $options->artifactType,
            $options->variant,
            $contents,
            max(1, $pageCount),
            $this->templateVersion(),
            new DateTimeImmutable,
            $options->calculationSnapshotId,
            $options->downloadName,
        );
    }

    public function templateVersion(): string
    {
        $version = config('smartabrechnen.pdf.template_version');

        return is_string($version) && $version !== '' ? $version : '0.0.0';
    }

    public function bodyFontPt(): string
    {
        $value = config('smartabrechnen.pdf.body_font_pt');

        if (is_int($value) || is_float($value)) {
            $value = (float) $value;
        } elseif (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        } else {
            $value = 10.5;
        }

        // Fließtext verbindlich zwischen 10 und 11 pt (Abschnitt 18).
        $value = max(10.0, min(11.0, $value));

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').'pt';
    }

    /**
     * Trennt die mPDF-Platzhalter im Seiteninhalt auf, damit Nutzertexte nicht
     * durch Seitenzahlen oder das Datum ersetzt werden.
     *
     * Ein Elementwechsel zwingt mPDF, den Text in getrennten Textobjekten zu
     * schreiben. Die Ersetzung arbeitet auf dem fertigen Seiteninhalt mit
     * einfachem Zeichenkettenvergleich und findet die getrennte Folge nicht
     * mehr. Sichtbar aendert sich nichts, das leere span traegt keinen Stil.
     */
    public static function neutralisePlaceholders(string $html): string
    {
        return preg_replace(
            '/\{(?=(PAGENO\}|nb\}|nbpg\}|DATE\s))/',
            '<span>{</span>',
            $html,
        ) ?? $html;
    }

    private function createMpdf(PdfRenderOptions $options): Mpdf
    {
        $fontDefaults = (new FontVariables)->getDefaults();

        /** @var array<string, array<string, mixed>> $fontData */
        $fontData = $fontDefaults['fontdata'];
        // Keine OpenType-Ligaturen (zum Beispiel "fi" zu einem Zeichen): Der
        // Text im Dokument soll zeichengenau dem erfassten Text entsprechen
        // und durchsuchbar bleiben.
        $fontData['dejavusans']['useOTL'] = 0;
        $fontData['dejavusans']['useKashida'] = 0;

        /** @var array<string, string> $fontTranslations */
        $fontTranslations = $fontDefaults['fonttrans'];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $options->landscape ? 'A4-L' : 'A4',
            'default_font' => 'dejavusans',
            'fontdata' => $fontData,
            // Die Vorlagen nennen Helvetica und Arial. Beide werden auf die
            // eingebettete DejaVu Sans abgebildet, damit alle Dokumente
            // dieselbe Schrift tragen.
            'fonttrans' => [
                'helvetica' => 'dejavusans',
                'arial' => 'dejavusans',
            ] + $fontTranslations,
            'margin_left' => 25,
            'margin_right' => 20,
            'margin_top' => 18,
            'margin_bottom' => 22,
            'margin_footer' => 10,
            'tempDir' => $this->tempDir(),
        ]);

        $mpdf->SetTitle($options->title);
        $mpdf->SetAuthor($options->author ?? $options->title);
        $mpdf->SetCreator('smart-abrechnen.de');
        $mpdf->SetSubject($options->title);
        $mpdf->autoPageBreak = true;
        $mpdf->useSubstitutions = false;
        $mpdf->shrink_tables_to_fit = 1;

        return $mpdf;
    }

    /**
     * Fußzeile jeder Seite. Neutral gestaltet, ohne CI-Farben, damit auch
     * Mieterdokumente keine Gestaltungselemente der Hausverwaltung tragen.
     */
    public function footerHtml(PdfRenderOptions $options): string
    {
        $left = e(trim($options->footerLeft));

        if ($options->watermark->enabled && $options->watermark->footerNote !== '') {
            $note = e($options->watermark->footerNote);
            $left = $left === '' ? $note : $left.' | '.$note;
        }

        return '<table width="100%" style="border-top:0.2mm solid #cccccc;font-size:7.5pt;color:#444444;">'
            .'<tr>'
            .'<td align="left">'.$left.'</td>'
            .'<td align="right">Seite {PAGENO} von {nb}</td>'
            .'</tr></table>';
    }

    private function tempDir(): string
    {
        $dir = storage_path('framework/cache/mpdf');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return is_dir($dir) ? $dir : sys_get_temp_dir();
    }
}
