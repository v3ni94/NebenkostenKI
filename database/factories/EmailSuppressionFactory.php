<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailSuppressionReason;
use App\Models\EmailSuppression;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailSuppression>
 */
class EmailSuppressionFactory extends Factory
{
    protected $model = EmailSuppression::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => 'gesperrt.'.fake()->unique()->numberBetween(1000, 999999).'@beispiel.invalid',
            'reason' => EmailSuppressionReason::BOUNCE,
            'suppressed_at' => now(),
            'source' => 'smtp-bounce',
        ];
    }
}
