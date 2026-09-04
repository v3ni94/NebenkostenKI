<?php

declare(strict_types=1);

namespace App\Application\Reminder;

use App\Enums\ReminderWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Standardplan der automatischen Erinnerungen (Masterprompt 17.1).
 *
 *   Q1        Standard 15. Januar
 *   Q2        Standard 15. April
 *   Q3        Standard 15. Juli
 *   Dezember  Standard 1. Dezember
 *
 * Die Termine sind ueber config('smartabrechnen.reminders') konfigurierbar, die
 * Quartalstermine ausdruecklich nur innerhalb des jeweiligen Quartals. Eine
 * unzulaessige Konfiguration wird nicht stillschweigend uebernommen, sondern
 * durch den Standardwert ersetzt. Sonst koennte ein Tippfehler im Adminbereich
 * eine Erinnerung in ein falsches Quartal verschieben.
 *
 * Zeitzone ist verbindlich Europe/Berlin. Gespeichert wird in der
 * Anwendungszeitzone Europe/Berlin (ADR-018).
 *
 * Das relevante Abrechnungsjahr ist immer das Vorjahr des Kalenderjahres, in
 * dem die Erinnerung faellig wird. Am 01.12.2026 wird also an die Abrechnung
 * fuer 2025 erinnert.
 */
final class ReminderSchedule
{
    public const ZEITZONE = 'Europe/Berlin';

    /**
     * Zulaessige Monate je Fenster und der jeweilige Standardtermin.
     *
     * @var array<string, array{monate: list<int>, standard: string}>
     */
    private const REGELN = [
        'Q1' => ['monate' => [1, 2, 3], 'standard' => '01-15'],
        'Q2' => ['monate' => [4, 5, 6], 'standard' => '04-15'],
        'Q3' => ['monate' => [7, 8, 9], 'standard' => '07-15'],
        'DEZEMBER' => ['monate' => [12], 'standard' => '12-01'],
    ];

    public function istAktiv(): bool
    {
        return (bool) config('smartabrechnen.reminders.enabled', true);
    }

    /**
     * Konfigurierter Termin des Fensters im Format MM-TT.
     */
    public function terminMuster(ReminderWindow $fenster): string
    {
        $regel = self::REGELN[$fenster->value];

        $konfiguriert = config($fenster->configKey());

        if (! is_string($konfiguriert) || preg_match('/^\d{2}-\d{2}$/', $konfiguriert) !== 1) {
            return $regel['standard'];
        }

        $monat = (int) substr($konfiguriert, 0, 2);
        $tag = (int) substr($konfiguriert, 3, 2);

        // Der Tag muss im gewaehlten Monat wirklich existieren. Ein 31. April
        // wuerde sonst still auf den 1. Mai rutschen. Das Jahr 2001 dient nur
        // als Nichtschaltjahr, damit der 29. Februar nicht durchfaellt.
        if (! in_array($monat, $regel['monate'], true) || ! checkdate($monat, $tag, 2001)) {
            return $regel['standard'];
        }

        return $konfiguriert;
    }

    /**
     * Faelligkeitstag des Fensters im angegebenen Kalenderjahr, Beginn des Tages
     * in Europe/Berlin.
     */
    public function termin(ReminderWindow $fenster, int $kalenderjahr): CarbonImmutable
    {
        $muster = $this->terminMuster($fenster);

        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            sprintf('%04d-%s 00:00:00', $kalenderjahr, $muster),
            self::ZEITZONE
        )->startOfDay();
    }

    /**
     * Fenster, das an diesem Tag faellig ist, sonst null.
     */
    public function fensterAm(Carbon|CarbonImmutable $tag): ?ReminderWindow
    {
        $berlin = CarbonImmutable::parse($tag)->setTimezone(self::ZEITZONE);
        $jahr = (int) $berlin->format('Y');

        foreach (ReminderWindow::cases() as $fenster) {
            if ($this->termin($fenster, $jahr)->isSameDay($berlin)) {
                return $fenster;
            }
        }

        return null;
    }

    /**
     * Relevantes Abrechnungsjahr, an das erinnert wird.
     */
    public function abrechnungsjahr(Carbon|CarbonImmutable $tag): int
    {
        return (int) CarbonImmutable::parse($tag)->setTimezone(self::ZEITZONE)->format('Y') - 1;
    }

    /**
     * Vollstaendiger Plan eines Kalenderjahres, nur zur Anzeige.
     *
     * @return array<string, string>
     */
    public function plan(int $kalenderjahr): array
    {
        $plan = [];

        foreach (ReminderWindow::cases() as $fenster) {
            $plan[$fenster->value] = $this->termin($fenster, $kalenderjahr)->format('d.m.Y');
        }

        return $plan;
    }
}
