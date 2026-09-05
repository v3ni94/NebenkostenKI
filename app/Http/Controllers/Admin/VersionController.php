<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\AiOverview;
use App\Application\Admin\VersionOverview;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Regel- und Promptversionen (Masterprompt 12, 13, 20).
 *
 * Der Prompttext selbst wird nicht angezeigt, nur Zweck, Version, Status und
 * ein gekuerzter Hash. Damit bleibt die Herkunft eines Ergebnisses
 * nachvollziehbar, ohne den Prompt in die Oberflaeche zu tragen.
 */
final class VersionController extends Controller
{
    public function __construct(
        private readonly VersionOverview $versions,
        private readonly AiOverview $ai,
    ) {}

    public function index(): View
    {
        return view('admin.versionen', [
            'regelstaende' => $this->versions->rulesets(),
            'domainversionen' => $this->versions->domainVersions(),
            'prompts' => $this->ai->promptVersions(),
        ]);
    }
}
