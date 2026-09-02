<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use App\Application\Account\LoginDestination;
use App\Application\Install\CreateAdministrator;
use App\Enums\AdminRole;
use App\Models\AdminRoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_legt_administrator_mit_einmalpasswort_an_und_fuehrt_zur_zweitfaktor_einrichtung(): void
    {
        $protokoll = [];
        Log::listen(static function (MessageLogged $eintrag) use (&$protokoll): void {
            $protokoll[] = $eintrag->message.' '.json_encode($eintrag->context);
        });

        $code = Artisan::call('smartabrechnen:admin:create', ['--email' => 'Admin@Beispiel.invalid', '--name' => 'Timo Test']);
        $ausgabe = Artisan::output();

        $this->assertSame(0, $code, $ausgabe);
        $this->assertStringContainsString('Konto angelegt: admin@beispiel.invalid', $ausgabe);
        $this->assertSame(1, preg_match('/Einmalpasswort.*?\n\s*\n\s+(\S{'.CreateAdministrator::PASSWORD_LENGTH.'})\n/s', $ausgabe, $treffer), $ausgabe);
        $passwort = $treffer[1];

        /** @var User $nutzer */
        $nutzer = User::query()->where('email', 'admin@beispiel.invalid')->firstOrFail();

        $this->assertSame('Timo Test', $nutzer->getAttribute('name'));
        $this->assertNotNull($nutzer->getAttribute('email_verified_at'));
        $this->assertTrue($nutzer->hasAdminRole(AdminRole::ADMIN));
        $this->assertNull($nutzer->getAttribute('two_factor_confirmed_at'));

        // Das Einmalpasswort ist gueltig und erscheint in keinem Protokoll.
        $this->assertTrue(Hash::check($passwort, (string) $nutzer->getAttribute('password')));
        $this->assertStringNotContainsString($passwort, implode("\n", $protokoll));

        // Ein frischer Administrator landet nach der Anmeldung ohne Umweg auf
        // der Einrichtung des Zweitfaktors, nicht auf dem Kundendashboard.
        $this->assertSame(route('two-factor.setup'), $this->app->make(LoginDestination::class)->for($nutzer));
    }

    public function test_anmeldung_mit_dem_einmalpasswort_leitet_zur_zweitfaktor_einrichtung(): void
    {
        $passwort = 'einmal-passwort-nur-fuer-den-test';

        /** @var User $nutzer */
        $nutzer = User::factory()->create([
            'email' => 'admin@beispiel.invalid',
            'password' => Hash::make($passwort),
            'two_factor_confirmed_at' => null,
        ]);

        AdminRoleAssignment::query()->create([
            'user_id' => $nutzer->getKey(),
            'role' => AdminRole::ADMIN,
            'granted_at' => now(),
        ]);

        $this->post(route('login'), ['email' => 'admin@beispiel.invalid', 'password' => $passwort])
            ->assertRedirect(route('two-factor.setup'));

        $this->assertAuthenticatedAs($nutzer);
    }

    public function test_administrator_mit_zweitfaktor_landet_im_adminbereich(): void
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create(['two_factor_confirmed_at' => now()]);

        AdminRoleAssignment::query()->create([
            'user_id' => $nutzer->getKey(),
            'role' => AdminRole::ADMIN,
            'granted_at' => now(),
        ]);

        $this->assertSame(route('admin.dashboard'), $this->app->make(LoginDestination::class)->for($nutzer));
    }

    public function test_kundennutzer_behalten_das_kundendashboard(): void
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create();

        $this->assertSame(route('portal.dashboard'), $this->app->make(LoginDestination::class)->for($nutzer));
    }

    public function test_ist_idempotent_und_setzt_bei_bestehender_adresse_nur_die_rolle(): void
    {
        /** @var User $bestehend */
        $bestehend = User::factory()->create(['email' => 'vorhanden@beispiel.invalid']);
        $hash = $bestehend->getAttribute('password');

        $this->artisan('smartabrechnen:admin:create', ['--email' => 'vorhanden@beispiel.invalid'])
            ->expectsOutputToContain('Konto vorhanden')
            ->expectsOutputToContain('Die Adminrolle wurde erteilt.')
            ->doesntExpectOutputToContain('Einmalpasswort')
            ->assertSuccessful();

        $this->artisan('smartabrechnen:admin:create', ['--email' => 'vorhanden@beispiel.invalid'])
            ->expectsOutputToContain('Die Adminrolle war bereits aktiv.')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->where('email', 'vorhanden@beispiel.invalid')->count());
        $this->assertSame(1, AdminRoleAssignment::query()->where('user_id', $bestehend->getKey())->count());
        $this->assertSame($hash, $bestehend->fresh()?->getAttribute('password'), 'Ein bestehendes Passwort wird nie ueberschrieben.');
    }

    public function test_ungueltige_adresse_und_fehlende_option_brechen_ab(): void
    {
        $this->artisan('smartabrechnen:admin:create')->assertFailed();
        $this->artisan('smartabrechnen:admin:create', ['--email' => 'keine-adresse'])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
