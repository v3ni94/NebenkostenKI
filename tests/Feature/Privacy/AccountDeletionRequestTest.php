<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Application\Privacy\AccountDeletionWorkflow;
use App\Enums\EmailSuppressionReason;
use App\Mail\LoeschantragEingegangenMail;
use App\Mail\LoeschantragErinnerungMail;
use App\Mail\SuppressionGuard;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Antrag, Frist und Ruecknahme des Konto-Loeschworkflows (Masterprompt 19).
 */
final class AccountDeletionRequestTest extends PrivacyTestCase
{
    /**
     * Passwort der Testnutzer aus der UserFactory.
     */
    private const PASSWORT = 'geheimes-testpasswort';

    public function test_loeschantrag_setzt_die_frist_und_wird_protokolliert(): void
    {
        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

        $antwort = $this->actingAs($a['user'])->post(route('portal.datenschutz.loeschung'), [
            'bestaetigung' => '1',
            'current_password' => self::PASSWORT,
        ]);

        $antwort->assertRedirect(route('portal.datenschutz.show'));

        $zustand = $this->workflow()->state($a['user']);

        self::assertTrue($zustand->pending);
        self::assertSame(30, $zustand->graceDays);
        self::assertSame('01.10.2026', $zustand->dueAtLabel());

        $this->assertDatabaseHas('audit_logs', [
            'action' => AccountDeletionWorkflow::ACTION_REQUESTED,
            'actor_user_id' => $a['user']->getKey(),
            'organization_id' => $a['organization']->getKey(),
        ]);

        Carbon::setTestNow();
    }

    public function test_loeschantrag_ohne_bestaetigung_wird_abgewiesen(): void
    {
        $a = $this->mandant('A');

        $antwort = $this->actingAs($a['user'])->post(route('portal.datenschutz.loeschung'), [
            'current_password' => self::PASSWORT,
        ]);

        $antwort->assertSessionHasErrors('bestaetigung');
        self::assertFalse($this->workflow()->state($a['user'])->pending);
    }

    public function test_loeschantrag_verlangt_das_aktuelle_passwort(): void
    {
        $a = $this->mandant('A');

        // Ohne Passwort: eine uebernommene Sitzung darf die Loeschung nicht
        // anstossen koennen.
        $ohne = $this->actingAs($a['user'])->post(route('portal.datenschutz.loeschung'), [
            'bestaetigung' => '1',
        ]);

        $ohne->assertSessionHasErrors('current_password');
        self::assertFalse($this->workflow()->state($a['user'])->pending);

        $falsch = $this->actingAs($a['user'])->post(route('portal.datenschutz.loeschung'), [
            'bestaetigung' => '1',
            'current_password' => 'falsches-passwort-2026',
        ]);

        $falsch->assertSessionHasErrors('current_password');
        self::assertFalse($this->workflow()->state($a['user'])->pending);
        self::assertSame(0, $this->antraege($a['user']->getKey()));
    }

    public function test_loeschantrag_sendet_eine_kritische_bestaetigungsmail_mit_termin(): void
    {
        Mail::fake();

        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

        $this->actingAs($a['user'])->post(route('portal.datenschutz.loeschung'), [
            'bestaetigung' => '1',
            'current_password' => self::PASSWORT,
        ]);

        Mail::assertSent(LoeschantragEingegangenMail::class, function (LoeschantragEingegangenMail $mail) use ($a): bool {
            $daten = $mail->daten();

            return $mail->hasTo((string) $a['user']->getAttribute('email'))
                && $mail->istKritisch()
                && $daten['faelligAm'] === '01.10.2026'
                && $daten['fristTage'] === 30;
        });

        // Der Versand wird als Kontonachricht protokolliert.
        $this->assertDatabaseHas('email_messages', [
            'user_id' => $a['user']->getKey(),
            'template' => 'loeschantrag-eingegangen',
        ]);

        Carbon::setTestNow();
    }

