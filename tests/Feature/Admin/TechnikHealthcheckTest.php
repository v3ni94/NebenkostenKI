<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Application\Admin\SystemHealthCheck;

/**
 * Technische Healthchecks (Masterprompt 20, 22).
 *
 * VERBINDLICH: Es wird niemals ein Secret ausgegeben, auch nicht teilweise
 * maskiert. Der Test prueft die Antwort ausdruecklich gegen die konfigurierten
 * Werte.
 */
final class TechnikHealthcheckTest extends AdminTestCase
{
    /**
     * Frei erfundene Platzhalter. Keine echten Zugangsdaten.
     */
    private const string SFTP_HOST = 'sftp.testhost.invalid';

    private const string SFTP_USER = 'testbenutzer4711';

    private const string SFTP_PASSWORT = 'testpasswort-geheim-0001';

    private const string SFTP_ROOT = '/kunden/testpfad/ergebnisse';

    private const string MAIL_PASSWORT = 'mailpasswort-geheim-0002';

    private function konfiguriereZugaenge(): void
    {
        config()->set('filesystems.disks.sftp.host', self::SFTP_HOST);
        config()->set('filesystems.disks.sftp.username', self::SFTP_USER);
        config()->set('filesystems.disks.sftp.password', self::SFTP_PASSWORT);
        config()->set('filesystems.disks.sftp.root', self::SFTP_ROOT);
        config()->set('filesystems.disks.sftp.timeout', 1);

        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', 'smtp.testhost.invalid');
        config()->set('mail.mailers.smtp.port', 465);
        config()->set('mail.mailers.smtp.username', 'kontakt@testhost.invalid');
        config()->set('mail.mailers.smtp.password', self::MAIL_PASSWORT);
        config()->set('mail.from.address', 'kontakt@testhost.invalid');
    }

    public function test_die_seite_zeigt_die_proben_fuer_datenbank_storage_sftp_und_mail(): void
    {
        $antwort = $this->actingAs($this->interneKennung())->get('/admin/technik');

        $antwort->assertOk();
        $antwort->assertSee('Datenbank');
        $antwort->assertSee('Storage');
        $antwort->assertSee('SFTP');
        $antwort->assertSee('Mail');
    }

    public function test_die_antwort_enthaelt_kein_secret_und_keinen_zugangsweg(): void
    {
        $this->konfiguriereZugaenge();

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/technik');

        $antwort->assertOk();
        $antwort->assertDontSee(self::SFTP_PASSWORT);
        $antwort->assertDontSee(self::MAIL_PASSWORT);
        $antwort->assertDontSee(self::SFTP_HOST);
        $antwort->assertDontSee(self::SFTP_USER);
        $antwort->assertDontSee(self::SFTP_ROOT);

        // Auch keine Teilmaskierung eines Passworts.
        $antwort->assertDontSee(substr(self::SFTP_PASSWORT, 0, 8));
        $antwort->assertDontSee(substr(self::MAIL_PASSWORT, 0, 8));
    }

    public function test_die_datenbankprobe_nennt_die_tatsaechliche_serverversion(): void
    {
        $probe = app(SystemHealthCheck::class)->database();

        self::assertTrue($probe->configured);
        self::assertTrue($probe->reachable);
        self::assertNotNull($probe->version);
    }

    public function test_die_versionspruefung_erkennt_unterstuetzte_mariadb_versionen(): void
    {
        self::assertTrue(SystemHealthCheck::isSupportedMariaDbVersion('10.11.6-MariaDB'));
        self::assertTrue(SystemHealthCheck::isSupportedMariaDbVersion('11.4.2-MariaDB'));
        self::assertTrue(SystemHealthCheck::isSupportedMariaDbVersion('11.8.0-MariaDB-log'));
    }

    public function test_die_versionspruefung_lehnt_nicht_unterstuetzte_versionen_ab(): void
    {
        self::assertFalse(SystemHealthCheck::isSupportedMariaDbVersion('10.6.16-MariaDB'));
        self::assertFalse(SystemHealthCheck::isSupportedMariaDbVersion('8.0.36'));
        self::assertFalse(SystemHealthCheck::isSupportedMariaDbVersion('12.0.0-MariaDB'));
    }

    public function test_ohne_hinterlegtes_sftp_ziel_wird_nicht_verbunden(): void
    {
        config()->set('filesystems.disks.sftp.host', null);
        config()->set('filesystems.disks.sftp.username', null);

        $probe = app(SystemHealthCheck::class)->sftp();

        self::assertFalse($probe->configured);
        self::assertNull($probe->reachable);
        self::assertSame('Nicht konfiguriert', $probe->statusLabel());
    }

    public function test_die_storage_probe_schreibt_und_loescht_eine_probedatei(): void
    {
        $probe = app(SystemHealthCheck::class)->storage();

        self::assertTrue($probe->reachable);
        self::assertNull($probe->errorClass);
    }

    public function test_die_mailprobe_baut_keine_verbindung_auf(): void
    {
        $this->konfiguriereZugaenge();

        $probe = app(SystemHealthCheck::class)->mail();

        self::assertTrue($probe->configured);
        self::assertNull($probe->reachable);
        self::assertStringNotContainsString(self::MAIL_PASSWORT, $probe->note);
    }

    public function test_eine_unvollstaendige_mailkonfiguration_wird_gemeldet_ohne_werte(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', null);
        config()->set('mail.mailers.smtp.username', 'kontakt@testhost.invalid');
        config()->set('mail.mailers.smtp.password', self::MAIL_PASSWORT);

        $probe = app(SystemHealthCheck::class)->mail();

        self::assertFalse($probe->configured);
        self::assertStringContainsString('host', $probe->note);
        self::assertStringNotContainsString('kontakt@testhost.invalid', $probe->note);
        self::assertStringNotContainsString(self::MAIL_PASSWORT, $probe->note);
    }
}
