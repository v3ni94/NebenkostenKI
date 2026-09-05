<?php

declare(strict_types=1);

namespace Tests\Unit\Reminder;

use App\Application\Reminder\ReminderSchedule;
use App\Enums\ReminderWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Standardplan der Erinnerungen (Masterprompt 17.1).
 */
final class ReminderScheduleTest extends TestCase
{
    private function plan(): ReminderSchedule
    {
        return new ReminderSchedule;
    }

    public function test_standardtermine_liegen_auf_den_vorgegebenen_tagen(): void
    {
        $plan = $this->plan();

        $this->assertSame('15.01.2026', $plan->termin(ReminderWindow::Q1, 2026)->format('d.m.Y'));
        $this->assertSame('15.04.2026', $plan->termin(ReminderWindow::Q2, 2026)->format('d.m.Y'));
        $this->assertSame('15.07.2026', $plan->termin(ReminderWindow::Q3, 2026)->format('d.m.Y'));
        $this->assertSame('01.12.2026', $plan->termin(ReminderWindow::DEZEMBER, 2026)->format('d.m.Y'));
    }

    public function test_termine_werden_in_europe_berlin_gebildet(): void
    {
        $termin = $this->plan()->termin(ReminderWindow::Q1, 2026);

        $this->assertSame('Europe/Berlin', $termin->getTimezone()->getName());
        $this->assertSame('00:00:00', $termin->format('H:i:s'));
    }

    public function test_fenster_wird_zum_termin_erkannt(): void
    {
        $plan = $this->plan();

        $this->assertSame(
            ReminderWindow::Q1,
            $plan->fensterAm(CarbonImmutable::parse('2026-01-15 08:00:00', 'Europe/Berlin'))
        );
        $this->assertSame(
            ReminderWindow::DEZEMBER,
            $plan->fensterAm(CarbonImmutable::parse('2026-12-01 23:30:00', 'Europe/Berlin'))
        );
        $this->assertNull($plan->fensterAm(CarbonImmutable::parse('2026-01-16 08:00:00', 'Europe/Berlin')));
    }

    public function test_termin_ist_je_quartal_konfigurierbar(): void
    {
        config(['smartabrechnen.reminders.q2' => '05-02']);

        $this->assertSame('02.05.2026', $this->plan()->termin(ReminderWindow::Q2, 2026)->format('d.m.Y'));
    }

    public function test_unzulaessige_konfiguration_faellt_auf_den_standard_zurueck(): void
    {
        $plan = $this->plan();

        config(['smartabrechnen.reminders.q1' => '08-15']);
        $this->assertSame('15.01.2026', $plan->termin(ReminderWindow::Q1, 2026)->format('d.m.Y'));

        config(['smartabrechnen.reminders.q3' => 'unsinn']);
        $this->assertSame('15.07.2026', $plan->termin(ReminderWindow::Q3, 2026)->format('d.m.Y'));

        config(['smartabrechnen.reminders.december' => '12-31']);
        $this->assertSame('31.12.2026', $plan->termin(ReminderWindow::DEZEMBER, 2026)->format('d.m.Y'));
    }

    public function test_relevantes_abrechnungsjahr_ist_das_vorjahr(): void
    {
        $plan = $this->plan();

        $this->assertSame(
            2025,
            $plan->abrechnungsjahr(CarbonImmutable::parse('2026-01-15', 'Europe/Berlin'))
        );
        $this->assertSame(
            2025,
            $plan->abrechnungsjahr(CarbonImmutable::parse('2026-12-01', 'Europe/Berlin'))
        );
    }

    public function test_plan_nennt_alle_vier_termine(): void
    {
        $plan = $this->plan()->plan(2027);

        $this->assertSame(
            ['Q1' => '15.01.2027', 'Q2' => '15.04.2027', 'Q3' => '15.07.2027', 'DEZEMBER' => '01.12.2027'],
            $plan
        );
    }

    public function test_erinnerungen_koennen_zentral_abgeschaltet_werden(): void
    {
        config(['smartabrechnen.reminders.enabled' => false]);

        $this->assertFalse($this->plan()->istAktiv());
    }
}
