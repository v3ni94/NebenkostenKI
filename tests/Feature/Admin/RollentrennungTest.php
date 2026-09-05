<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\BillingRunStatus;
use App\Enums\EmailSuppressionReason;
use App\Enums\InvoiceStatus;
use App\Enums\ProcessingJobStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Admin\CommunicationController;
use App\Http\Controllers\Admin\UserController;
use App\Mail\SuppressionGuard;
use App\Models\BillingRun;
use App\Models\EmailMessage;
use App\Models\Invoice;
use App\Models\ProcessingJob;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Rollentrennung im Adminbereich (ARCHITECTURE.md T10).
 *
 * Rechtematrix, verbindlich:
 *
 *   Handlung                              ADMIN  SUPPORT  FINANCE
 *   Lesende Adminrouten                     ja     ja       ja
 *   Kundenkonto sperren, entsperren,
 *   Passwort-Link, Zweitfaktor-Reset        ja     ja       nein
 *   Interne Kennung sperren, entsperren,
 *   Passwort-Link, Zweitfaktor-Reset        ja     nein     nein
 *   Storno einer Leistungsrechnung          ja     nein     ja
 *   Teiljobs und Loeschungen wiederholen    ja     ja       nein
 *   Zahlungsnachlauf (Finalisierung und
 *   Rechnung nachholen)                     ja     nein     ja
 *   Sperrlisteneintrag aufheben             ja     ja       nein
 */
final class RollentrennungTest extends AdminTestCase
{
    private const GRUND = 'Missbrauchsverdacht nach Meldung, Ticket 4720.';

    public function test_eine_finanzkennung_darf_keine_konten_sperren(): void
    {
        $kunde = $this->kunde();

        $this->actingAs($this->interneKennung(AdminRole::FINANCE))
            ->post('/admin/nutzer/'.$kunde['user']->getKey().'/sperren', ['grund' => self::GRUND])
            ->assertForbidden();

        self::assertSame(
            UserStatus::AKTIV,
            User::query()->findOrFail($kunde['user']->getKey())->getAttribute('status'),
        );
    }

