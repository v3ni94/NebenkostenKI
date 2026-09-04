<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Account\TwoFactorAuthentication;
use App\Application\Admin\AdminAuditRecorder;
use App\Application\Admin\UserAdministration;
use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserLockRequest;
use App\Mail\MailDispatcher;
use App\Mail\ZweitfaktorZurueckgesetztMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Nutzerverwaltung: Liste, Sperren, Entsperren, Passwort-Reset und
 * Zuruecksetzung des Zweitfaktors (Masterprompt 20).
 *
 * Der Adminbereich setzt niemals selbst ein Passwort. Er loest ausschliesslich
 * den regulaeren Zurücksetzen-Link an die hinterlegte Adresse aus.
 *
 * Jede Aenderung am Konto verlangt eine Begruendung und erzeugt einen
 * Audit-Eintrag.
 *
 * ROLLENTRENNUNG (ARCHITECTURE.md T10): Die Routen tragen das Gate
 * admin-manage-users (ADMIN und SUPPORT). Interne Kennungen, also Konten mit
 * aktiver Adminrolle, darf ausschliesslich die Rolle ADMIN sperren, entsperren
 * oder zuruecksetzen. Eine Supportkennung koennte sonst die Administration
 * aussperren.
 *
 * ZWEITFAKTOR-RESET: Der Weg fuer Kunden, die Telefon und
 * Wiederherstellungscodes verloren haben. Er verlangt eine Begruendung mit der
 * durchgefuehrten Identitaetspruefung, beendet alle Sitzungen des Kontos und
 * informiert den Inhaber per kritischer Kontonachricht. Der Faktor wird nicht
 * neu gesetzt, der Nutzer richtet ihn selbst wieder ein.
 */
final class UserController extends Controller
{
    public const string MELDUNG_INTERNE_KENNUNG = 'Interne Kennungen kann nur die Rolle Administration ändern.';

    public function __construct(
        private readonly UserAdministration $users,
        private readonly TwoFactorAuthentication $zweiFaktor,
        private readonly AdminAuditRecorder $audit,
        private readonly MailDispatcher $mails,
    ) {}

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

        if (! $this->darfInterneKennungAendern($akteur, $user)) {
            return $this->interneKennungAbgelehnt();
        }

        $this->users->lock($user, $akteur, $request->grund());

        return redirect()
            ->route('admin.nutzer')
            ->with('status', 'Das Konto ist gesperrt. Offene Sitzungen wurden beendet.');
    }

    public function unlock(UserLockRequest $request, User $user): RedirectResponse
    {
        $akteur = $this->actor($request);

        if (! $this->darfInterneKennungAendern($akteur, $user)) {
            return $this->interneKennungAbgelehnt();
        }

        $this->users->unlock($user, $akteur, $request->grund());

        return redirect()
            ->route('admin.nutzer')
            ->with('status', 'Das Konto ist wieder freigegeben.');
    }

    public function passwordReset(Request $request, User $user): RedirectResponse
    {
        $akteur = $this->actor($request);

        if (! $this->darfInterneKennungAendern($akteur, $user)) {
            return $this->interneKennungAbgelehnt();
        }

        $this->users->sendPasswordReset($user, $akteur);

        return redirect()
            ->route('admin.nutzer')
            ->with(
                'status',
                'Der Link zum Zurücksetzen des Passworts wurde an die hinterlegte Adresse gesendet, '
                .'sofern die Adresse versandfähig ist. Es wurde kein Passwort gesetzt.'
            );
    }

    /**
     * Zweitfaktor eines Kontos zuruecksetzen.
     *
     * Die Begruendung ist Pflicht und soll die durchgefuehrte
     * Identitaetspruefung benennen. Sie wird im Revisionsprotokoll gespeichert.
     */
    public function resetTwoFactor(UserLockRequest $request, User $user): RedirectResponse
    {
        $akteur = $this->actor($request);

        if ($akteur->getKey() === $user->getKey()) {
            return redirect()
                ->route('admin.nutzer')
                ->with('hinweis', 'Den eigenen Zweitfaktor schalten Sie in Ihrem Konto ab, nicht hier.');
        }

        if (! $this->darfInterneKennungAendern($akteur, $user)) {
            return $this->interneKennungAbgelehnt();
        }

        if (! $this->zweiFaktor->isConfirmed($user)) {
            return redirect()
                ->route('admin.nutzer')
                ->with('hinweis', 'Für dieses Konto ist kein Zweitfaktor aktiv.');
        }

        $this->zweiFaktor->reset($user);

        $this->audit->record(
            action: 'admin.user.two_factor_reset',
            actor: $akteur,
            subject: $user,
            metadata: ['sitzungen_beendet' => true],
            reason: $request->grund(),
        );

        $this->mails->send(
            new ZweitfaktorZurueckgesetztMail($this->anrede($user), route('two-factor.setup')),
            (string) $user->getAttribute('email'),
            $user,
        );

        return redirect()
            ->route('admin.nutzer')
            ->with(
                'status',
                'Der Zweitfaktor ist zurückgesetzt und alle Sitzungen des Kontos sind beendet. Der Inhaber '
                .'wurde per E-Mail informiert und richtet den Faktor selbst neu ein.'
            );
    }

    /**
     * Interne Kennungen darf nur die Rolle ADMIN aendern.
     */
    private function darfInterneKennungAendern(User $akteur, User $ziel): bool
    {
        if (! $ziel->isStaff()) {
            return true;
        }

        return $akteur->hasAdminRole(AdminRole::ADMIN);
    }

    private function interneKennungAbgelehnt(): RedirectResponse
    {
        return redirect()
            ->route('admin.nutzer')
            ->with('hinweis', self::MELDUNG_INTERNE_KENNUNG);
    }

    private function anrede(User $user): string
    {
        $name = $user->getAttribute('name');

        return is_string($name) && trim($name) !== ''
            ? 'Guten Tag '.trim($name).','
            : 'Guten Tag,';
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
