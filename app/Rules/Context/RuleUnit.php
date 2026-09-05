<?php

declare(strict_types=1);

namespace App\Rules\Context;

/**
 * Eine Einheit des Objekts mit den fuer Verteilerschluessel benoetigten
 * Bezugsgroessen. Dezimalwerte sind Zeichenketten.
 */
final readonly class RuleUnit
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $livingAreaSqm = null,
        public ?string $heatedAreaSqm = null,
        public ?string $coOwnershipShare = null,
        public bool $requiresHeatedArea = false,
        public bool $requiresCoOwnershipShare = false,
    ) {}

    /**
     * Fehlende Bezugsgroessen dieser Einheit.
     *
     * @return list<string>
     */
    public function missingMeasurements(): array
    {
        $missing = [];

        if ($this->livingAreaSqm === null || trim($this->livingAreaSqm) === '') {
            $missing[] = 'Wohnfläche';
        }

        if ($this->requiresHeatedArea && ($this->heatedAreaSqm === null || trim($this->heatedAreaSqm) === '')) {
            $missing[] = 'beheizte Wohnfläche';
        }

        if ($this->requiresCoOwnershipShare && ($this->coOwnershipShare === null || trim($this->coOwnershipShare) === '')) {
            $missing[] = 'Miteigentumsanteil';
        }

        return $missing;
    }
}
