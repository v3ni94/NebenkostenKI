<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\MetricsOverview;
use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Kennzahlen (Masterprompt 20).
 *
 * Alle Zahlen entstehen aus den vorhandenen Fachdaten. Es gibt keinen
 * Analysetracker, kein Zaehlpixel und keine Uebermittlung an Dritte.
 */
final class MetricsController extends Controller
{
    public function __construct(private readonly MetricsOverview $metrics) {}

    public function index(): View
    {
        $jetzt = Carbon::now();

        return view('admin.kennzahlen', [
            'monat' => $this->metrics->revenue($jetzt->copy()->startOfMonth(), $jetzt),
            'jahr' => $this->metrics->revenue($jetzt->copy()->startOfYear(), $jetzt),
            'monatsreihe' => $this->metrics->monthlyRevenueCent(),
            'laeufe' => $this->metrics->runsPerStatus(),
            'conversion' => $this->metrics->previewToPaymentConversion(),
            'abbruchschritte' => $this->metrics->abandonmentSteps(),
        ]);
    }
}
