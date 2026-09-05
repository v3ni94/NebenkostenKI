<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Application\Reminder\ReminderSchedule;
use App\Enums\EmailStatus;
use App\Enums\ReminderStatus;
use App\Enums\ReminderWindow;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\ReminderEvent;
use Illuminate\Support\Carbon;

/**
 * E-Mail-Status, Sperrliste, Erinnerungstermine und Templates
 * (Masterprompt 16, 17, 20).
 *
 * DATENSPARSAMKEIT: Es wird kein Nachrichteninhalt, kein Downloadlink und kein
 * Token angezeigt. Sichtbar sind Vorlage, Empfaengeradresse, Status, Zeitpunkt
 * und Fehlercode. Die Adresse ist fuer die Fehlersuche erforderlich.
 */
final class CommunicationOverview
{
    public function __construct(private readonly ReminderSchedule $schedule) {}

    /**
     * @return array<string, int>
     */
    public function emailStatusCounts(): array
    {
        $counts = [];

        foreach (EmailStatus::cases() as $status) {
            $counts[$status->value] = EmailMessage::query()->where('status', $status->value)->count();
        }

        return $counts;
    }

    /**
     * @return list<EmailMessage>
     */
    public function recentEmails(int $limit = 50): array
    {
        /** @var list<EmailMessage> $messages */
        $messages = EmailMessage::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all();

        return $messages;
    }

    /**
     * @return list<EmailMessage>
     */
    public function failedEmails(int $limit = 50): array
    {
        /** @var list<EmailMessage> $messages */
        $messages = EmailMessage::query()
            ->whereIn('status', [
                EmailStatus::FEHLGESCHLAGEN->value,
                EmailStatus::BOUNCED->value,
            ])
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->get()
            ->all();

        return $messages;
    }

    /**
     * @return list<EmailSuppression>
     */
    public function suppressions(int $limit = 100): array
    {
        /** @var list<EmailSuppression> $entries */
        $entries = EmailSuppression::query()
            ->orderByDesc('suppressed_at')
            ->limit($limit)
            ->get()
            ->all();

        return $entries;
    }

    /**
     * Verwendete Vorlagen mit Anzahl. Der Vorlagentext selbst wird nicht
     * angezeigt, er liegt versioniert im Repository.
     *
     * @return array<string, int>
     */
    public function templateUsage(): array
    {
        /** @var array<int|string, mixed> $raw */
        $raw = EmailMessage::query()
            ->selectRaw('template, count(*) as anzahl')
            ->groupBy('template')
            ->orderBy('template')
            ->pluck('anzahl', 'template')
            ->all();

        $usage = [];

        foreach ($raw as $template => $count) {
            $usage[(string) $template] = is_numeric($count) ? (int) $count : 0;
        }

        return $usage;
    }

    /**
     * Erinnerungsplan des laufenden Kalenderjahres, nur zur Anzeige.
     *
     * @return array<string, string>
     */
    public function reminderPlan(?int $year = null): array
    {
        return $this->schedule->plan($year ?? (int) Carbon::now()->format('Y'));
    }

    public function remindersActive(): bool
    {
        return $this->schedule->istAktiv();
    }

    /**
     * @return array<string, int>
     */
    public function reminderStatusCounts(): array
    {
        $counts = [];

        foreach (ReminderStatus::cases() as $status) {
            $counts[$status->value] = ReminderEvent::query()->where('status', $status->value)->count();
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function reminderWindowCounts(): array
    {
        $counts = [];

        foreach (ReminderWindow::cases() as $window) {
            $counts[$window->value] = ReminderEvent::query()
                ->where('reminder_window', $window->value)
                ->count();
        }

        return $counts;
    }

    /**
     * @return list<ReminderEvent>
     */
    public function upcomingReminders(int $limit = 50): array
    {
        /** @var list<ReminderEvent> $events */
        $events = ReminderEvent::query()
            ->where('status', ReminderStatus::GEPLANT->value)
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->get()
            ->all();

        return $events;
    }
}
