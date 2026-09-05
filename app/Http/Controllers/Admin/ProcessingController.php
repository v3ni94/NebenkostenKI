<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\ProcessingOverview;
use App\Application\Admin\RetryProcessingJob;
use App\Http\Controllers\Controller;
use App\Models\ProcessingJob;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Status der Dokumentverarbeitung, fehlgeschlagene Teiljobs und Dead Letter
 * (Masterprompt 20).
 *
 * Es werden keine Rohdaten, keine Nutzlasten und keine Prompts angezeigt.
 */
final class ProcessingController extends Controller
{
    public function __construct(
        private readonly ProcessingOverview $overview,
        private readonly RetryProcessingJob $retry,
    ) {}

    public function index(): View
    {
        return view('admin.verarbeitung', [
            'dokumente' => $this->overview->documentStatusCounts(),
            'jobs' => $this->overview->jobStatusCounts(),
            'fehlgeschlagen' => $this->overview->failedJobs(),
            'deadletter' => $this->overview->deadLetterJobs(),
        ]);
    }

    public function retryJob(Request $request, ProcessingJob $job): RedirectResponse
    {
        $nutzer = $request->user();

        if (! $nutzer instanceof User) {
            abort(403);
        }

        $erfolg = ($this->retry)($job, $nutzer);

        return redirect()
            ->route('admin.verarbeitung')
            ->with(
                $erfolg ? 'status' : 'hinweis',
                $erfolg
                    ? 'Der Teiljob wurde erneut in die Warteschlange gestellt.'
                    : 'Der Teiljob ist in seinem aktuellen Status nicht wiederholbar.',
            );
    }
}