    public function test_bestaetigungsmail_geht_auch_an_eine_gesperrte_adresse(): void
    {
        Mail::fake();

        $a = $this->mandant('A');
        $adresse = (string) $a['user']->getAttribute('email');

        app(SuppressionGuard::class)->suppress($adresse, EmailSuppressionReason::BOUNCE, 'test');

        $this->workflow()->request($a['user'], $a['organization']);

        Mail::assertSent(LoeschantragEingegangenMail::class);
    }

    public function test_erinnerung_wird_einige_tage_vor_der_loeschung_genau_einmal_versendet(): void
    {
        Mail::fake();

        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->workflow()->request($a['user'], $a['organization']);

        // Frueh in der Frist: noch keine Erinnerung.
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00'));
        self::assertSame(0, $this->workflow()->remindDue());
        Mail::assertNotSent(LoeschantragErinnerungMail::class);

        // Innerhalb des Vorlaufs: genau eine Erinnerung.
        Carbon::setTestNow(Carbon::parse('2026-09-27 10:00:00'));
        self::assertSame(1, $this->workflow()->remindDue());
        self::assertSame(0, $this->workflow()->remindDue());

        Mail::assertSent(LoeschantragErinnerungMail::class, 1);
        Mail::assertSent(LoeschantragErinnerungMail::class, function (LoeschantragErinnerungMail $mail) use ($a): bool {
            $daten = $mail->daten();

            return $mail->hasTo((string) $a['user']->getAttribute('email'))
                && $mail->istKritisch()
                && $daten['faelligAm'] === '01.10.2026';
        });

        $this->assertDatabaseHas('audit_logs', [
            'action' => AccountDeletionWorkflow::ACTION_REMINDED,
            'actor_user_id' => $a['user']->getKey(),
        ]);

        // Der Erinnerungseintrag aendert den Zustand des Antrags nicht.
        self::assertTrue($this->workflow()->state($a['user'])->pending);

        Carbon::setTestNow();
    }

    public function test_nach_ruecknahme_wird_nicht_mehr_erinnert(): void
    {
        Mail::fake();

        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->workflow()->request($a['user'], $a['organization']);
        $this->workflow()->withdraw($a['user'], $a['organization']);

        Carbon::setTestNow(Carbon::parse('2026-09-28 10:00:00'));
        self::assertSame(0, $this->workflow()->remindDue());
        Mail::assertNotSent(LoeschantragErinnerungMail::class);

        Carbon::setTestNow();
    }

    public function test_der_loeschlauf_versendet_die_erinnerungen(): void
    {
        Mail::fake();

        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->workflow()->request($a['user'], $a['organization']);

        Carbon::setTestNow(Carbon::parse('2026-09-28 10:00:00'));

        $this->artisan('smartabrechnen:execute-account-deletions')
            ->expectsOutputToContain('1 Erinnerungen vor der Kontolöschung versendet.')
            ->assertExitCode(0);

        Mail::assertSent(LoeschantragErinnerungMail::class, 1);
        $this->assertDatabaseHas('users', ['id' => $a['user']->getKey()]);

        Carbon::setTestNow();
    }

