<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\InvoiceStatus;
use App\Enums\ProcessingJobStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Admin\UserController;
use App\Models\Invoice;
use App\Models\ProcessingJob;
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

    public function test_lesende_routen_bleiben_fuer_jede_rolle_erreichbar(): void
    {
        foreach ([AdminRole::SUPPORT, AdminRole::FINANCE] as $rolle) {
            $this->actingAs($this->interneKennung($rolle))->get('/admin/nutzer')->assertOk();
            $this->actingAs($this->interneKennung($rolle))->get('/admin/zahlungen')->assertOk();
        }
    }
}
