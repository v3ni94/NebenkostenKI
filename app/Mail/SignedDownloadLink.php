<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\GeneratedDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Zeitlich begrenzter, kontogebundener Downloadlink.
 *
 * VERBINDLICH (Masterprompt 16, 19):
 *
 *  - Finale Mieterabrechnungen werden nicht als Anhang versendet. Der Nutzer
 *    erhaelt ausschliesslich diesen Link.
 *  - Der Link ist signiert und laeuft nach der Frist aus
 *    config('smartabrechnen.retention.signed_download_ttl_minutes') ab.
 *  - Der Link ersetzt die Autorisierung nicht. Die Zielroute prueft weiterhin
 *    Anmeldung, bestaetigte E-Mail-Adresse und Zugehoerigkeit zum Mandanten.
 *    Ein weitergegebener Link wirkt deshalb nicht wie ein Zugriffsrecht.
 *  - Die Adresse enthaelt keine Kundendaten. Die Kennung des Artefakts ist eine
 *    ULID und zusaetzlich signiert.
 */
class SignedDownloadLink
{
    public function gueltigkeitMinuten(): int
    {
        $wert = (int) config('smartabrechnen.retention.signed_download_ttl_minutes', 30);

        return max(1, $wert);
    }

    public function fuer(GeneratedDocument $dokument): string
    {
        return URL::temporarySignedRoute(
            'portal.downloads.stream',
            Carbon::now()->addMinutes($this->gueltigkeitMinuten()),
            ['generatedDocument' => $dokument->getKey()],
        );
    }

    /**
     * Frisch signierter Link auf die kontogebundene Route
     * portal.downloads.signed, wie ihn die Bestaetigungsmail nach der
     * Finalisierung traegt. Wird beim erneuten Versand einer Nachricht aus dem
     * Wiederholungspuffer benutzt, damit kein abgelaufener Link versendet wird.
     */
    public function signiert(string $generatedDocumentId): string
    {
        return URL::temporarySignedRoute(
            'portal.downloads.signed',
            Carbon::now()->addMinutes($this->gueltigkeitMinuten()),
            ['generatedDocument' => $generatedDocumentId],
        );
    }
}
