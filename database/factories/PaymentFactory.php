<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\BillingRun;
use App\Models\Payment;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'provider' => PaymentProvider::STRIPE,
            'checkout_session_id' => 'cs_test_'.Str::random(24),
            'payment_intent_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'amount_cent' => 7470,
            'currency' => 'eur',
            'statement_count' => 3,
            'unit_price_gross_cent' => 2490,
            'base_price_gross_cent' => 0,
            'status' => PaymentStatus::ERSTELLT,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::BEZAHLT,
            'payment_intent_id' => 'pi_test_'.Str::random(24),
            'paid_at' => now(),
        ]);
    }
}
