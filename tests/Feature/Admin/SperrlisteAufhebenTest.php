<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\EmailSuppressionReason;
use App\Http\Controllers\Admin\CommunicationController;
use App\Mail\SuppressionGuard;
use App\Models\AuditLog;
use App\Models\EmailSuppression;

/**
 * Aufheben einer Adresssperre im Adminbereich (Masterprompt 17.2, 20).
 *
 * Anlass: Ein Ausfall des Postausgangsservers oder ein falsches
 * Postfachpasswort darf Adressen nicht dauerhaft sperren. Faellt es dennoch
 * zu einer Sperre, muss der Betreiber sie mit Begruendung aufheben koennen.
 */
final class SperrlisteAufhebenTest extends AdminTestCase
{
    public function test_die_sperrliste_bietet_das_aufheben_mit_begruendung_an(): void
    {
        app(SuppressionGuard::class)->suppress('gesperrt@beispiel.invalid', EmailSuppressionReason::BOUNCE, 'erinnerung-folgejahr');

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/kommunikation');

        $antwort->assertOk();
        $antwort->assertSee('Sperre aufheben');
        $antwort->assertSee(route('admin.kommunikation.sperre.aufheben'), false);
    }

    public function test_eine_sperre_wird_mit_begruendung_aufgehoben_und_protokolliert(): void
    {
        $sperre = app(SuppressionGuard::class)->suppress('gesperrt@beispiel.invalid', EmailSuppressionReason::BOUNCE, 'erinnerung-folgejahr');
        $admin = $this->interneKennung();
        $grund = 'SMTP-Ausfall am 04.09.2026, keine echte Unzustellbarkeit.';

        $this->actingAs($admin)
            ->post(route('admin.kommunikation.sperre.aufheben'), [
                'email' => 'Gesperrt@Beispiel.invalid',
                'grund' => $grund,
            ])
            ->assertRedirect('/admin/kommunikation')
            ->assertSessionHas('status');

        self::assertFalse(app(SuppressionGuard::class)->isSuppressed('gesperrt@beispiel.invalid'));
        self::assertSame(0, EmailSuppression::query()->count());

        /** @var AuditLog $eintrag */
        $eintrag = AuditLog::query()
            ->where('action', CommunicationController::AUDIT_SPERRE_AUFGEHOBEN)
            ->firstOrFail();

        self::assertSame($admin->getKey(), $eintrag->getAttribute('actor_user_id'));
        self::assertSame($sperre->getKey(), $eintrag->getAttribute('subject_id'));
        self::assertSame($grund, $eintrag->getAttribute('reason'));
    }

    public function test_ohne_begruendung_bleibt_die_sperre_bestehen(): void
    {
        app(SuppressionGuard::class)->suppress('gesperrt@beispiel.invalid', EmailSuppressionReason::BOUNCE, 'test');

        $this->actingAs($this->interneKennung())
            ->from('/admin/kommunikation')
            ->post(route('admin.kommunikation.sperre.aufheben'), [
                'email' => 'gesperrt@beispiel.invalid',
                'grund' => '',
            ])
            ->assertRedirect('/admin/kommunikation')
            ->assertSessionHasErrors('grund');

        self::assertTrue(app(SuppressionGuard::class)->isSuppressed('gesperrt@beispiel.invalid'));
    }

    public function test_eine_unbekannte_adresse_liefert_einen_hinweis(): void
    {
        $this->actingAs($this->interneKennung())
            ->post(route('admin.kommunikation.sperre.aufheben'), [
                'email' => 'frei@beispiel.invalid',
                'grund' => 'Pruefung des Hinweises bei unbekannter Adresse.',
            ])
            ->assertRedirect('/admin/kommunikation')
            ->assertSessionHas('hinweis');

        self::assertSame(0, AuditLog::query()->where('action', CommunicationController::AUDIT_SPERRE_AUFGEHOBEN)->count());
    }

    public function test_kunden_ohne_interne_rolle_koennen_keine_sperre_aufheben(): void
    {
        app(SuppressionGuard::class)->suppress('gesperrt@beispiel.invalid', EmailSuppressionReason::BOUNCE, 'test');
        $kunde = $this->kunde();

        $this->actingAs($kunde['user'])
            ->post(route('admin.kommunikation.sperre.aufheben'), [
                'email' => 'gesperrt@beispiel.invalid',
                'grund' => 'Versuch ohne Berechtigung, muss abgewiesen werden.',
            ])
            ->assertNotFound();

        self::assertTrue(app(SuppressionGuard::class)->isSuppressed('gesperrt@beispiel.invalid'));
    }
}
