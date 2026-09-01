<?php

declare(strict_types=1);

namespace App\Application\Wizard\Dto;

use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;

/**
 * Eine Zeile der Schlüsseltabelle (Masterprompt Abschnitt 9, Schritt 8).
 *
 * Der Vorschlag folgt der verbindlichen Priorität:
 *   1. ausdrücklich bestätigte Mietvertragsregelung
 *   2. bestätigter Schlüssel aus dem Vorjahr
 *   3. fachlich naheliegender Standardwert mit Warnhinweis
 *
 * Die Quelle des Vorschlags erscheint als Badge. WEG-Schlüssel und
 * mietvertraglicher Umlageschlüssel werden nicht stillschweigend
 * gleichgesetzt.
 */
final readonly class AllocationKeyRow
{
    /**
     * @param  list<AllocationValueRow>  $values
     */
    public function __construct(
        public string $categoryId,
        public string $categoryLabel,
        public AllocationKeyType $keyType,
        public AllocationKeySource $source,
        public array $values,
        public string $denominator,
        public string $sharePercent,
        public bool $isUnitScope,
        public ?AllocationKeyType $contractKeyType = null,
        public ?string $defaultWarning = null,
        public ?string $measurementUnit = null,
        public bool $consumptionNeedsSubstitute = false,
    ) {}

    public function sourceBadge(): string
    {
        return $this->source->label();
    }

    /**
     * @return list<AllocationValueRow>
     */
    public function missingValues(): array
    {
        return array_values(array_filter(
            $this->values,
            static fn (AllocationValueRow $row): bool => $row->isMissing()
        ));
    }

    public function hasMissingValues(): bool
    {
        return $this->missingValues() !== [];
    }

    /**
     * Ergibt die Summe der Anteile 100 Prozent?
     */
    public function isComplete(): bool
    {
        return ! $this->hasMissingValues() && $this->sharePercent === '100,00';
    }

    /**
     * Weicht der gewählte Schlüssel von der bestätigten Mietvertragsregelung
     * ab? Das ist eine Warnung und kein Blocker.
     */
    public function deviatesFromContract(): bool
    {
        return $this->contractKeyType instanceof AllocationKeyType
            && $this->contractKeyType !== $this->keyType;
    }

    public function deviationWarning(): ?string
    {
        if (! $this->deviatesFromContract()) {
            return null;
        }

        return sprintf(
            'Der gewählte Schlüssel %s weicht von der bestätigten Mietvertragsregelung %s ab. Bitte prüfen Sie, '
            .'ob die Abweichung gewollt ist.',
            $this->keyType->label(),
            $this->contractKeyType instanceof AllocationKeyType ? $this->contractKeyType->label() : ''
        );
    }

    /**
     * Text der Live-Validierung, wenn die Anteilssumme nicht 100 Prozent
     * ergibt.
     */
    public function shareWarning(): ?string
    {
        if ($this->hasMissingValues() || $this->sharePercent === '100,00') {
            return null;
        }

        return sprintf(
            'Die Summe der Anteile ergibt %s Prozent und nicht 100,00 Prozent. Bitte prüfen Sie Zähler und Nenner.',
            $this->sharePercent
        );
    }
}
