<?php

declare(strict_types=1);

namespace App\Application\Wizard\Dto;

use App\Application\BillingRun\PortalStatusCategory;
use App\Application\Wizard\WizardStep;

/**
 * Eine Station der Fortschrittsleiste.
 *
 * Die Statuskategorie ist immer ein ausgeschriebener Text: Erledigt, Bitte
 * prüfen, Fehlt noch oder Blockiert die Abrechnung. Farbe ist nur zusätzliche
 * Information.
 */
final readonly class WizardStepView
{
    public function __construct(
        public WizardStep $step,
        public string $kategorie,
        public bool $erreichbar,
        public bool $aktuell,
        public ?string $hinweis = null,
    ) {}

    public function variante(): string
    {
        return PortalStatusCategory::variant($this->kategorie);
    }

    public function nummer(): int
    {
        return $this->step->value;
    }

    public function label(): string
    {
        return $this->step->label();
    }

    public function blockiert(): bool
    {
        return $this->kategorie === PortalStatusCategory::BLOCKIERT;
    }

    public function erledigt(): bool
    {
        return $this->kategorie === PortalStatusCategory::ERLEDIGT;
    }
}