    public function test_zweiter_antrag_verdoppelt_die_frist_nicht(): void
    {
        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->workflow()->request($a['user'], $a['organization']);

        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));
        $this->workflow()->request($a['user'], $a['organization']);

        self::assertSame('01.10.2026', $this->workflow()->state($a['user'])->dueAtLabel());
        self::assertSame(1, $this->antraege($a['user']->getKey()));

        Carbon::setTestNow();
    }

    public function test_ruecknahme_innerhalb_der_frist_beendet_den_antrag(): void
    {
        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->workflow()->request($a['user'], $a['organization']);

        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00'));

        $antwort = $this->actingAs($a['user'])
            ->delete(route('portal.datenschutz.loeschung.zuruecknehmen'));

        $antwort->assertRedirect(route('portal.datenschutz.show'));

        self::assertFalse($this->workflow()->state($a['user'])->pending);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AccountDeletionWorkflow::ACTION_WITHDRAWN,
            'actor_user_id' => $a['user']->getKey(),
        ]);

        Carbon::setTestNow();
    }

    public function test_ruecknahme_nach_ablauf_der_frist_ist_nicht_mehr_moeglich(): void
    {
        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->workflow()->request($a['user'], $a['organization']);

        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00'));

        self::assertFalse($this->workflow()->withdraw($a['user'], $a['organization']));
        self::assertTrue($this->workflow()->state($a['user'])->isDue());

        Carbon::setTestNow();
    }

    public function test_ruecknahme_ohne_antrag_meldet_dies_sachlich(): void
    {
        $a = $this->mandant('A');

        $antwort = $this->actingAs($a['user'])
            ->delete(route('portal.datenschutz.loeschung.zuruecknehmen'));

        $antwort->assertRedirect(route('portal.datenschutz.show'));

        $status = session('status');
        self::assertIsString($status);
        self::assertStringContainsString('kein zurücknehmbarer Löschantrag', $status);
    }

    public function test_nach_ablauf_der_frist_ist_der_antrag_faellig(): void
    {
        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->workflow()->request($a['user'], $a['organization']);

        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00'));
        self::assertFalse($this->workflow()->state($a['user'])->isDue());
        self::assertSame([], $this->workflow()->due());

        Carbon::setTestNow(Carbon::parse('2026-10-01 11:00:00'));
        self::assertTrue($this->workflow()->state($a['user'])->isDue());
        self::assertCount(1, $this->workflow()->due());

        Carbon::setTestNow();
    }

    public function test_zurueckgenommener_antrag_wird_nicht_faellig(): void
    {
        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->workflow()->request($a['user'], $a['organization']);

        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00:00'));
        $this->workflow()->withdraw($a['user'], $a['organization']);

        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00'));
        self::assertSame([], $this->workflow()->due());

        Carbon::setTestNow();
    }

    public function test_frist_ist_konfigurierbar_und_begrenzt(): void
    {
        config(['smartabrechnen.retention.account_deletion_grace_days' => 14]);
        self::assertSame(14, $this->workflow()->graceDays());

        config(['smartabrechnen.retention.account_deletion_grace_days' => 1]);
        self::assertSame(AccountDeletionWorkflow::MIN_GRACE_DAYS, $this->workflow()->graceDays());

        config(['smartabrechnen.retention.account_deletion_grace_days' => 400]);
        self::assertSame(AccountDeletionWorkflow::MAX_GRACE_DAYS, $this->workflow()->graceDays());

        config(['smartabrechnen.retention.account_deletion_grace_days' => null]);
        self::assertSame(AccountDeletionWorkflow::DEFAULT_GRACE_DAYS, $this->workflow()->graceDays());
    }

    public function test_geaenderte_konfiguration_verschiebt_einen_laufenden_antrag_nicht(): void
    {
        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->workflow()->request($a['user'], $a['organization']);

        config(['smartabrechnen.retention.account_deletion_grace_days' => 90]);

        self::assertSame('01.10.2026', $this->workflow()->state($a['user'])->dueAtLabel());

        Carbon::setTestNow();
    }

    public function test_antrag_eines_mandanten_beruehrt_den_anderen_nicht(): void
    {
        $a = $this->mandant('A');
        $b = $this->mandant('B');

        $this->workflow()->request($a['user'], $a['organization']);

        self::assertTrue($this->workflow()->state($a['user'])->pending);
        self::assertFalse($this->workflow()->state($b['user'])->pending);
    }

    private function workflow(): AccountDeletionWorkflow
    {
        /** @var AccountDeletionWorkflow $workflow */
        $workflow = app(AccountDeletionWorkflow::class);

        return $workflow;
    }

    private function antraege(mixed $userId): int
    {
        return AuditLog::query()
            ->where('action', AccountDeletionWorkflow::ACTION_REQUESTED)
            ->where('actor_user_id', $userId)
            ->count();
    }
}
