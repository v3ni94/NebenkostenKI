<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ReminderPreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registrierung eines Kundennutzers.
 *
 * Vorgabe des Masterprompts, Abschnitt 8.2: Nach der ersten Registrierung
 * besitzt der Nutzer automatisch ein dauerhaftes Konto. Die Organisation ist der
 * Mandant, ueber den alle Kundendaten gescopet werden. Sie wird deshalb in
 * derselben Transaktion wie der Nutzer angelegt. Ein Nutzer ohne Organisation
 * waere ein Konto ohne Mandanten und damit ohne nutzbaren Bereich.
 *
 * In einer Transaktion entstehen:
 *
 *  1. der Nutzer mit Status UNBESTAETIGT,
 *  2. die persoenliche Organisation,
 *  3. die Mitgliedschaft mit der Rolle OWNER,
 *  4. die globale Erinnerungseinstellung des Nutzers.
 *
 * Der Status wechselt erst mit der bestaetigten E-Mail-Adresse auf AKTIV, siehe
 * App\Application\Account\VerifyEmailAddress. Nur aktive Konten erhalten
 * Erinnerungen (Masterprompt 17.2).
 */
class RegisterUser
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return array{user: User, organization: Organization}
     */
    public function handle(string $name, string $email, string $password): array
    {
        $name = trim($name);
        $email = Str::lower(trim($email));

        /** @var array{user: User, organization: Organization} $ergebnis */
        $ergebnis = DB::transaction(function () use ($name, $email, $password): array {
            /** @var User $user */
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                // Der Cast "hashed" am Modell erzeugt den Hash mit dem in
                // config/hashing.php gesetzten Treiber, produktiv Argon2id.
                'password' => $password,
                'status' => UserStatus::UNBESTAETIGT,
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
            ]);

            /** @var Organization $organization */
            $organization = Organization::query()->create([
                'name' => $name === '' ? $email : $name,
                'type' => OrganizationType::PRIVATPERSON,
                'billing_name' => $name === '' ? null : $name,
                'billing_country' => 'DE',
                'contact_email' => $email,
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'role' => OrganizationRole::OWNER,
                'joined_at' => now(),
            ]);

            // Globale Erinnerungseinstellung. Je Nutzer existiert hoechstens
            // eine Zeile mit property_id null, siehe Migrationskommentar.
            ReminderPreference::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'property_id' => null,
                'is_active' => true,
                'q1_enabled' => true,
                'q2_enabled' => true,
                'q3_enabled' => true,
                'december_enabled' => true,
                'unsubscribe_token' => Str::random(64),
            ]);

            return ['user' => $user, 'organization' => $organization];
        });

        $this->audit->record(
            action: 'account.registered',
            subject: $ergebnis['user'],
            actor: $ergebnis['user'],
            organization: $ergebnis['organization'],
            metadata: ['rolle' => OrganizationRole::OWNER->value],
        );

        return $ergebnis;
    }
}
