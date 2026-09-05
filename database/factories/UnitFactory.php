<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Property;
use App\Models\Unit;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = Unit::class;

    /**
     * Fortlaufender Zaehler fuer die Einheitenbezeichnung.
     *
     * Die Tabelle traegt einen Unique-Index auf property_id und label. Ein
     * Zufallswert aus einem begrenzten Bereich kollidiert dabei zwangslaeufig,
     * und fake()->unique() ist keine Loesung: dessen Zustand gilt fuer den
     * gesamten Testprozess und ist nach dem Ausschoepfen des Wertebereichs
     * erschoepft. Das erzeugte reihenfolgeabhaengige Fehlschlaege. Ein
     * eigener Zaehler ist deterministisch und kollisionsfrei.
     */
    private static int $laufendeNummer = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Property::class, 'property_id'),
            'label' => 'WE '.(++self::$laufendeNummer),
            'location' => fake()->randomElement(['EG links', 'EG rechts', '1. OG links', '1. OG rechts', '2. OG mitte', 'DG']),
            'unit_number' => (string) self::$laufendeNummer,
            'living_area_sqm' => '72.5000',
            'heated_area_sqm' => '70.2500',
            'mea' => '87.500000',
            'room_count' => 3,
            'individual_key_1_value' => '1.0000',
            'is_commercial' => false,
            'is_owner_occupied' => false,
        ];
    }

    public function commercial(): static
    {
        return $this->state(fn (array $attributes): array => ['is_commercial' => true]);
    }
}
