<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

use App\Domain\Support\GermanNumberFormatter;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Individueller Verteilerschlüssel 1 bis 5.
 *
 * Für mietvertraglich vereinbarte Sonderschlüssel, etwa Nutzflächenanteile,
 * Stellplatzeinheiten oder gewerbliche Sonderquoten. Die Bezeichnung kann
 * überschrieben werden, damit das PDF den vereinbarten Begriff zeigt.
 */
final class IndividualKey extends NumericAllocationKey
{
    private AllocationKeyType $type;

    private ?string $customLabel;

    private string $unitOfMeasure;

    /**
     * Der Index wird zur Laufzeit geprüft, damit auch Werte aus der
     * Persistenzschicht sicher verarbeitet werden. Zulässig sind 1 bis 5.
     *
     * @param  array<string, BigDecimal|string|int>  $values
     */
    public function __construct(
        int $index,
        array $values,
        BigDecimal|string|int|null $denominator = null,
        ?string $label = null,
        string $unitOfMeasure = '',
    ) {
        $type = AllocationKeyType::tryFrom('INDIVIDUAL_'.$index);

        if (! $type instanceof AllocationKeyType) {
            throw new InvalidArgumentException('Individuelle Verteilerschlüssel sind nur mit Index 1 bis 5 zulässig.');
        }

        $this->type = $type;
        $this->customLabel = $label;
        $this->unitOfMeasure = $unitOfMeasure;

        parent::__construct($values, $denominator);
    }

    public function type(): AllocationKeyType
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->customLabel ?? $this->type->label();
    }

    public function explanationFor(string $participantKey): string
    {
        $unit = $this->unitOfMeasure;

        return sprintf(
            '%s %s von %s',
            $this->label(),
            GermanNumberFormatter::quantity($this->numeratorFor($participantKey), $unit),
            GermanNumberFormatter::quantity($this->denominator(), $unit)
        );
    }
}
