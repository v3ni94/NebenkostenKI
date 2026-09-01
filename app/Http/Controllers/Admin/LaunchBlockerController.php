<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\LaunchBlockerCheck;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Rechtliche und technische Livegang-Blocker (Masterprompt 26).
 *
 * Die Seite nennt zu jedem Punkt, was fehlt, welche Folge das hat und wer es
 * bereitstellen muss. Es wird keine Angabe erfunden und keine Freigabe
 * behauptet.
 */
final class LaunchBlockerController extends Controller
{
    public function __construct(private readonly LaunchBlockerCheck $blockers) {}

    public function index(): View
    {
        return view('admin.livegang', [
            'bericht' => $this->blockers->report(),
        ]);
    }
}
