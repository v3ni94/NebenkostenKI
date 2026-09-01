<?php

declare(strict_types=1);

namespace App\Application\Review\Dto;

/**
 * Darstellung einer Kostenposition in der Pruefoberflaeche (Schritt 6).
 *
 * DATENSCHUTZ: Sichtbar ist ausschliesslich die neutrale Quellenbezeichnung
 * mit Seite und dem kurzen gespeicherten Fundstellenausschnitt. Es gibt keine
 * Seitenansicht des Quelldokuments; die Originale sind zu diesem Zeitpunkt
 * bereits geloescht (Abschnitt 6.4 und 6.5).
 */
final readonly class CostPositionView
{
    /**
     * @param  list<string>  $conflictReasons
     */
    public function __construct(
        public string $id,
        public string $description,
        public ?string $supplierName,
        public ?string $invoiceNumber,
        public ?string $documentDateLabel,
        public ?string $servicePeriodLabel,
        public int $amountCent,
        public string $amountLabel,
        public ?string $categoryId,
        public ?string $categoryCode,
        public ?string $categoryName,
        public string $apportionmentStatus,
        public string $apportionmentLabel,
        public bool $excludedFromApportionment,
        public ?int $laborShareCent,
        public ?string $laborShareLabel,
        public string $paragraph35aLabel,
        public string $sourceLabel,
        public ?int $sourcePage,
        public ?string $sourceExcerpt,
        public ?string $confidence,
        public string $confidenceLabel,
        public string $confidenceVariant,
        public bool $possibleDuplicate,
        public ?string $duplicateOfCostItemId,
        public string $status,
        public string $statusLabel,
        public bool $decided,
        public bool $servicePeriodOutside,
        public bool $bulkConfirmable,
        public array $conflictReasons = [],
        public ?string $directUnitLabel = null,
        public bool $manuallyEntered = false,
    ) {}
}
