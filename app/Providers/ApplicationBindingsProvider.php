<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Calculation\FinalDocumentViewsFromSnapshot;
use App\Application\Payment\Contracts\FinalDocumentViews;
use App\Application\Payment\Events\BillingRunFinalized;
use App\Jobs\FailDocumentOnDeadLetter;
use App\Listeners\SendFinalizationMails;
use App\Listeners\SendProcessingStatusMails;
use App\Models\Document;
use App\Services\Payment\Contracts\CheckoutClient;
use App\Services\Payment\StripeGateway;
use App\Services\Queue\DeadLetterListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Verdrahtung der fachlichen Nahtstellen zwischen den Anwendungspaketen.
 *
 * WARUM DIESER PROVIDER UND KEIN EIGENER EventServiceProvider
 *
 * Es geht hier um genau eine Sache: die beiden Nahtstellen, an denen das
 * Zahlungspaket auf andere Pakete trifft. Das ist einmal die Aufbereitung des
 * gesperrten Berechnungsstandes zu Darstellungsobjekten und einmal der Versand
 * der Bestaetigungsmails nach der Finalisierung. Beides gehoert fachlich
 * zusammen und ist in einer Datei in einem Blick pruefbar. Ein zusaetzlicher
 * EventServiceProvider haette nur eine einzige Zeile getragen und die Frage
 * "wo wird die Finalisierung angeschlossen" auf zwei Dateien verteilt.
 *
 * AppServiceProvider bleibt unberuehrt: dort stehen die HTTP-nahen
 * Ratenbegrenzungen. AiServiceProvider bleibt ebenfalls unberuehrt, weil er
 * ausschliesslich die KI-Schicht mit ihren eigenen Datenschutz-, Freigabe- und
 * Kostenregeln traegt und im Test gezielt ersetzt wird. Registriert wird
 * dieser Provider in bootstrap/providers.php, der dafuer vorgesehenen
 * Providerliste von Laravel 12.
 *
 * BINDUNG DER FINALDOKUMENTE: Ohne diese Bindung bricht die Finalisierung mit
 * einer klaren Meldung ab und erzeugt insbesondere keine ersatzweise
 * berechneten Werte. Gebunden wird die Umsetzung des Berechnungspakets, die
 * den Snapshot als einzige Quelle liest und dieselbe Fabrik verwendet wie die
 * Vorschau (Abschnitt 14.3).
 */
final class ApplicationBindingsProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FinalDocumentViews::class, FinalDocumentViewsFromSnapshot::class);

        /*
         * Zahlungsanbieter.
         *
         * StartCheckout und CancelCheckout verlangen das Interface
         * CheckoutClient. Ohne diese Bindung kann der Container es nicht
         * aufloesen und die Zahlungsseite endet in einem Fehler. Die Tests des
         * Zahlungspakets injizieren eine Testdopplung direkt und haben die
         * fehlende Bindung deshalb nicht aufgedeckt; sichtbar wurde sie erst
         * beim Aufruf im Browser.
         *
         * Die Bindung ist bewusst unabhaengig davon gesetzt, ob ein
         * Stripe-Schluessel konfiguriert ist. Fehlt er, meldet der Gateway das
         * beim Aufruf mit einer klaren Meldung, und der Adminbereich fuehrt es
         * als Livegang-Blocker. Eine stillschweigend fehlende Bindung wuerde
         * dagegen erst im Betrieb auffallen.
         */
        $this->app->bind(CheckoutClient::class, StripeGateway::class);

        // Schliesst ein Dokument ab, dessen Teiljob endgueltig in Dead Letter
        // geht: Kennzeichnung und sofortige Loeschung der Quelldaten.
        $this->app->bind(DeadLetterListener::class, FailDocumentOnDeadLetter::class);
    }

    public function boot(): void
    {
        // Der Versand der Bestaetigungsmail haengt am Ereignis und nicht am
        // Use Case. Damit bleibt die Finalisierung frei von Mailkenntnis.
        // Genau eine Registrierung. Der Methodenname ist absichtlich nicht
        // handle() oder __invoke(), damit die automatische Listener-Erkennung
        // von Laravel 12 nicht zusaetzlich registriert und die
        // Bestaetigungsmail nicht doppelt versendet wird.
        Event::listen(
            BillingRunFinalized::class,
            [SendFinalizationMails::class, 'versendeBestaetigungen'],
        );

        // Statusmails zur Dokumentverarbeitung haengen am Modellereignis des
        // Dokuments. Auch hier ein eigener Methodenname, damit nichts doppelt
        // registriert wird.
        Event::listen(
            'eloquent.updated: '.Document::class,
            [SendProcessingStatusMails::class, 'dokumentAktualisiert'],
        );
    }
}
