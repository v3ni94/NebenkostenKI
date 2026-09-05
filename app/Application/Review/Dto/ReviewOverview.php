<?php

declare(strict_types=1);

namespace App\Application\Review\Dto;

/**
 * Gesamtdarstellung der Kostenpruefung (Schritt 6).
 *
 * canProceed ist nur true, wenn jede Position bestaetigt oder verworfen ist.
 * Eine Sammelbestaetigung ist ausschliesslich fuer konfliktfreie und
 * hochkonfidente Positionen zugelassen.
 */
final readonly class ReviewOverview
{
    /**
     * @param  list<CostGroupView>  $groups
     * @param  list<WarningBanner>  $banners
     * @param  list<string>  $bulkConfirmableIds
     */
    public function __construct(
        public array $groups,
        public array $banners,
        public array $bulkConfirmableIds,
        public int $positionCount,
        public int $openCount,
        public int $confirmedCount,
        public int $discardedCount,
        public int $apportionableSumCent,
        public string $apportionableSumLabel,
        public int $excludedSumCent,
        public string $excludedSumLabel,
        public bool $canProceed,
        public ?string $blockedReason,
    ) {}
}
