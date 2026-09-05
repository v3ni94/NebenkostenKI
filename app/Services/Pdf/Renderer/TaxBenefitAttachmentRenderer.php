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
 * Anlage nach § 35a EStG als eigene Datei (Abschnitt 12.4, 14.1).
 *
 * Ausgewiesen werden getrennt haushaltsnahe Dienstleistungen und
 * Handwerkerleistungen, ausschließlich mit nachgewiesenen begünstigten
 * Bestandteilen. Materialkosten werden nicht als Lohnanteil ausgegeben.
 */
final class TaxBenefitAttachmentRenderer
{
    public const string TEMPLATE = 'pdf.anlage-35a';

    public function __construct(
        private readonly ViewFactory $views,
        private readonly PdfEngine $engine,
    ) {}

    public function renderPreview(TenantStatementView $view, ?string $calculationSnapshotId = null): PdfDocument
    {
        return $this->engine->renderHtml(
            $this->html($view),
            PdfRenderOptions::preview(
                ArtifactType::ANLAGE_35A,
                'Anlage nach Paragraf 35a EStG',
                $this->tenantFooter(),
                $view->sender->address->name,
                $calculationSnapshotId,
                $this->downloadName($view, 'vorschau'),
            ),
            self::TEMPLATE,
        );
    }

    public function renderFinal(TenantStatementView $view, ?string $calculationSnapshotId = null): PdfDocument
    {
        return $this->engine->renderHtml(
            $this->html($view),
            PdfRenderOptions::final(
                ArtifactType::ANLAGE_35A,
                'Anlage nach Paragraf 35a EStG',
                $this->tenantFooter(),
                $view->sender->address->name,
                $calculationSnapshotId,
                $this->downloadName($view, null),
            ),
            self::TEMPLATE,
        );
    }

    public function html(TenantStatementView $view): string
    {
        return $this->views->make(self::TEMPLATE, [
            'view' => $view,
            'bodyFont' => $this->engine->bodyFontPt(),
        ])->render();
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

        return PdfFileName::build('Anlage-35a', ...$parts);
    }

    private function tenantFooter(): string
    {
        $footer = config('smartabrechnen.pdf.tenant_footer');

        return is_string($footer) ? $footer : '';
    }
}
