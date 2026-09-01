<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\LaunchBlockerCheck;
use App\Application\Admin\MetricsOverview;
use App\Application\Admin\PrivacyMonitor;
use App\Application\Admin\ProcessingOverview;
use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Uebersicht des internen Bereichs (Masterprompt 20).
 *
 * Die Uebersicht fasst die Zustaende zusammen, die eine sofortige Handlung
 * verlangen: Livegang-Blocker, Datenschutzalarme und fehlgeschlagene Teiljobs.
 * Kundendaten werden hier nicht angezeigt.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly LaunchBlockerCheck $blockers,
        private readonly PrivacyMonitor $privacy,
        private readonly ProcessingOverview $processing,
        private readonly MetricsOverview $metrics,
    ) {}

    public function index(): View
    {
        $monatsbeginn = Carbon::now()->startOfMonth();

        return view('admin.dashboard', [
            'bericht' => $this->blockers->report(),
            'datenschutz' => $this->privacy->summary(),
            'jobs' => $this->processing->jobStatusCounts(),
            'dokumente' => $this->processing->documentStatusCounts(),
            'wiederholbar' => $this->processing->retryableCount(),
            'umsatz' => $this->metrics->revenue($monatsbeginn, Carbon::now()),
            'conversion' => $this->metrics->previewToPaymentConversion(),
            'laeufe' => $this->metrics->runsPerStatus(),
        ]);
    }
}
