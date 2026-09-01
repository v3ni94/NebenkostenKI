<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\SupportAccessGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupportAccessRequest;
use App\Models\BillingRun;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Begruendung und Freischaltung eines Supportzugriffs
 * (Masterprompt 19, ARCHITECTURE.md T10).
 *
 * Ohne erfasste Begruendung gibt es keinen Einblick in eine Organisation, ein
 * Objekt oder einen Abrechnungslauf. Die Freischaltung gilt zeitlich begrenzt
 * und nur fuer genau den angefragten Datensatz.
 */
final class SupportAccessController extends Controller
{
    /**
     * Zulaessige Entitaeten des Supportzugriffs.
     *
     * @var array<string, class-string<Model>>
     */
    public const array ENTITAETEN = [
        'organisation' => Organization::class,
        'objekt' => Property::class,
        'abrechnung' => BillingRun::class,
    ];

    public function __construct(private readonly SupportAccessGuard $guard) {}

    public function create(Request $request, string $entitaet, string $id): View
    {
        $this->assertKnownEntity($entitaet);

        return view('admin.supportzugriff', [
            'entitaet' => $entitaet,
            'id' => $id,
            'ziel' => (string) $request->string('ziel'),
        ]);
    }

    public function store(SupportAccessRequest $request, string $entitaet, string $id): RedirectResponse
    {
        $this->assertKnownEntity($entitaet);

        $nutzer = $request->user();

        if (! $nutzer instanceof User) {
            abort(403);
        }

        $this->guard->grant($nutzer, $entitaet, $id, $request->grund());

        return redirect()
            ->to($this->targetUrl($entitaet, $id))
            ->with('status', 'Der Supportzugriff ist für '.SupportAccessGuard::GUELTIGKEIT_MINUTEN
                .' Minuten freigeschaltet. Die Begründung wurde protokolliert.');
    }

    private function targetUrl(string $entitaet, string $id): string
    {
        return match ($entitaet) {
            'organisation' => route('admin.organisationen.show', $id),
            'objekt' => route('admin.objekte.show', $id),
            default => route('admin.abrechnungen.show', $id),
        };
    }

    private function assertKnownEntity(string $entitaet): void
    {
        if (! array_key_exists($entitaet, self::ENTITAETEN)) {
            abort(404);
        }
    }
}
