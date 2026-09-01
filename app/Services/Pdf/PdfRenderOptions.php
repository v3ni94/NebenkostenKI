<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Enums\GeneratedDocumentVariant;
use App\Services\Pdf\Watermark\WatermarkSettings;
use App\Services\Storage\ArtifactType;

/**
 * Rahmenangaben eines Rendervorgangs.
 *
 * Der Fußzeilentext links ist bei Mieterdokumenten ausschließlich der dezente
 * Hinweis aus config('smartabrechnen.pdf.tenant_footer'). Rechts steht immer
 * die Seitennummerierung "Seite X von Y".
 */
final readonly class PdfRenderOptions
{
    public function __construct(
        public ArtifactType $artifactType,
        public GeneratedDocumentVariant $variant,
        public WatermarkSettings $watermark,
        public string $title,
        public string $footerLeft = '',
        public ?string $author = null,
        public ?string $calculationSnapshotId = null,
        public ?string $downloadName = null,
        public bool $landscape = false,
    ) {}

    public static function preview(
        ArtifactType $artifactType,
        string $title,
        string $footerLeft = '',
        ?string $author = null,
        ?string $calculationSnapshotId = null,
        ?string $downloadName = null,
        bool $landscape = false,
    ): self {
        return new self(
            $artifactType,
            GeneratedDocumentVariant::VORSCHAU,
            WatermarkSettings::preview(),
            $title,
            $footerLeft,
            $author,
            $calculationSnapshotId,
            $downloadName,
            $landscape,
        );
    }

    public static function final(
        ArtifactType $artifactType,
        string $title,
        string $footerLeft = '',
        ?string $author = null,
        ?string $calculationSnapshotId = null,
        ?string $downloadName = null,
        bool $landscape = false,
    ): self {
        return new self(
            $artifactType,
            GeneratedDocumentVariant::FINAL,
            WatermarkSettings::none(),
            $title,
            $footerLeft,
            $author,
            $calculationSnapshotId,
            $downloadName,
            $landscape,
        );
    }
}
