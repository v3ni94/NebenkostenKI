<?php

declare(strict_types=1);

namespace App\Services\Pdf\Renderer;

use App\Services\Pdf\PdfEngine;
use App\Services\Pdf\View\TenantStatementView;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Optional zuschaltbare Belegübersicht (Abschnitt 14.1).
 *
 * Die Übersicht entsteht ausschließlich aus strukturierten Extraktionsdaten.
 * Originaldateien werden weder eingebettet noch verlinkt; es werden keine
 * Dateipfade ausgegeben.
 */
final class VoucherIndexRenderer
{
    public const string TEMPLATE = 'pdf.anlage-belegliste';

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
