<?php

declare(strict_types=1);

namespace App\Services\Pdf\Watermark;

use Mpdf\Mpdf;

/**
 * Brennt das Vorschauwasserzeichen serverseitig in das PDF ein.
 *
 * TECHNISCHE UMSETZUNG (Abschnitt 14.3, ADR-005): Verwendet wird die
 * Wasserzeichenfunktion von mPDF (SetWatermarkText zusammen mit
 * showWatermarkText). mPDF zeichnet den Text bei jedem Seitenbeginn in den
 * Seiteninhalt, diagonal und mit Alpha-Transparenz. Das Wasserzeichen ist
 * damit Teil des Seiteninhalts und KEINE entfernbare Ebene im Browser, kein
 * HTML-Overlay und kein CSS-Hintergrundbild.
 */
final class WatermarkStamp
{
    public function applyTo(Mpdf $mpdf, WatermarkSettings $settings): void
    {
        if (! $settings->enabled) {
            $mpdf->showWatermarkText = false;
            $mpdf->SetWatermarkText('');

            return;
        }

        $mpdf->SetWatermarkText($settings->text, $settings->alpha);
        $mpdf->showWatermarkText = true;
        $mpdf->watermarkTextAlpha = $settings->alpha;
        $mpdf->watermarkAngle = $settings->angle;
        $mpdf->watermark_size = $settings->sizePercent;
    }
}
