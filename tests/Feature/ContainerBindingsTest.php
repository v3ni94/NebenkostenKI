<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Documents\Contracts\DocumentClassifier;
use App\Application\Documents\Contracts\DocumentExtractor;
use App\Application\Documents\Contracts\ProviderFileDeleter;
use App\Application\Payment\Contracts\FinalDocumentViews;
use App\Providers\AiServiceProvider;
use App\Services\Payment\Contracts\CheckoutClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jede Vertragsschnittstelle muss aus dem Container aufloesbar sein.
 *
 * ANLASS: Die Schnittstelle CheckoutClient war nicht gebunden. Der Container
 * kann eine Schnittstelle ohne Bindung nicht aufloesen, weshalb die
 * Zahlungsseite mit einem Fehler endete. Die Tests des Zahlungspakets liefen
 * trotzdem gruen, weil sie eine Testdopplung direkt in den Use Case
 * injizieren. Aufgefallen ist es erst beim Aufruf im Browser.
 *
 * Dieser Test schliesst die Luecke fuer die gesamte Klasse von Fehlern: Wird
 * kuenftig eine Schnittstelle ergaenzt, aber die Bindung vergessen, schlaegt
 * er fehl, statt erst im Betrieb aufzufallen.
 *
 * Hinweis zur KI-Anbindung: In der Testumgebung ist sie nur gebunden, wenn
 * ai.bind_document_pipeline ausdruecklich eingeschaltet ist. Der Test schaltet
 * sie deshalb fuer die drei Dokumentvertraege ein. Das ist keine Aufweichung
 * des Nachweises, dass die Pipeline auch ohne KI-Anbindung sauber laeuft; er
 * steht unveraendert in den Loeschtests.
 */
final class ContainerBindingsTest extends TestCase
{
    /**
     * @return array<string, array{class-string, bool}>
     */
    public static function vertraege(): array
    {
        return [
            'Zahlungsanbieter' => [CheckoutClient::class, false],
            'Aufbereitung der Final-PDFs' => [FinalDocumentViews::class, false],
            'Dokumentklassifikation' => [DocumentClassifier::class, true],
            'Dokumentextraktion' => [DocumentExtractor::class, true],
            'Loeschung der Providerdatei' => [ProviderFileDeleter::class, true],
        ];
    }

    /**
     * @param  class-string  $vertrag
     */
    #[DataProvider('vertraege')]
    public function test_vertrag_ist_aus_dem_container_aufloesbar(string $vertrag, bool $braucht_ki_anbindung): void
    {
        if ($braucht_ki_anbindung) {
            config(['ai.bind_document_pipeline' => true, 'ai.primary_provider' => 'fake']);

            // Der Provider bindet beim Registrieren, deshalb muss die
            // Anwendung die geaenderte Konfiguration erneut lesen.
            $this->refreshApplicationWithConfig();
        }

        $umsetzung = $this->app?->make($vertrag);

        self::assertInstanceOf($vertrag, $umsetzung);
    }

    /**
     * Startet die Anwendung mit der gesetzten Konfiguration neu.
     */
    private function refreshApplicationWithConfig(): void
    {
        $bindung = config('ai.bind_document_pipeline');
        $provider = config('ai.primary_provider');

        $this->refreshApplication();

        config([
            'ai.bind_document_pipeline' => $bindung,
            'ai.primary_provider' => $provider,
        ]);

        $this->app?->register(AiServiceProvider::class, true);
    }
}
