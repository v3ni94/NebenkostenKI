<?php

declare(strict_types=1);

namespace App\Application\Reminder;

use App\Models\Property;
use App\Models\ReminderPreference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Signierte Adressen der Erinnerungsmails.
 *
 * VERBINDLICH (Masterprompt 17.2, 19, Arbeitsauftrag Nummer 5):
 *
 *  - Keine Kundendaten in der Adresse. Kein Name, keine E-Mail-Adresse, keine
 *    Anschrift, kein Betrag.
 *  - Keine erratbare Kennung. Kennungen sind ULIDs, zusaetzlich ist jede
 *    Adresse signiert.
 *  - Der Abmeldelink funktioniert ohne vorherige Anmeldung. Er traegt deshalb
 *    einen eigenen Zufallstoken und ist signiert, damit er nicht veraendert
 *    werden kann.
 *  - Der Folgejahres-CTA ist zeitlich begrenzt und fuehrt in das angemeldete
 *    Konto. Er ersetzt die Anmeldung nicht.
 */
class ReminderLinks
{
    /**
     * Gueltigkeit des Folgejahres-CTA in Tagen. Die Erinnerungen liegen bis zu
     * einem Quartal auseinander, der Link muss deshalb einige Wochen tragen.
     */
    public const CTA_GUELTIGKEIT_TAGE = 120;

    public function abmeldeUrl(ReminderPreference $einstellung): string
    {
        return URL::signedRoute('erinnerungen.abmelden', [
            'token' => (string) $einstellung->getAttribute('unsubscribe_token'),
        ]);
    }

    public function aktivierungsUrl(ReminderPreference $einstellung): string
    {
        return URL::signedRoute('erinnerungen.aktivieren', [
            'token' => (string) $einstellung->getAttribute('unsubscribe_token'),
        ]);
    }

    /**
     * CTA, der den vorausgefuellten Folgejahreslauf oeffnet.
     */
    public function folgejahrUrl(Property $objekt, int $abrechnungsjahr): string
    {
        return URL::temporarySignedRoute(
            'portal.folgejahr.start',
            Carbon::now()->addDays(self::CTA_GUELTIGKEIT_TAGE),
            [
                'property' => $objekt->getKey(),
                'jahr' => $abrechnungsjahr,
            ],
        );
    }
}
