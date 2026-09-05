<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Revisionsprotokoll (Masterprompt 19, 20).
 *
 * Angezeigt werden Akteur, Aktion, Entitaet, Zeitpunkt, gekuerzte IP und die
 * Begruendung eines Supportzugriffs. Die IP ist bereits beim Schreiben
 * gekuerzt, der User-Agent liegt nur als Hash vor.
 */
final class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $aktion = trim((string) $request->string('aktion'));

        $query = AuditLog::query()
            ->with('actor')
            ->orderByDesc('occurred_at');

        if ($aktion !== '') {
            $query->where('action', 'like', $aktion.'%');
        }

        /** @var list<AuditLog> $eintraege */
        $eintraege = $query->limit(200)->get()->all();

        return view('admin.protokoll', [
            'eintraege' => $eintraege,
            'aktion' => $aktion,
        ]);
    }
}
