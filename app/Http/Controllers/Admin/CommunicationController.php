<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\AdminAuditRecorder;
use App\Application\Admin\CommunicationOverview;
use App\Enums\EmailStatus;
use App\Enums\EmailSuppressionReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuppressionReleaseRequest;
use App\Mail\MailDispatcher;
use App\Mail\SuppressionGuard;
use App\Mail\WiederholungNichtMoeglichException;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * E-Mail-Status, Sperrliste, Erinnerungstermine und Vorlagen
 * (Masterprompt 16, 17, 20).
 *
 * Es wird kein Nachrichteninhalt, kein Downloadlink und kein Token angezeigt.
 *
 * Schreibende Handlungen sind das Aufheben einer Adresssperre, zum Beispiel
 * nach einem Ausfall des Postausgangsservers, der faelschlich als
 * Unzustellbarkeit gewertet wurde (mit Begruendung), und das erneute Senden
 * einer zeitweilig gescheiterten Nachricht. Beide erzeugen einen Audit-Eintrag.
 */
final class CommunicationController extends Controller
{
    public const string AUDIT_SPERRE_AUFGEHOBEN = 'admin.email_suppression.released';

    public const string AUDIT_ERNEUT_SENDEN_ANGEFORDERT = 'admin.email.resend_requested';

    public function __construct(
        private readonly CommunicationOverview $overview,
        private readonly MailDispatcher $mailer,
    ) {}

    public function index(): View
    {
        return view('admin.kommunikation', [
            'mailstatus' => $this->overview->emailStatusCounts(),
            'letzte' => $this->overview->recentEmails(25),
            'fehlgeschlagen' => $this->overview->failedEmails(25),
            'wiederholbar' => fn (EmailMessage $nachricht): bool => $this->mailer->istWiederholbar($nachricht),
            'sperrliste' => $this->overview->suppressions(50),
            'vorlagen' => $this->overview->templateUsage(),
            'erinnerungsplan' => $this->overview->reminderPlan(),
            'erinnerungen_aktiv' => $this->overview->remindersActive(),
            'erinnerungsstatus' => $this->overview->reminderStatusCounts(),
            'erinnerungsfenster' => $this->overview->reminderWindowCounts(),
            'anstehend' => $this->overview->upcomingReminders(25),
        ]);
    }

    /**
     * Versendet eine zeitweilig gescheiterte Nachricht erneut. Die Handlung
     * wird mit Akteur protokolliert; der Versand selbst laeuft ueber den
     * MailDispatcher mit dessen Protokoll und Sperrlistenpruefung.
     */
    public function resend(
        Request $request,
        EmailMessage $emailMessage,
        MailDispatcher $mailer,
        AdminAuditRecorder $audit,
    ): RedirectResponse {
        $akteur = $request->user();

        if (! $akteur instanceof User) {
            abort(403);
        }

        $audit->record(
            action: self::AUDIT_ERNEUT_SENDEN_ANGEFORDERT,
            actor: $akteur,
            subject: $emailMessage,
            metadata: [
                'template' => (string) $emailMessage->getAttribute('template'),
                'versuche_bisher' => (int) $emailMessage->getAttribute('attempts'),
            ],
        );

        try {
            $ergebnis = $mailer->erneutSenden($emailMessage, $akteur);
        } catch (WiederholungNichtMoeglichException $ausnahme) {
            return redirect()
                ->route('admin.kommunikation')
                ->with('hinweis', $ausnahme->getMessage());
        }

        $status = $ergebnis->getAttribute('status');

        return redirect()
            ->route('admin.kommunikation')
            ->with(
                $status === EmailStatus::GESENDET ? 'status' : 'hinweis',
                match ($status) {
                    EmailStatus::GESENDET => 'Die Nachricht wurde erneut versendet.',
                    EmailStatus::UNTERDRUECKT => 'Die Adresse steht inzwischen auf der Sperrliste. Es wurde nicht versendet.',
                    EmailStatus::BOUNCED => 'Die Gegenstelle lehnt den Empfänger dauerhaft ab. Die Adresse ist gesperrt.',
                    default => 'Der erneute Versand ist fehlgeschlagen. Fehlercode: '.($ergebnis->getAttribute('error_code') ?? 'ohne Angabe').'.',
                },
            );
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
