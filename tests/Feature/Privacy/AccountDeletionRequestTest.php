<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Application\Privacy\AccountDeletionWorkflow;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;

/**
 * Antrag, Frist und Ruecknahme des Konto-Loeschworkflows (Masterprompt 19).
 */
final class AccountDeletionRequestTest extends PrivacyTestCase
{
    public function test_loeschantrag_setzt_die_frist_und_wird_protokolliert(): void
    {
        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

        $antwort = $this->actingAs($a['user'])->post(route('portal.datenschutz.loeschung'), [
            'bestaetigung' => '1',
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

        $antwort = $this->actingAs($a['user'])->post(route('portal.datenschutz.loeschung'), []);

        $antwort->assertSessionHasErrors('bestaetigung');
        self::assertFalse($this->workflow()->state($a['user'])->pending);
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