    public function test_eine_finanzkennung_darf_keinen_passwort_link_ausloesen(): void
    {
        Notification::fake();
        $kunde = $this->kunde();

        $this->actingAs($this->interneKennung(AdminRole::FINANCE))
            ->post('/admin/nutzer/'.$kunde['user']->getKey().'/passwort')
            ->assertForbidden();

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $kunde['user']->getAttribute('email'),
        ]);
    }

    public function test_eine_supportkennung_darf_kundenkonten_sperren(): void
    {
        $kunde = $this->kunde();

        $this->actingAs($this->interneKennung(AdminRole::SUPPORT))
            ->post('/admin/nutzer/'.$kunde['user']->getKey().'/sperren', ['grund' => self::GRUND])
            ->assertRedirect('/admin/nutzer');

        self::assertSame(
            UserStatus::GESPERRT,
            User::query()->findOrFail($kunde['user']->getKey())->getAttribute('status'),
        );
    }

    public function test_eine_supportkennung_darf_keine_interne_kennung_sperren(): void
    {
        $admin = $this->interneKennung(AdminRole::ADMIN);

        $antwort = $this->actingAs($this->interneKennung(AdminRole::SUPPORT))
            ->post('/admin/nutzer/'.$admin->getKey().'/sperren', ['grund' => self::GRUND]);

        $antwort->assertRedirect('/admin/nutzer');
        $antwort->assertSessionHas('hinweis', UserController::MELDUNG_INTERNE_KENNUNG);

        self::assertSame(
            UserStatus::AKTIV,
            User::query()->findOrFail($admin->getKey())->getAttribute('status'),
        );
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.user.locked']);
    }

    public function test_eine_supportkennung_darf_keinen_passwort_link_fuer_eine_interne_kennung_ausloesen(): void
    {
        Notification::fake();
        $admin = $this->interneKennung(AdminRole::ADMIN);

        $this->actingAs($this->interneKennung(AdminRole::SUPPORT))
            ->post('/admin/nutzer/'.$admin->getKey().'/passwort')
            ->assertSessionHas('hinweis', UserController::MELDUNG_INTERNE_KENNUNG);

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $admin->getAttribute('email'),
        ]);
    }

    public function test_die_administration_darf_eine_andere_interne_kennung_sperren(): void
    {
        $support = $this->interneKennung(AdminRole::SUPPORT);

        $this->actingAs($this->interneKennung(AdminRole::ADMIN))
            ->post('/admin/nutzer/'.$support->getKey().'/sperren', ['grund' => self::GRUND])
            ->assertRedirect('/admin/nutzer');

        self::assertSame(
            UserStatus::GESPERRT,
            User::query()->findOrFail($support->getKey())->getAttribute('status'),
        );
    }

    public function test_eine_supportkennung_darf_keine_stornorechnung_erzeugen(): void
    {
        $this->bestaetigteBetreiberstammdaten();

        /** @var Invoice $rechnung */
        $rechnung = Invoice::factory()->create([
            'number' => 'NK-2026-000041',
            'status' => InvoiceStatus::BEZAHLT,
        ]);

        $this->actingAs($this->interneKennung(AdminRole::SUPPORT))
            ->get('/admin/rechnungen/'.$rechnung->getKey().'/storno')
            ->assertForbidden();

        $this->actingAs($this->interneKennung(AdminRole::SUPPORT))
            ->post('/admin/rechnungen/'.$rechnung->getKey().'/storno', [
                'grund' => 'Korrektur nach Rücksprache mit dem Kunden.',
                'freigabe_geschaeftsfuehrung' => '1',
            ])
            ->assertForbidden();

        self::assertSame(0, Invoice::query()->whereNotNull('cancels_invoice_id')->count());
        self::assertSame(
            InvoiceStatus::BEZAHLT,
            Invoice::query()->findOrFail($rechnung->getKey())->getAttribute('status'),
        );
    }

    public function test_eine_finanzkennung_darf_eine_stornorechnung_erzeugen(): void
    {
        $this->bestaetigteBetreiberstammdaten();

        /** @var Invoice $rechnung */
        $rechnung = Invoice::factory()->create([
            'number' => 'NK-2026-000042',
            'status' => InvoiceStatus::BEZAHLT,
        ]);

        $this->actingAs($this->interneKennung(AdminRole::FINANCE))
            ->post('/admin/rechnungen/'.$rechnung->getKey().'/storno', [
                'grund' => 'Korrektur nach Rücksprache mit dem Kunden.',
                'freigabe_geschaeftsfuehrung' => '1',
            ])
            ->assertRedirect('/admin/zahlungen');

        self::assertSame(1, Invoice::query()->where('cancels_invoice_id', $rechnung->getKey())->count());
    }

    public function test_eine_finanzkennung_darf_keine_teiljobs_und_loeschungen_wiederholen(): void
    {
        /** @var ProcessingJob $job */
        $job = ProcessingJob::factory()->create([
            'status' => ProcessingJobStatus::FEHLGESCHLAGEN,
        ]);

        $this->actingAs($this->interneKennung(AdminRole::FINANCE))
            ->post('/admin/verarbeitung/jobs/'.$job->getKey().'/wiederholen')
            ->assertForbidden();

        self::assertSame(
            ProcessingJobStatus::FEHLGESCHLAGEN,
            ProcessingJob::query()->findOrFail($job->getKey())->getAttribute('status'),
        );

        $this->actingAs($this->interneKennung(AdminRole::FINANCE))
            ->post('/admin/datenschutz/loeschungen/wiederholen')
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.deletion.retried']);
    }

    public function test_eine_supportkennung_darf_loeschungen_wiederholen(): void
    {
        $this->actingAs($this->interneKennung(AdminRole::SUPPORT))
            ->post('/admin/datenschutz/loeschungen/wiederholen')
            ->assertRedirect('/admin/datenschutz');

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.deletion.retried']);
    }

    public function test_eine_supportkennung_darf_den_zahlungsnachlauf_nicht_ausloesen(): void
    {
        $lauf = $this->bezahlterLaufOhneFinalisierung();

        $this->actingAs($this->interneKennung(AdminRole::SUPPORT))
            ->post('/admin/zahlungsnachlauf/'.$lauf->getKey().'/finalisieren')
            ->assertForbidden();

        $this->actingAs($this->interneKennung(AdminRole::SUPPORT))
            ->post('/admin/zahlungsnachlauf/'.$lauf->getKey().'/rechnung')
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.billing_run.finalization_requested']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.invoice.late_issue_requested']);
        self::assertSame(
            BillingRunStatus::PAID,
            BillingRun::query()->findOrFail($lauf->getKey())->getAttribute('status'),
        );
    }

    public function test_eine_finanzkennung_erreicht_den_zahlungsnachlauf(): void
    {
        $lauf = $this->bezahlterLaufOhneFinalisierung();

        // Das Gate laesst die Finanzkennung durch. Ob die Finalisierung
        // fachlich gelingt, prueft ZahlungsnachlaufTest; hier zaehlt allein,
        // dass die Handlung nicht mit 403 abgewiesen wird und protokolliert ist.
        $this->actingAs($this->interneKennung(AdminRole::FINANCE))
            ->post('/admin/zahlungsnachlauf/'.$lauf->getKey().'/finalisieren')
            ->assertRedirect('/admin/zahlungsnachlauf');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.billing_run.finalization_requested',
            'subject_id' => $lauf->getKey(),
        ]);
    }

    public function test_eine_finanzkennung_darf_keine_sperre_aufheben(): void
    {
        app(SuppressionGuard::class)->suppress('gesperrt@beispiel.invalid', EmailSuppressionReason::BOUNCE, 'erinnerung-folgejahr');

        $this->actingAs($this->interneKennung(AdminRole::FINANCE))
            ->post(route('admin.kommunikation.sperre.aufheben'), [
                'email' => 'gesperrt@beispiel.invalid',
                'grund' => 'SMTP-Ausfall am 04.09.2026, keine echte Unzustellbarkeit.',
            ])
            ->assertForbidden();

        self::assertTrue(app(SuppressionGuard::class)->isSuppressed('gesperrt@beispiel.invalid'));
        $this->assertDatabaseMissing('audit_logs', ['action' => CommunicationController::AUDIT_SPERRE_AUFGEHOBEN]);
    }

    public function test_eine_finanzkennung_darf_keine_nachricht_erneut_senden(): void
    {
        $nachricht = EmailMessage::factory()->create();

        $this->actingAs($this->interneKennung(AdminRole::FINANCE))
            ->post(route('admin.kommunikation.nachricht.erneut', ['emailMessage' => $nachricht->getKey()]), [
                'grund' => 'Kunde bittet um erneute Zusendung der Bestaetigung.',
            ])
            ->assertForbidden();
    }

    public function test_eine_supportkennung_darf_eine_sperre_aufheben(): void
    {
        app(SuppressionGuard::class)->suppress('gesperrt@beispiel.invalid', EmailSuppressionReason::BOUNCE, 'erinnerung-folgejahr');

        $this->actingAs($this->interneKennung(AdminRole::SUPPORT))
            ->post(route('admin.kommunikation.sperre.aufheben'), [
                'email' => 'gesperrt@beispiel.invalid',
                'grund' => 'SMTP-Ausfall am 04.09.2026, keine echte Unzustellbarkeit.',
            ])
            ->assertRedirect('/admin/kommunikation');

        self::assertFalse(app(SuppressionGuard::class)->isSuppressed('gesperrt@beispiel.invalid'));
    }

    /**
     * Bezahlter Lauf, dessen Finalisierung noch offen ist. Die Stammdaten
     * reichen fuer die Rechtepruefung; die fachliche Finalisierung prueft
     * ZahlungsnachlaufTest.
     */
    private function bezahlterLaufOhneFinalisierung(): BillingRun
    {
        $kunde = $this->kunde();

        /** @var Property $objekt */
        $objekt = Property::factory()->create([
            'organization_id' => $kunde['organization']->getKey(),
            'created_by_user_id' => $kunde['user']->getKey(),
        ]);

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $kunde['organization']->getKey(),
            'property_id' => $objekt->getKey(),
            'created_by_user_id' => $kunde['user']->getKey(),
            'status' => BillingRunStatus::PAID,
            'paid_at' => now()->subHour(),
        ]);

        return $lauf;
    }

    public function test_lesende_routen_bleiben_fuer_jede_rolle_erreichbar(): void
    {
        foreach ([AdminRole::SUPPORT, AdminRole::FINANCE] as $rolle) {
            $this->actingAs($this->interneKennung($rolle))->get('/admin/nutzer')->assertOk();
            $this->actingAs($this->interneKennung($rolle))->get('/admin/zahlungen')->assertOk();
        }
    }
}
