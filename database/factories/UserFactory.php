<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Frei erfundene Testdaten. Keine echten Personen und keine Bestandsdaten.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->randomElement(TestData::FIRST_NAMES);
        $lastName = fake()->randomElement(TestData::LAST_NAMES);

        return [
            'name' => $firstName.' '.$lastName,
            'email' => Str::lower($firstName.'.'.$lastName.'.'.fake()->unique()->numberBetween(1000, 999999)).'@beispiel.invalid',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('geheimes-testpasswort'),
            'remember_token' => Str::random(10),
            'status' => UserStatus::AKTIV,
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
            'status' => UserStatus::UNBESTAETIGT,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::GESPERRT,
        ]);
    }
}
