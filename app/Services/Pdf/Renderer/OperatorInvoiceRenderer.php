<?php

declare(strict_types=1);

namespace App\Services\Pdf\Renderer;

use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\PdfEngine;
use App\Services\Pdf\PdfRenderOptions;
use App\Services\Pdf\Support\HvmCorporateIdentity;
use App\Services\Pdf\Support\OperatorDetails;
use App\Services\Pdf\Support\PdfFileName;
use App\Services\Pdf\View\InvoiceView;
use App\Services\Storage\ArtifactType;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Leistungsrechnung der Hausverwaltung Müller GmbH an den Nutzer
 * (Abschnitt 15.2).
 *
 * Dies ist das einzige Dokument im HVM-Corporate-Identity mit Kennlinie am
 * oberen Rand. Die Rechnungsnummer wird als Parameter übernommen; die
 * lückenlose, atomare Vergabe erfolgt außerhalb dieses Pakets.
 *
 * Eine Rechnung wird nie mit Wasserzeichen ausgegeben: sie entsteht erst nach
 * bestätigtem Zahlungserfolg.
 *
 * LIVEGANG: Fehlen Steuer- oder Bankdaten, wird der sichtbare Platzhalter aus
 * config('smartabrechnen.operator.placeholder_text') gedruckt. Die fehlenden
 * Felder benennt OperatorDetails::missingMandatoryFields(); sie sind
 * Livegang-Blocker für die produktive Rechnungserzeugung.
 */
final class OperatorInvoiceRenderer
{
    public const string TEMPLATE = 'pdf.hvm-rechnung';

    public function __construct(
        private readonly ViewFactory $views,
        private readonly PdfEngine $engine,
    ) {}

    public function render(InvoiceView $view): PdfDocument
    {
        return $this->engine->renderHtml(
            $this->html($view),
            PdfRenderOptions::final(
                ArtifactType::HVM_RECHNUNG,
                $view->subjectLine(),
                OperatorDetails::fromConfig()->legalName(),
                OperatorDetails::fromConfig()->legalName(),
                null,
                PdfFileName::build('Rechnung', $view->number),
            ),
            self::TEMPLATE,
        );
    }

    public function html(InvoiceView $view): string
    {
        return $this->views->make(self::TEMPLATE, [
            'view' => $view,
            'operator' => OperatorDetails::fromConfig(),
            'logoPath' => HvmCorporateIdentity::logoPath(),
            'bodyFont' => $this->engine->bodyFontPt(),
        ])->render();
    }

    /**
     * Pflichtangaben, die für die produktive Rechnungserzeugung noch fehlen.
     *
     * @return list<string>
     */
    public function launchBlockers(): array
    {
        return OperatorDetails::fromConfig()->missingMandatoryFields();
    }
}
