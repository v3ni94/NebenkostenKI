<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailStatus;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'template' => 'vorschau-bereit',
            'recipient_email' => 'empfaenger.'.fake()->unique()->numberBetween(1000, 999999).'@beispiel.invalid',
            'subject' => 'Ihre Vorschau ist bereit',
            'status' => EmailStatus::GESENDET,
            'attempts' => 1,
            'queued_at' => now(),
            'sent_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EmailStatus::FEHLGESCHLAGEN,
            'sent_at' => null,
            'failed_at' => now(),
            'error_code' => 'SMTP_TIMEOUT',
        ]);
    }
}
