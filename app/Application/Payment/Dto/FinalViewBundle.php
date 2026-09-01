<?php

declare(strict_types=1);

namespace App\Application\Payment\Dto;

use App\Services\Pdf\View\OwnerOverviewView;
use App\Services\Pdf\View\TenantStatementView;

/**
 * Darstellungsobjekte eines gesperrten Berechnungsstandes.
 *
 * statementIds ordnet jeder Mieterabrechnung die zugehoerige Kennung aus
 * unit_statements zu, damit das erzeugte PDF eindeutig zugeordnet gespeichert
 * werden kann. Die Reihenfolge ist verbindlich identisch mit statements.
 */
final readonly class FinalViewBundle
{
    /**
     * @param  list<TenantStatementView>  $statements
     * @param  list<string|null>  $statementIds
     */
    public function __construct(
        public array $statements,
        public array $statementIds = [],
        public ?OwnerOverviewView $ownerOverview = null,
    ) {}

    public function statementIdAt(int $index): ?string
    {
        $id = $this->statementIds[$index] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function isEmpty(): bool
    {
        return $this->statements === [];
    }
}
