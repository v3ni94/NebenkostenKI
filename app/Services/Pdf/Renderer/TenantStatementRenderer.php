<?php

declare(strict_types=1);

namespace App\Services\Pdf\Renderer;

use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfEngine;
use App\Services\Pdf\PdfRenderOptions;
use App\Services\Pdf\Support\PdfFileName;
use App\Services\Pdf\View\TenantStatementView;
use App\Services\Storage\ArtifactType;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Mieterabrechnung samt Anlagen zu den Verteilerschlüsseln und optionaler
 * Belegübersicht (Abschnitt 14.1, 11.1, 2.2).
 *
 * NEUTRALITÄT: Absender ist der Vermieter. Die Datei enthält kein HVM-Logo,
 * keine HVM-Kennlinie und keine HVM-Farben. Einzige Erwähnung der Plattform
 * ist die dezente Fußzeile aus config('smartabrechnen.pdf.tenant_footer').
 *
 * VORSCHAU UND FINALVERSION: Beide Wege rufen denselben Renderweg mit dem
 * identischen Berechnungsergebnis auf und unterscheiden sich ausschließlich in
 * den Wasserzeicheneinstellungen. Die Finalversion wird dadurch vollständig
 * neu erzeugt. Es gibt bewusst keine Methode, die ein bestehendes PDF
 * entgegennimmt oder ein Wasserzeichen entfernt.
 */
final class TenantStatementRenderer
{
    public const string TEMPLATE = 'pdf.mieterabrechnung';

    public function __construct(
        private readonly ViewFactory $views,
        private readonly PdfEngine $engine,
        private readonly AllocationKeyAttachmentRenderer $allocationKeys,
        private readonly VoucherIndexRenderer $vouchers,
    ) {}

    /**
     * Vorschau mit serverseitig eingebranntem Wasserzeichen auf jeder Seite.
     */
    public function renderPreview(TenantStatementView $view, ?string $calculationSnapshotId = null): PdfDocument
    {
        return $this->engine->renderHtml(
            $this->html($view),
            PdfRenderOptions::preview(
                ArtifactType::MIETERABRECHNUNG_VORSCHAU,
                $view->subjectLine(),
                $this->tenantFooter(),
                $view->sender->address->name,
                $calculationSnapshotId,
                $this->downloadName($view, 'vorschau'),
            ),
            self::TEMPLATE,
        );
    }

    /**
     * Finalversion ohne Wasserzeichen, vollständig neu aus dem gesperrten
     * Calculation Snapshot erzeugt.
     */
    public function renderFinal(TenantStatementView $view, ?string $calculationSnapshotId = null): PdfDocument
    {
        return $this->engine->renderHtml(
            $this->html($view),
            PdfRenderOptions::final(
                ArtifactType::MIETERABRECHNUNG_FINAL,
                $view->subjectLine(),
                $this->tenantFooter(),
                $view->sender->address->name,
                $calculationSnapshotId,
                $this->downloadName($view, null),
            ),
            self::TEMPLATE,
        );
    }

    /**
     * Vollständiges HTML der Mieterabrechnung einschließlich Anlagen.
     */
    public function html(TenantStatementView $view): string
    {
        $html = $this->views->make(self::TEMPLATE, [
            'view' => $view,
            'bodyFont' => $this->engine->bodyFontPt(),
        ])->render();

        $html .= '<pagebreak />'.$this->allocationKeys->html($view);

        if ($view->showVoucherIndex) {
            $html .= '<pagebreak />'.$this->vouchers->html($view);
        }

        return $html;
    }

    private function downloadName(TenantStatementView $view, ?string $suffix): string
    {
        $parts = [
            (string) $view->result->billingPeriod->start->format('Y'),
            $view->subject->unitLabel ?? $view->result->unitLabel,
            $view->result->tenantLabel,
        ];

        if ($suffix !== null) {
            $parts[] = $suffix;
        }

        return PdfFileName::build('Betriebskostenabrechnung', ...$parts);
    }

    private function tenantFooter(): string
    {
        $footer = config('smartabrechnen.pdf.tenant_footer');

        return is_string($footer) ? $footer : '';
    }
}
