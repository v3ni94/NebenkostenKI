<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\AdminAuditRecorder;
use App\Application\Admin\PrivacyMonitor;
use App\Application\Documents\RetryFailedDeletions;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Datenschutzmonitor (Masterprompt 19, 20).
 *
 * Angezeigt werden ueberfaellige temporaere Uploads, offene lokale Loeschungen,
 * offene Providerloeschungen und fehlgeschlagene Loeschungen als kritischer
 * Alarm. Es wird niemals ein Dateiinhalt und niemals ein Originaldateiname
 * angezeigt.
 */
final class PrivacyController extends Controller
{
    public function __construct(
        private readonly PrivacyMonitor $monitor,
        private readonly RetryFailedDeletions $retry,
        private readonly AdminAuditRecorder $audit,
    ) {}

    public function index(): View
    {
        return view('admin.datenschutz', [
            'zusammenfassung' => $this->monitor->summary(),
            'ueberfaellig' => $this->monitor->overdueUploads(),
            'fehlgeschlagen' => $this->monitor->failedDeletions(),
            'provider' => $this->monitor->openProviderDeletions(),
        ]);
    }

    /**
     * Wiederholt alle fehlgeschlagenen und ueberfaelligen Loeschungen.
     */
    public function retry(Request $request): RedirectResponse
    {
        $bericht = ($this->retry)();

        $nutzer = $request->user();

        if ($nutzer instanceof User) {
            $this->audit->record(
                action: 'admin.deletion.retried',
                actor: $nutzer,
                metadata: [
                    'geprueft' => $bericht->inspected,
                    'geloescht' => $bericht->deleted,
                    'fehlgeschlagen' => $bericht->failed,
                ],
            );
        }

        return redirect()
            ->route('admin.datenschutz')
            ->with('status', 'Die Löschung wurde erneut angestoßen. '.$bericht->summary());
    }
}
