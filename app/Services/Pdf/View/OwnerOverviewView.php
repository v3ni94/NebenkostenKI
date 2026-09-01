<?php

declare(strict_types=1);

namespace App\Services\Pdf\View;

use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Calculation\Result\OwnerOverviewResult;
use DateTimeImmutable;

/**
 * Internes Übersichtsblatt je Abrechnungslauf (Abschnitt 14.2).
 *
 * Das Blatt ist ausdrücklich ein internes Dokument für den Eigentümer
 * beziehungsweise Nutzer und nicht für den Versand an Mieter bestimmt.
 */
final readonly class OwnerOverviewView
{
    /**
     * @param  list<CheckFinding>  $findings
     * @param  list<ManualDecision>  $manualDecisions
     * @param  list<DocumentIndexEntry>  $documents
     */
    public function __construct(
        public OwnerOverviewResult $result,
        public DateTimeImmutable $generatedOn,
        public ?PostalAddress $owner = null,
        public ?string $propertyAddressLine = null,
        public array $findings = [],
        public array $manualDecisions = [],
        public array $documents = [],
        public ?string $billingRunReference = null,
    ) {}

    public function subjectLine(): string
    {
        return 'Eigentümerübersicht '.$this->result->billingPeriod->format();
    }
}
