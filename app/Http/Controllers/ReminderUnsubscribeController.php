<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Reminder\ManageReminderSubscription;
use App\Application\Reminder\ReminderPreferences;
use App\Models\ReminderPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
 * ZWEI SCHRITTE: Der Aufruf aus der E-Mail (GET) zeigt nur eine
 * Bestaetigungsseite. Die Aenderung selbst erfolgt erst mit dem Absenden des
 * Formulars (POST) auf dieselbe signierte Adresse. Link-Scanner und
 * Vorschaudienste der Postfaecher rufen enthaltene Adressen automatisch ab;
 * sie wuerden den Nutzer sonst unbemerkt abmelden oder wieder anmelden.
 *
 * Der Ablauf gibt bewusst keine Kontodaten aus. Er bestaetigt nur die Aenderung
 * auf der Startseite, die den Statushinweis anzeigt.
 */
class ReminderUnsubscribeController extends Controller
{
    public function __construct(
        private readonly ReminderPreferences $einstellungen,
        private readonly ManageReminderSubscription $verwaltung,
    ) {}

    /**
     * Bestaetigungsseite der Abmeldung. Aendert nichts.
     */
    public function unsubscribe(Request $request, string $token): View
    {
        $einstellung = $this->einstellung($token);

        return $this->seite($request, $einstellung, abmeldung: true);
    }

    /**
     * Fuehrt die Abmeldung aus.
     */
    public function confirmUnsubscribe(Request $request, string $token): RedirectResponse
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

    /**
     * Bestaetigungsseite der erneuten Aktivierung. Aendert nichts.
     */
    public function resubscribe(Request $request, string $token): View
    {
        $einstellung = $this->einstellung($token);

        return $this->seite($request, $einstellung, abmeldung: false);
    }

    /**
     * Fuehrt die erneute Aktivierung aus.
     */
    public function confirmResubscribe(Request $request, string $token): RedirectResponse
    {
        $einstellung = $this->einstellung($token);

        $this->verwaltung->reaktivieren($einstellung);

        return redirect()
            ->route('site.home')
            ->with('status', 'Ihre Erinnerungen sind wieder aktiv.');
    }

    private function seite(Request $request, ReminderPreference $einstellung, bool $abmeldung): View
    {
        return view('site.erinnerungen', [
            'abmeldung' => $abmeldung,
            'objekt' => $this->verwaltung->objektbezeichnung($einstellung),
            'aktiv' => (bool) $einstellung->getAttribute('is_active'),
            // Das Formular sendet an dieselbe signierte Adresse. Die Signatur
            // liegt in der Abfragezeichenkette und wird auch beim POST geprueft.
            'formularUrl' => $request->fullUrl(),
        ]);
    }

    private function einstellung(string $token): ReminderPreference
    {
        $einstellung = $this->einstellungen->findeMitToken($token);

        abort_unless($einstellung instanceof ReminderPreference, 404);

        return $einstellung;
    }
}
