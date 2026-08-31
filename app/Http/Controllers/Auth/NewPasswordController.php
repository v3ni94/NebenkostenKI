<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Account\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\NewPasswordRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Vergabe eines neuen Passworts.
 *
 * Der Broker prueft das Token gegen die Tabelle password_reset_tokens und
 * verwirft es nach der Verwendung. Ein abgelaufenes Token wird abgelehnt, die
 * Gueltigkeit steht in config/auth.php.
 *
 * Mit dem neuen Passwort wird zusaetzlich der remember_token erneuert. Damit
 * werden bestehende Angemeldet-bleiben-Cookies auf anderen Geraeten wertlos.
 */
class NewPasswordController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function create(Request $request, string $token): View
    {
        return view('auth.passwort-neu', [
            'token' => $token,
            'email' => (string) $request->string('email'),
        ]);
    }

    public function store(NewPasswordRequest $request): RedirectResponse
    {
        $ergebnis = Password::broker()->reset(
            [
                'email' => Str::lower((string) $request->string('email')),
                'password' => (string) $request->string('password'),
                'password_confirmation' => (string) $request->string('password_confirmation'),
                'token' => (string) $request->string('token'),
            ],
            function (CanResetPassword $user, string $password): void {
                if (! $user instanceof User) {
                    return;
                }

                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $this->audit->record(
                    action: 'account.password_reset',
                    subject: $user,
                    actor: $user,
                );
            }
        );

        if ($ergebnis === Password::PASSWORD_RESET) {
            return redirect()
                ->route('login')
                ->with('status', 'Ihr Passwort ist geändert. Bitte melden Sie sich mit dem neuen Passwort an.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors([
                'email' => 'Der Link ist abgelaufen oder wurde bereits verwendet. '
                    .'Bitte fordern Sie einen neuen Link an.',
            ]);
    }
}
