<?php

declare(strict_types=1);

namespace App\Services\Pdf\Renderer;

use App\Services\Pdf\PdfEngine;
use App\Services\Pdf\View\TenantStatementView;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Anlage "Erläuterung der Verteilerschlüssel" (Abschnitt 14.1).
 *
 * Die Anlage ist Bestandteil der Mieterabrechnung und wird an dieselbe Datei
 * angehängt. Sie liefert daher nur HTML; die Datei erzeugt der
 * TenantStatementRenderer.
 */
final class AllocationKeyAttachmentRenderer
{
    public const string TEMPLATE = 'pdf.anlage-verteilerschluessel';

    public function __construct(
        private readonly ViewFactory $views,
        private readonly PdfEngine $engine,
    ) {}

    public function html(TenantStatementView $view): string
    {
        return $this->views->make(self::TEMPLATE, [
            'view' => $view,
            'bodyFont' => $this->engine->bodyFontPt(),
        ])->render();
    }
}
