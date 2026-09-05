<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use App\Application\Install\CheckResult;
use App\Application\Install\ConfigurationCheck;
use App\Application\Install\Connectivity\SmtpConnectivity;
use App\Application\Install\Connectivity\StripeConnectivity;
use App\Application\Install\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Alle externen Aufrufe sind gefakt. Es entsteht keine Verbindung zu Stripe,
 * einem SMTP-Server, einem SFTP-Ziel oder einem KI-Provider.
 */
final class CheckConfigCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $stripeAufrufe = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $smtpAufrufe = [];

    private bool $stripeGueltig = true;

    private bool $smtpGueltig = true;

    protected function setUp(): void
    {
        parent::setUp();

        $stripe = new class($this) implements StripeConnectivity
        {
            public function __construct(private readonly CheckConfigCommandTest $test) {}

            public function verifySecretKey(string $secretKey): string
            {
                return $this->test->stripeAufruf($secretKey);
            }
        };

        $smtp = new class($this) implements SmtpConnectivity
        {
            public function __construct(private readonly CheckConfigCommandTest $test) {}

            public function handshake(array $mailerConfig): void
            {
                $this->test->smtpAufruf($mailerConfig);
            }
        };

        $this->app->instance(ConfigurationCheck::class, new ConfigurationCheck(
            $this->app,
            $this->app->make(SchedulerHeartbeat::class),
            $stripe,
            $smtp,
        ));

        $this->produktivkonfiguration();
    }

    public function stripeAufruf(string $secretKey): string
    {
        $this->stripeAufrufe[] = $secretKey;

        if (! $this->stripeGueltig) {
            throw new RuntimeException('sk_live_darf_nicht_erscheinen abgelehnt');
        }

        return str_starts_with($secretKey, 'sk_live_') ? 'live' : 'test';
    }

    /**
     * @param  array<string, mixed>  $mailerConfig
     */
    public function smtpAufruf(array $mailerConfig): void
    {
        $this->smtpAufrufe[] = $mailerConfig;

        if (! $this->smtpGueltig) {
            throw new RuntimeException('535 Authentication failed for postfach-geheim');
        }
    }

    public function test_vollstaendige_konfiguration_liefert_keine_fehler_und_exit_null(): void
    {
        $code = Artisan::call('smartabrechnen:check-config');
        $ausgabe = Artisan::output();
        $this->assertSame(0, $code, $ausgabe);
        $this->assertStringContainsString('Datenbank', $ausgabe);
        $this->assertStringContainsString('SFTP', $ausgabe);
        $this->assertStringContainsString('Stripe-Schluessel', $ausgabe);
        $this->assertStringContainsString('Keine Fehler', $ausgabe);

        $this->stripeAufrufe = [];
        $this->smtpAufrufe = [];
        $ergebnisse = $this->ergebnisse();

        $this->assertFalse(ConfigurationCheck::hasErrors($ergebnisse));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'APP_ENV'));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'APP_DEBUG'));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'APP_URL'));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'Trusted Proxies'));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'SFTP'));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'SMTP'));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'Stripe-Schluessel'));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'Stripe-Webhook'));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'Assets'));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'Cronjob'));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'KI-Anbindung'));
        $this->assertSame(CheckResult::OK, $this->statusVon($ergebnisse, 'KI-Tageslimit'));

        // SQLite ist im Test nur eine Warnung; die KI-Freigabe fehlt bewusst.
        $this->assertSame(CheckResult::WARNUNG, $this->statusVon($ergebnisse, 'Datenbank'));
        $this->assertSame(CheckResult::WARNUNG, $this->statusVon($ergebnisse, 'KI-Provider'));

        $this->assertCount(1, $this->stripeAufrufe);
        $this->assertCount(1, $this->smtpAufrufe);
        $this->assertSame([], Storage::disk('sftp')->allFiles(), 'Die Probedatei muss wieder geloescht sein.');
    }

    public function test_fehler_fuehren_zu_exit_code_eins_mit_handlungsanweisung(): void
    {
        config()->set('app.debug', true);
        config()->set('services.stripe.webhook_secret', null);

        $this->assertSame(1, Artisan::call('smartabrechnen:check-config'));
        $ausgabe = Artisan::output();
        $this->assertStringContainsString('FEHLER', $ausgabe);
        $this->assertStringContainsString('APP_DEBUG=false', $ausgabe);
        $this->assertStringContainsString('/webhooks/stripe', $ausgabe);
        $this->assertStringContainsString('2 Fehler', $ausgabe);
    }

    public function test_geheimnisse_erscheinen_nicht_in_der_ausgabe(): void
    {
        $this->stripeGueltig = false;
        $this->smtpGueltig = false;

        $ergebnisse = $this->ergebnisse();
        $text = implode("\n", array_map(
            static fn (CheckResult $r): string => $r->name.' '.$r->message.' '.$r->action,
            $ergebnisse,
        ));

        $this->assertSame(CheckResult::FEHLER, $this->statusVon($ergebnisse, 'Stripe-Schluessel'));
        $this->assertSame(CheckResult::FEHLER, $this->statusVon($ergebnisse, 'SMTP'));
        $this->assertStringContainsString('RuntimeException', $text);
        $this->assertStringNotContainsString('sk_live_', $text);
        $this->assertStringNotContainsString('postfach-geheim', $text);
        $this->assertStringNotContainsString('darf_nicht_erscheinen', $text);
        $this->assertStringNotContainsString('sftp.beispiel.invalid', $text);
        $this->assertStringNotContainsString('smtp.beispiel.invalid', $text);
        $this->assertStringNotContainsString('sehr-geheim', $text);
    }

    public function test_fehlende_zugangsdaten_werden_ohne_verbindung_gemeldet(): void
    {
        config()->set('services.stripe.secret', null);
        config()->set('mail.mailers.smtp.password', null);
        config()->set('filesystems.disks.sftp.host', null);

        $ergebnisse = $this->ergebnisse();

        $this->assertSame(CheckResult::FEHLER, $this->statusVon($ergebnisse, 'Stripe-Schluessel'));
        $this->assertSame(CheckResult::FEHLER, $this->statusVon($ergebnisse, 'SMTP'));
        $this->assertSame(CheckResult::FEHLER, $this->statusVon($ergebnisse, 'SFTP'));
        $this->assertSame([], $this->stripeAufrufe);
        $this->assertSame([], $this->smtpAufrufe);
    }

    public function test_testprovider_und_fehlender_cronjob_sind_fehler(): void
    {
        config()->set('ai.primary_provider', 'fake');
        $this->app->make(SchedulerHeartbeat::class)->record(now()->subHours(3));

        $ergebnisse = $this->ergebnisse();

        $this->assertSame(CheckResult::FEHLER, $this->statusVon($ergebnisse, 'KI-Provider'));
        $this->assertSame(CheckResult::FEHLER, $this->statusVon($ergebnisse, 'Cronjob'));
    }

    public function test_abgeschaltete_ki_anbindung_wird_als_warnung_gemeldet(): void
    {
        config()->set('ai.bind_document_pipeline', false);

        $ergebnisse = $this->ergebnisse();

        $this->assertSame(CheckResult::WARNUNG, $this->statusVon($ergebnisse, 'KI-Anbindung'));
        $this->assertStringContainsString('AI_BIND_DOCUMENT_PIPELINE', $this->meldungVon($ergebnisse, 'KI-Anbindung'));
    }

    public function test_tageslimit_von_null_cent_ist_ein_fehler(): void
    {
        config()->set('ai.max_daily_cost_cent_per_user', 0);

        $ergebnisse = $this->ergebnisse();

        $this->assertSame(CheckResult::FEHLER, $this->statusVon($ergebnisse, 'KI-Tageslimit'));
        $this->assertStringContainsString('Tageslimit erreicht', $this->meldungVon($ergebnisse, 'KI-Tageslimit'));
        $this->assertTrue(ConfigurationCheck::hasErrors($ergebnisse));
    }

    public function test_fehlendes_tageslimit_ist_eine_warnung(): void
    {
        config()->set('ai.max_daily_cost_cent_per_user', null);

        $this->assertSame(CheckResult::WARNUNG, $this->statusVon($this->ergebnisse(), 'KI-Tageslimit'));
    }

    public function test_tageslimit_ohne_kalkulationsbasis_fuer_die_konfigurierten_modelle_ist_ein_fehler(): void
    {
        config()->set('ai.max_daily_cost_cent_per_user', 500);
        config()->set('ai.cost_basis_us_cent_per_million_tokens', [
            'claude-haiku-4-5' => ['input' => 1, 'output' => 1],
        ]);

        $ergebnisse = $this->ergebnisse();

        $this->assertSame(CheckResult::FEHLER, $this->statusVon($ergebnisse, 'KI-Tageslimit'));
        $meldung = $this->meldungVon($ergebnisse, 'KI-Tageslimit');
        $this->assertStringContainsString('openai: gpt-5.6-luna', $meldung);
        $this->assertStringContainsString('openai: gpt-5.6-terra', $meldung);
    }

    public function test_kalkulationsbasis_wird_auch_fuer_den_fallbackprovider_verlangt(): void
    {
        config()->set('ai.max_daily_cost_cent_per_user', 500);
        config()->set('ai.fallback_enabled', true);
        config()->set('ai.fallback_provider', 'anthropic');
        config()->set('ai.cost_basis_us_cent_per_million_tokens', [
            'gpt-5.6-luna' => ['input' => 1, 'output' => 1],
            'gpt-5.6-terra' => ['input' => 1, 'output' => 1],
        ]);

        $ergebnisse = $this->ergebnisse();

        $this->assertSame(CheckResult::FEHLER, $this->statusVon($ergebnisse, 'KI-Tageslimit'));
        $this->assertStringContainsString('anthropic: claude-haiku-4-5', $this->meldungVon($ergebnisse, 'KI-Tageslimit'));
    }

    public function test_modusabweichung_der_stripe_schluessel_ist_eine_warnung(): void
    {
        config()->set('services.stripe.key', 'pk_test_beispiel');

        $this->assertSame(CheckResult::WARNUNG, $this->statusVon($this->ergebnisse(), 'Stripe-Schluessel'));
    }

    public function test_www_in_app_url_und_leere_proxys_sind_warnungen(): void
    {
        config()->set('app.url', 'https://www.beispiel.test');
        config()->set('deploy.trusted_proxies', '');

        $ergebnisse = $this->ergebnisse();

        $this->assertSame(CheckResult::WARNUNG, $this->statusVon($ergebnisse, 'APP_URL'));
        $this->assertSame(CheckResult::WARNUNG, $this->statusVon($ergebnisse, 'Trusted Proxies'));
    }

    public function test_testmail_wird_ueber_den_mailer_versendet(): void
    {
        // Der Mailer wird ersetzt, damit kein SMTP-Verbindungsversuch entsteht.
        Mail::fake();

        $code = Artisan::call('smartabrechnen:check-config', ['--send-test-mail' => 'empfaenger@beispiel.invalid']);
        $ausgabe = Artisan::output();
        $this->assertSame(0, $code, $ausgabe);
        $this->assertStringContainsString('Testmail', $ausgabe);
        $this->assertStringContainsString('Testnachricht wurde', $ausgabe);

        $this->assertSame(1, Artisan::call('smartabrechnen:check-config', ['--send-test-mail' => 'ungueltig']));
    }

    // -----------------------------------------------------------------

    private function produktivkonfiguration(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://beispiel.test');
        config()->set('deploy.trusted_proxies', '*');

        Storage::fake('sftp');
        config()->set('filesystems.default', 'sftp');
        config()->set('filesystems.disks.sftp.host', 'sftp.beispiel.invalid');
        config()->set('filesystems.disks.sftp.username', 'benutzer');
        config()->set('filesystems.disks.sftp.password', 'sehr-geheim');
        config()->set('filesystems.disks.sftp.privateKey', null);

        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', 'smtp.beispiel.invalid');
        config()->set('mail.mailers.smtp.port', 465);
        config()->set('mail.mailers.smtp.username', 'kontakt@beispiel.invalid');
        config()->set('mail.mailers.smtp.password', 'sehr-geheim');
        config()->set('mail.from.address', 'kontakt@beispiel.invalid');

        config()->set('services.stripe.key', 'pk_live_beispiel');
        config()->set('services.stripe.secret', 'sk_live_darf_nicht_erscheinen');
        config()->set('services.stripe.webhook_secret', 'whsec_beispiel');

        // Primaerprovider openai ohne Datenschutzfreigabe: Warnung, kein Aufruf.
        config()->set('ai.primary_provider', 'openai');
        config()->set('ai.data_retention_approved', false);

        // Tageslimit mit Kalkulationsbasis fuer die konfigurierten Modelle.
        // Die Werte sind Testplatzhalter, keine Preisangaben.
        config()->set('ai.max_daily_cost_cent_per_user', 500);
        config()->set('ai.cost_basis_us_cent_per_million_tokens', [
            'gpt-5.6-luna' => ['input' => 1, 'output' => 1],
            'gpt-5.6-terra' => ['input' => 1, 'output' => 1],
        ]);

        $this->app->make(SchedulerHeartbeat::class)->record();
    }

    /**
     * @return list<CheckResult>
     */
    private function ergebnisse(): array
    {
        return $this->app->make(ConfigurationCheck::class)->run();
    }

    /**
     * @param  list<CheckResult>  $ergebnisse
     */
    private function statusVon(array $ergebnisse, string $name): string
    {
        foreach ($ergebnisse as $ergebnis) {
            if ($ergebnis->name === $name) {
                return $ergebnis->status;
            }
        }

        $this->fail('Keine Pruefung mit Namen '.$name);
    }

    /**
     * @param  list<CheckResult>  $ergebnisse
     */
    private function meldungVon(array $ergebnisse, string $name): string
    {
        foreach ($ergebnisse as $ergebnis) {
            if ($ergebnis->name === $name) {
                return $ergebnis->message;
            }
        }

        $this->fail('Keine Pruefung mit Namen '.$name);
    }
}
