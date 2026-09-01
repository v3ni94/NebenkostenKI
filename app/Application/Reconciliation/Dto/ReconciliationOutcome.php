<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Dto;

/**
 * Ergebnis eines Reconciliation-Laufs.
 *
 * Der Lauf erzeugt ausschliesslich Vorschlaege, Ausschlusszeilen und
 * Pruefaufgaben. Er bestaetigt nichts und finalisiert nichts.
 */
final readonly class ReconciliationOutcome
{
    /**
     * @param  list<ExcludedPositionRow>  $excludedPositions
     * @param  list<DuplicateFinding>  $duplicates
     */
    public function __construct(
        public int $documentsEvaluated,
        public int $proposalsCreated,
        public array $excludedPositions,
        public array $duplicates,
        public HeatingMatrix $heatingMatrix,
        public ?PropertyTaxOutcome $propertyTax,
        public BillingModeSuggestion $modeSuggestion,
        public int $openIssueCount,
        public bool $blocksFinalization,
    ) {}
}
