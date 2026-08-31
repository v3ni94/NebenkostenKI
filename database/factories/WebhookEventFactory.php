<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\WebhookProcessingStatus;
use App\Enums\WebhookSignatureStatus;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => PaymentProvider::STRIPE,
            'provider_event_id' => 'evt_test_'.Str::random(24),
            'event_type' => 'checkout.session.completed',
            'signature_status' => WebhookSignatureStatus::GUELTIG,
            'processing_status' => WebhookProcessingStatus::EMPFANGEN,
            'payload_digest' => hash('sha256', 'testnutzlast'),
            'payload' => null,
            'received_at' => now(),
            'attempts' => 0,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'processing_status' => WebhookProcessingStatus::VERARBEITET,
            'processed_at' => now(),
            'attempts' => 1,
        ]);
    }
}
