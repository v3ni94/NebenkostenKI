<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\BillingRunStatus;
use App\Enums\EmailStatus;
use App\Enums\EmailSuppressionReason;
use App\Enums\PaymentStatus;
use App\Enums\ReminderStatus;
use App\Enums\ReminderWindow;
use App\Models\BillingRun;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\Payment;
use App\Models\ReminderEvent;

/**
 * Kennzahlen, E-Mail-Status, Erinnerungen und Versionen (Masterprompt 20).
 *
 * Die Kennzahlen sind datensparsam und entstehen ausschliesslich aus den
 * vorhandenen Fachdaten. Es gibt keinen Analysetracker und kein Zaehlpixel.
 */
final class KennzahlenUndKommunikationTest extends AdminTestCase
{
    public function test_der_umsatz_eines_zeitraums_wird_ausgewiesen(): void
    {
        Payment::factory()->create([
            'status' => PaymentStatus::BEZAHLT,
            'amount_cent' => 7470,
            'paid_at' => now(),
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/kennzahlen');

        $antwort->assertOk();
        $antwort->assertSee('74,70 EUR');
    }

    public function test_die_conversion_von_vorschau_zu_zahlung_wird_berechnet(): void
    {
        BillingRun::factory()->count(3)->create(['status' => BillingRunStatus::PREVIEW_READY]);
        BillingRun::factory()->create(['status' => BillingRunStatus::FINALIZED]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/kennzahlen');

        $antwort->assertOk();
        $antwort->assertSee('25 Prozent');
        $antwort->assertSee('1 von 4 Läufen mit Vorschau');
    }

    public function test_abbruchschritte_werden_ausgewiesen(): void
    {
        BillingRun::factory()->create([
            'status' => BillingRunStatus::REVIEW_REQUIRED,
            'wizard_step' => 6,
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/kennzahlen');

        $antwort->assertOk();
        $antwort->assertSee('Abbruchschritte offener Läufe');
        $antwort->assertSee('Schritt 6');
    }

    public function test_die_kennzahlen_enthalten_kein_externes_tracking(): void
    {
        $antwort = $this->actingAs($this->interneKennung())->get('/admin/kennzahlen');

        $antwort->assertOk();
        $antwort->assertDontSee('googletagmanager');
        $antwort->assertDontSee('google-analytics');
        $antwort->assertDontSee('matomo');
        $antwort->assertDontSee('<img src="https://');
    }

    public function test_der_emailstatus_und_die_sperrliste_werden_angezeigt(): void
    {
        EmailMessage::factory()->create([
            'template' => 'transaktion.zahlung-bestaetigt',
            'status' => EmailStatus::FEHLGESCHLAGEN,
            'error_code' => 'SMTP_TIMEOUT',
            'failed_at' => now(),
        ]);

        EmailSuppression::query()->create([
            'email' => 'gesperrt@beispiel.invalid',
            'reason' => EmailSuppressionReason::BOUNCE,
            'suppressed_at' => now(),
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/kommunikation');

        $antwort->assertOk();
        $antwort->assertSee('SMTP_TIMEOUT');
        $antwort->assertSee('gesperrt@beispiel.invalid');
        $antwort->assertSee('Sperrliste');
        $antwort->assertSee('transaktion.zahlung-bestaetigt');
    }

    public function test_der_erinnerungsplan_und_anstehende_termine_werden_angezeigt(): void
    {
        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create();

        ReminderEvent::factory()->create([
            'organization_id' => $lauf->getAttribute('organization_id'),
            'billing_year' => 2025,
            'reminder_window' => ReminderWindow::Q1,
            'status' => ReminderStatus::GEPLANT,
            'scheduled_for' => now()->addDays(30),
            'recipient_email' => 'erinnerung@beispiel.invalid',
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/kommunikation');

        $antwort->assertOk();
        $antwort->assertSee('Erinnerungsplan des laufenden Jahres');
        $antwort->assertSee('Anstehende Erinnerungen');
        $antwort->assertSee('erinnerung@beispiel.invalid');
    }

    public function test_die_versionsuebersicht_zeigt_die_regelstaende(): void
    {
        $antwort = $this->actingAs($this->interneKennung())->get('/admin/versionen');

        $antwort->assertOk();
        $antwort->assertSee('Regelstände');
        $antwort->assertSee('2020.1');
        $antwort->assertSee('2023.1');
    }

    public function test_die_zahlungsuebersicht_zeigt_zahlungen_und_erstattungen(): void
    {
        Payment::factory()->create([
            'status' => PaymentStatus::TEILWEISE_ERSTATTET,
            'amount_cent' => 7470,
            'refunded_amount_cent' => 2490,
            'refunded_at' => now(),
            'paid_at' => now()->subDay(),
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/zahlungen');

        $antwort->assertOk();
        $antwort->assertSee('74,70 EUR');
        $antwort->assertSee('24,90 EUR');
    }
}
