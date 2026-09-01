<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\UserAdministration;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserLockRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Nutzerverwaltung: Liste, Sperren, Entsperren, Passwort-Reset
 * (Masterprompt 20).
 *
 * Der Adminbereich setzt niemals selbst ein Passwort. Er loest ausschliesslich
 * den regulaeren Zurücksetzen-Link an die hinterlegte Adresse aus.
 *
 * Jede Aenderung am Konto verlangt eine Begruendung und erzeugt einen
 * Audit-Eintrag.
 */
final class UserController extends Controller
{
    public function __construct(private readonly UserAdministration $users) {}

    public function index(Request $request): View
    {
        $suche = trim((string) $request->string('suche'));

        $query = User::query()
            ->withTrashed()
            ->with('adminRoles')
            ->orderByDesc('created_at');

        if ($suche !== '') {
            $query->where(function ($builder) use ($suche): void {
                $builder->where('email', 'like', '%'.$suche.'%')
                    ->orWhere('name', 'like', '%'.$suche.'%');
            });
        }

        /** @var list<User> $nutzer */
        $nutzer = $query->limit(100)->get()->all();

        $statusZahlen = [];

        foreach (UserStatus::cases() as $status) {
            $statusZahlen[$status->value] = User::query()->where('status', $status->value)->count();
        }

        return view('admin.nutzer', [
            'nutzer' => $nutzer,
            'suche' => $suche,
            'statuszahlen' => $statusZahlen,
        ]);
    }

    public function lock(UserLockRequest $request, User $user): RedirectResponse
    {
        $akteur = $this->actor($request);

        if ($akteur->getKey() === $user->getKey()) {
            return redirect()
                ->route('admin.nutzer')
                ->with('hinweis', 'Die eigene Kennung kann nicht gesperrt werden.');
        }

        $this->users->lock($user, $akteur, $request->grund());

        return redirect()
            ->route('admin.nutzer')
            ->with('status', 'Das Konto ist gesperrt. Offene Sitzungen wurden beendet.');
    }

    public function unlock(UserLockRequest $request, User $user): RedirectResponse
    {
        $this->users->unlock($user, $this->actor($request), $request->grund());

        return redirect()
            ->route('admin.nutzer')
            ->with('status', 'Das Konto ist wieder freigegeben.');
    }

    public function passwordReset(Request $request, User $user): RedirectResponse
    {
        $this->users->sendPasswordReset($user, $this->actor($request));

        return redirect()
            ->route('admin.nutzer')
            ->with(
                'status',
                'Der Link zum Zurücksetzen des Passworts wurde an die hinterlegte Adresse gesendet, '
                .'sofern die Adresse versandfähig ist. Es wurde kein Passwort gesetzt.'
            );
    }

    private function actor(Request $request): User
    {
        $akteur = $request->user();

        if (! $akteur instanceof User) {
            abort(403);
        }

        return $akteur;
    }
}
