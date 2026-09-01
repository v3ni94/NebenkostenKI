<?php

declare(strict_types=1);

namespace App\Services\Pdf\Renderer;

use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfEngine;
use App\Services\Pdf\PdfRenderOptions;
use App\Services\Pdf\Support\PdfFileName;
use App\Services\Pdf\View\OwnerOverviewView;
use App\Services\Storage\ArtifactType;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Eigentümerübersicht als internes Blatt je Abrechnungslauf (Abschnitt 14.2).
 *
 * Das Blatt ist ein internes Dokument. Es wird im Querformat gerendert, weil
 * die Übersichtstabellen mehr Spalten führen als eine Mieterabrechnung.
 */
final class OwnerOverviewRenderer
{
    public const string TEMPLATE = 'pdf.eigentuemeruebersicht';

    public function __construct(
        private readonly ViewFactory $views,
        private readonly PdfEngine $engine,
    ) {}

    public function renderPreview(OwnerOverviewView $view, ?string $calculationSnapshotId = null): PdfDocument
    {
        return $this->engine->renderHtml(
            $this->html($view),
            PdfRenderOptions::preview(
                ArtifactType::EIGENTUEMERUEBERSICHT,
                $view->subjectLine(),
                'Internes Übersichtsblatt',
                $view->owner?->name,
                $calculationSnapshotId,
                $this->downloadName($view, 'vorschau'),
                true,
            ),
            self::TEMPLATE,
        );
    }

    public function renderFinal(OwnerOverviewView $view, ?string $calculationSnapshotId = null): PdfDocument
    {
        return $this->engine->renderHtml(
            $this->html($view),
            PdfRenderOptions::final(
                ArtifactType::EIGENTUEMERUEBERSICHT,
                $view->subjectLine(),
                'Internes Übersichtsblatt',
                $view->owner?->name,
                $calculationSnapshotId,
                $this->downloadName($view, null),
                true,
            ),
            self::TEMPLATE,
        );
    }

    public function html(OwnerOverviewView $view): string
    {
        return $this->views->make(self::TEMPLATE, [
            'view' => $view,
            'bodyFont' => $this->engine->bodyFontPt(),
        ])->render();
    }

    private function downloadName(OwnerOverviewView $view, ?string $suffix): string
    {
        $parts = [
            (string) $view->result->billingPeriod->start->format('Y'),
            $view->result->propertyLabel,
        ];

        if ($suffix !== null) {
            $parts[] = $suffix;
        }

        return PdfFileName::build('Eigentuemeruebersicht', ...$parts);
    }
}
