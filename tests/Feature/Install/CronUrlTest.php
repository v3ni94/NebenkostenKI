<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wartungsaufruf per URL fuer Hosting ohne Shell (CronController).
 */
final class CronUrlTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = 'testschluessel-mit-mindestens-32-zeichen-laenge-0123456789';

    public function test_ohne_konfigurierten_schluessel_existiert_die_route_nicht(): void
    {
        config()->set('smartabrechnen.cron_token', null);

        $this->get('/wartung/schedule?token=irgendwas')->assertNotFound();
    }

    public function test_zu_kurzer_schluessel_schaltet_die_route_ebenfalls_ab(): void
    {
        config()->set('smartabrechnen.cron_token', 'kurz');

        $this->get('/wartung/schedule?token=kurz')->assertNotFound();
    }

    public function test_falscher_schluessel_wird_abgewiesen(): void
    {
        config()->set('smartabrechnen.cron_token', self::TOKEN);

        $this->get('/wartung/schedule?token=falsch')->assertForbidden();
        $this->get('/wartung/schedule')->assertForbidden();
    }

    public function test_unbekannte_aufgabe_wird_abgelehnt(): void
    {
        config()->set('smartabrechnen.cron_token', self::TOKEN);

        $this->get('/wartung/migrate-fresh?token='.self::TOKEN)->assertNotFound();
    }

    public function test_schedule_run_wird_ausgefuehrt_und_liefert_text(): void
    {
        config()->set('smartabrechnen.cron_token', self::TOKEN);

        $antwort = $this->get('/wartung/schedule?token='.self::TOKEN);

        $antwort->assertOk();
        $antwort->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $antwort->assertHeader('Cache-Control', 'no-store, private');
        $antwort->assertSee('Aufgabe: schedule');
        $antwort->assertSee('erfolgreich');
    }

    public function test_check_config_laeuft_ueber_die_url(): void
    {
        config()->set('smartabrechnen.cron_token', self::TOKEN);

        $antwort = $this->get('/wartung/check-config?token='.self::TOKEN);

        $antwort->assertSee('Aufgabe: check-config');
    }

    public function test_admin_wird_mit_email_angelegt_und_das_einmalpasswort_erscheint_genau_einmal(): void
    {
        config()->set('smartabrechnen.cron_token', self::TOKEN);

        $antwort = $this->get('/wartung/admin?token='.self::TOKEN.'&email=admin@example.test&name=Verwaltung');

        $antwort->assertOk();
        $antwort->assertSee('admin@example.test');
        $this->assertDatabaseHas('users', ['email' => 'admin@example.test']);
        self::assertTrue(User::query()->where('email', 'admin@example.test')->firstOrFail()->adminRoles()->exists());
    }

    public function test_scheduler_schluessel_erlaubt_nur_schedule(): void
    {
        config()->set('smartabrechnen.cron_token', null);
        config()->set('smartabrechnen.cron_schedule_token', self::TOKEN);

        $this->get('/wartung/schedule?token='.self::TOKEN)->assertOk();
        $this->get('/wartung/install?token='.self::TOKEN)->assertForbidden();
        $this->get('/wartung/admin?token='.self::TOKEN.'&email=x@example.test')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }

    public function test_admin_ohne_email_wird_abgelehnt(): void
    {
        config()->set('smartabrechnen.cron_token', self::TOKEN);

        $this->get('/wartung/admin?token='.self::TOKEN)->assertSessionHasErrors('email');
    }
}
