<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\AdminAuditRecorder;
use App\Application\Admin\CommunicationOverview;
use App\Enums\EmailSuppressionReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuppressionReleaseRequest;
use App\Mail\SuppressionGuard;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * E-Mail-Status, Sperrliste, Erinnerungstermine und Vorlagen
 * (Masterprompt 16, 17, 20).
 *
 * Es wird kein Nachrichteninhalt, kein Downloadlink und kein Token angezeigt.
 *
 * Die einzige schreibende Handlung ist das Aufheben einer Adresssperre, zum
 * Beispiel nach einem Ausfall des Postausgangsservers, der faelschlich als
 * Unzustellbarkeit gewertet wurde. Sie verlangt eine Begruendung und erzeugt
 * einen Audit-Eintrag.
 */
final class CommunicationController extends Controller
{
    public const string AUDIT_SPERRE_AUFGEHOBEN = 'admin.email_suppression.released';

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

    public function releaseSuppression(
        SuppressionReleaseRequest $request,
        SuppressionGuard $suppression,
        AdminAuditRecorder $audit,
    ): RedirectResponse {
        $akteur = $request->user();

        if (! $akteur instanceof User) {
            abort(403);
        }

        $sperre = $suppression->find($request->email());

        if (! $sperre instanceof EmailSuppression) {
            return redirect()
                ->route('admin.kommunikation')
                ->with('hinweis', 'Für diese Adresse besteht keine Sperre.');
        }

        $grund = $sperre->getAttribute('reason');
        $quelle = $sperre->getAttribute('source');

        // Protokoll vor dem Loeschen, damit der Eintrag die Sperre referenziert.
        $audit->record(
            action: self::AUDIT_SPERRE_AUFGEHOBEN,
            actor: $akteur,
            subject: $sperre,
            metadata: [
                'grund_der_sperre' => $grund instanceof EmailSuppressionReason ? $grund->value : null,
                'quelle' => is_string($quelle) ? $quelle : null,
            ],
            reason: $request->grund(),
        );

        $suppression->release($request->email());

        return redirect()
            ->route('admin.kommunikation')
            ->with('status', 'Die Sperre ist aufgehoben. Die Adresse erhält wieder Erinnerungen.');
    }
}
