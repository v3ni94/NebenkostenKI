<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ReminderPreference;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Registrierung einschliesslich automatischer Anlage der Organisation.
 */
final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $abweichungen
     * @return array<string, mixed>
     */
    private function gueltigeAngaben(array $abweichungen = []): array
    {
        return array_merge([
            'name' => 'Maria Beispiel',
            'email' => 'maria.beispiel@beispiel.invalid',
            'password' => 'sicheres-passwort-2026',
            'password_confirmation' => 'sicheres-passwort-2026',
            'datenschutz' => '1',
        ], $abweichungen);
    }

    public function test_registrierungsformular_ist_erreichbar(): void
    {
        $antwort = $this->get(route('register'));

        $antwort->assertOk();
        $antwort->assertSee('Konto anlegen');
        $antwort->assertSee('Mindestens 12 Zeichen');
    }

    public function test_registrierung_legt_nutzer_organisation_und_mitgliedschaft_an(): void
    {
        Notification::fake();

        $antwort = $this->post(route('register'), $this->gueltigeAngaben());

        $antwort->assertRedirect(route('verification.notice'));

        $nutzer = User::query()->where('email', 'maria.beispiel@beispiel.invalid')->first();
        self::assertInstanceOf(User::class, $nutzer);
        self::assertSame('Maria Beispiel', $nutzer->getAttribute('name'));
        self::assertSame(UserStatus::UNBESTAETIGT, $nutzer->getAttribute('status'));
        self::assertNull($nutzer->getAttribute('email_verified_at'));

        // Genau eine Organisation, der Nutzer ist Inhaber.
        self::assertSame(1, Organization::query()->count());

        $mitgliedschaft = OrganizationUser::query()->where('user_id', $nutzer->getKey())->first();
        self::assertInstanceOf(OrganizationUser::class, $mitgliedschaft);
        self::assertSame(OrganizationRole::OWNER, $mitgliedschaft->getAttribute('role'));
        self::assertNotNull($mitgliedschaft->getAttribute('joined_at'));

        $organisation = Organization::query()->firstOrFail();
        self::assertSame(OrganizationType::PRIVATPERSON, $organisation->getAttribute('type'));
        self::assertSame('Maria Beispiel', $organisation->getAttribute('name'));
    }

    public function test_registrierung_legt_globale_erinnerungseinstellung_an(): void
    {
        Notification::fake();

        $this->post(route('register'), $this->gueltigeAngaben());

        $einstellung = ReminderPreference::query()->whereNull('property_id')->first();

        self::assertInstanceOf(ReminderPreference::class, $einstellung);
        self::assertTrue((bool) $einstellung->getAttribute('is_active'));
        self::assertSame(64, strlen((string) $einstellung->getAttribute('unsubscribe_token')));
    }

    public function test_registrierung_meldet_den_nutzer_an_und_versendet_die_bestaetigung(): void
    {
        Notification::fake();

        $this->post(route('register'), $this->gueltigeAngaben());

        $nutzer = User::query()->firstOrFail();

        $this->assertAuthenticatedAs($nutzer);
        Notification::assertSentTo($nutzer, VerifyEmailAddress::class);
    }

    public function test_registrierung_speichert_das_passwort_nur_als_hash(): void
    {
        Notification::fake();

        $this->post(route('register'), $this->gueltigeAngaben());

        $nutzer = User::query()->firstOrFail();
        $hash = (string) $nutzer->getAttribute('password');

        self::assertNotSame('sicheres-passwort-2026', $hash);
        self::assertTrue(Hash::check('sicheres-passwort-2026', $hash));
    }

    public function test_registrierung_ohne_einwilligung_wird_abgelehnt(): void
    {
        Notification::fake();

        $antwort = $this->post(route('register'), $this->gueltigeAngaben(['datenschutz' => null]));

        $antwort->assertSessionHasErrors('datenschutz');
        self::assertSame(0, User::query()->count());
        self::assertSame(0, Organization::query()->count());
    }

    public function test_registrierung_mit_zu_kurzem_passwort_wird_abgelehnt(): void
    {
        $antwort = $this->post(route('register'), $this->gueltigeAngaben([
            'password' => 'kurz1',
            'password_confirmation' => 'kurz1',
        ]));

        $antwort->assertSessionHasErrors('password');
        self::assertSame(0, User::query()->count());
    }

    public function test_registrierung_mit_abweichender_wiederholung_wird_abgelehnt(): void
    {
        $antwort = $this->post(route('register'), $this->gueltigeAngaben([
            'password_confirmation' => 'ein-anderes-passwort-2026',
        ]));

        $antwort->assertSessionHasErrors('password');
        self::assertSame(0, User::query()->count());
    }

    public function test_registrierung_mit_bereits_vergebener_adresse_verraet_kein_konto(): void
    {
        User::factory()->create(['email' => 'schon.da@beispiel.invalid']);

        $antwort = $this->post(route('register'), $this->gueltigeAngaben([
            'email' => 'schon.da@beispiel.invalid',
        ]));

        $antwort->assertSessionHasErrors('email');

        $fehler = session('errors')?->get('email') ?? [];
        $text = implode(' ', $fehler);

        // Die Meldung darf nicht bestaetigen, dass ein Konto existiert.
        self::assertStringNotContainsString('besteht bereits ein Konto', $text);
        self::assertStringContainsString('keine Registrierung möglich', $text);
    }

    public function test_registrierung_ist_ohne_csrf_token_nicht_moeglich(): void
    {
        // Die CSRF-Middleware ueberspringt die Pruefung, solange die Anwendung
        // in der Umgebung testing laeuft. Fuer diesen Nachweis wird die
        // Umgebung deshalb ausdruecklich umgestellt.
        $this->app['env'] = 'local';

        $antwort = $this->post(route('register'), $this->gueltigeAngaben());

        $antwort->assertStatus(419);
        self::assertSame(0, User::query()->count());
    }

    public function test_angemeldeter_nutzer_sieht_das_registrierungsformular_nicht(): void
    {
        $this->actingAs(User::factory()->create());

        $antwort = $this->get(route('register'));

        $antwort->assertRedirect(route('portal.dashboard'));
    }
}
