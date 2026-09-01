<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\SystemHealthCheck;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Technische Healthchecks fuer Datenbank, Storage, SFTP und Mail
 * (Masterprompt 20, 22).
 *
 * VERBINDLICH: Es wird niemals ein Secret ausgegeben, auch nicht teilweise
 * maskiert. Angezeigt werden ausschliesslich erreichbar ja oder nein, die
 * Version und die Fehlerklasse.
 */
final class HealthController extends Controller
{
    public function __construct(private readonly SystemHealthCheck $health) {}

    public function index(): View
    {
        return view('admin.technik', [
            'proben' => $this->health->all(),
        ]);
    }
}
