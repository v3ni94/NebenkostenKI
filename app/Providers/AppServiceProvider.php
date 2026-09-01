<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    /**
     * Ratenbegrenzungen fuer Upload und Download.
     *
     * Vorgabe: Rate Limits fuer Login, Reset, Upload, KI und Downloads
     * (Masterprompt 19). Die Grenzen fuer Anmeldung und Passwort-Reset stehen
     * bei den jeweiligen Routen in routes/auth.php.
     *
     * Die Grenzen sind bewusst pro Nutzer und nicht nur pro IP gesetzt: mehrere
     * Nutzer hinter einem Firmenanschluss teilen sich sonst dasselbe Kontingent.
     * Fuer nicht angemeldete Anfragen bleibt die IP der Schluessel.
     *
     * Der Chunk-Upload braucht ein hohes Kontingent, weil eine 25-MB-Datei bei
     * 4 MB je Abschnitt bereits sieben Anfragen erzeugt. Die eigentliche
     * Mengenbegrenzung leisten die Groessenlimits je Datei und je Lauf aus
     * config('smartabrechnen.uploads'), nicht die Ratenbegrenzung.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('uploads', function (Request $request): Limit {
            $user = $request->user();

            return $user !== null
                ? Limit::perMinute(120)->by('uploads:user:'.$user->getAuthIdentifier())
                : Limit::perMinute(20)->by('uploads:ip:'.$request->ip());
        });

        /*
         * Webhooks des Zahlungsanbieters. Die Grenze schuetzt vor einer Flut
         * gefaelschter Anfragen, ist aber bewusst hoch genug, damit die
         * Wiederholungsversuche von Stripe nach einem Ausfall nicht verworfen
         * werden. Die eigentliche Echtheitspruefung leistet die Signatur.
         */
        RateLimiter::for('webhooks', function (Request $request): Limit {
            return Limit::perMinute(120)->by('webhooks:'.$request->ip());
        });

        RateLimiter::for('downloads', function (Request $request): Limit {
            $user = $request->user();

            return $user !== null
                ? Limit::perMinute(60)->by('downloads:user:'.$user->getAuthIdentifier())
                : Limit::perMinute(15)->by('downloads:ip:'.$request->ip());
        });
    }
}
