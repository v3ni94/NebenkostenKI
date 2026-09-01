<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\AiOverview;
use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * KI-Bereich: Healthcheck, Modelle, Promptversionen, Kosten und Limits
 * (Masterprompt 13.2, 13.8, 20).
 *
 * Der Healthcheck laeuft ueber die Providerabstraktion und sendet keinen
 * Dokumentinhalt. Es wird kein API-Key ausgegeben, auch nicht maskiert.
 */
final class AiController extends Controller
{
    public function __construct(private readonly AiOverview $overview) {}

    public function index(): View
    {
        $monatsbeginn = Carbon::now()->startOfMonth();
        $jetzt = Carbon::now();

        return view('admin.ki', [
            'provider' => $this->overview->providerState(),
            'primaer' => $this->overview->primaryProvider(),
            'fallback' => $this->overview->fallbackProvider(),
            'prompts' => $this->overview->promptVersions(),
            'monat' => $this->overview->periodTotals($monatsbeginn, $jetzt),
            'gesamt' => $this->overview->periodTotals($jetzt->copy()->subYears(5), $jetzt),
            'je_nutzer' => $this->overview->costsPerUser($monatsbeginn, $jetzt),
            'tageskosten' => $this->overview->dailyCostCent(),
            'warnung' => $this->overview->costSpikeWarning(),
            'limits' => $this->overview->limits(),
        ]);
    }
}
