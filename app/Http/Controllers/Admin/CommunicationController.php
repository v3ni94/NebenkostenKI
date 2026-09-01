<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\CommunicationOverview;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * E-Mail-Status, Sperrliste, Erinnerungstermine und Vorlagen
 * (Masterprompt 16, 17, 20).
 *
 * Es wird kein Nachrichteninhalt, kein Downloadlink und kein Token angezeigt.
 */
final class CommunicationController extends Controller
{
    public function __construct(private readonly CommunicationOverview $overview) {}

    public function index(): View
    {
        return view('admin.kommunikation', [
            'mailstatus' => $this->overview->emailStatusCounts(),
            'letzte' => $this->overview->recentEmails(25),
            'fehlgeschlagen' => $this->overview->failedEmails(25),
            'sperrliste' => $this->overview->suppressions(50),
            'vorlagen' => $this->overview->templateUsage(),
            'erinnerungsplan' => $this->overview->reminderPlan(),
            'erinnerungen_aktiv' => $this->overview->remindersActive(),
            'erinnerungsstatus' => $this->overview->reminderStatusCounts(),
            'erinnerungsfenster' => $this->overview->reminderWindowCounts(),
            'anstehend' => $this->overview->upcomingReminders(25),
        ]);
    }
}
