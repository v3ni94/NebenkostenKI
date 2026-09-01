<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Reminder\ManageReminderSubscription;
use App\Application\Reminder\ReminderPreferences;
use App\Models\ReminderPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Abmeldung und erneute Aktivierung der Erinnerungen ohne Anmeldung.
 *
 * VERBINDLICH (Masterprompt 17.2):
 *
 *  - Der Link funktioniert ohne vorherige Anmeldung. Er ist deshalb signiert
 *    und traegt einen Zufallstoken, keine Kennung des Nutzers und keine
 *    Kundendaten.
 *  - Die Signatur wird von der Middleware "signed" geprueft. Ein veraenderter
 *    Link fuehrt zu 403.
 *  - Ein unbekannter Token fuehrt zu 404 und nicht zu einer Meldung, die die
 *    Existenz eines Kontos bestaetigt.
 *  - Der Vorgang wirkt ausschliesslich auf Erinnerungen. Kritische Konto- und
 *    Zahlungsnachrichten bleiben unberuehrt.
 *
 * Der Ablauf gibt bewusst keine Kontodaten aus. Er bestaetigt nur die Aenderung.
 */
class ReminderUnsubscribeController extends Controller
{
    public function __construct(
        private readonly ReminderPreferences $einstellungen,
        private readonly ManageReminderSubscription $verwaltung,
    ) {}

    public function unsubscribe(Request $request, string $token): RedirectResponse
    {
        $einstellung = $this->einstellung($token);

        $this->verwaltung->abmelden($einstellung);

        $objekt = $this->verwaltung->objektbezeichnung($einstellung);

        return redirect()
            ->route('site.home')
            ->with('status', $objekt === null
                ? 'Ihre Erinnerungen sind abgemeldet. Nachrichten zu Konto, Zahlung und Rechnung '
                    .'erhalten Sie weiterhin.'
                : sprintf(
                    'Die Erinnerungen für das Objekt %s sind abgemeldet. Nachrichten zu Konto, Zahlung '
                    .'und Rechnung erhalten Sie weiterhin. In Ihrem Konto können Sie die Erinnerungen '
                    .'jederzeit wieder aktivieren.',
                    $objekt
                ));
    }

    public function resubscribe(Request $request, string $token): RedirectResponse
    {
        $einstellung = $this->einstellung($token);

        $this->verwaltung->reaktivieren($einstellung);

        return redirect()
            ->route('site.home')
            ->with('status', 'Ihre Erinnerungen sind wieder aktiv.');
    }

    private function einstellung(string $token): ReminderPreference
    {
        $einstellung = $this->einstellungen->findeMitToken($token);

        abort_unless($einstellung instanceof ReminderPreference, 404);

        return $einstellung;
    }
}
