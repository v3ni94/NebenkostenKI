<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Application\Documents\Contracts\DocumentClassifier;
use App\Application\Documents\Contracts\DocumentExtractor;
use App\Application\Documents\Contracts\ProviderFileDeleter;
use App\Application\Documents\Support\AiPipelineResolver;
use App\Application\Documents\Support\NullProviderFileDeleter;
use App\Application\Documents\Support\ProviderFileDeleterResolver;
use App\Providers\AiServiceProvider;
use App\Services\Ai\AiDocumentProviderInterface;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\AiProviderRouter;
use App\Services\Ai\Integration\AiDocumentClassifier;
use App\Services\Ai\Integration\AiDocumentExtractor;
use App\Services\Ai\Integration\AiProviderFileDeleter;
use App\Services\Ai\ProviderReleaseGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nachweise zur Verdrahtung.
 *
 * Wichtigster Punkt: Ohne Bindung laeuft die Pipeline bis Dead Letter und
 * loescht die Quelldaten sofort. Es bleiben also unter keinen Umstaenden
 * Originaldateien liegen, auch nicht bei fehlender oder gesperrter
 * KI-Anbindung.
 */
class AiServiceProviderBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ohne_bindung_meldet_die_pipeline_eine_fehlende_ki_schicht(): void
    {
        // Standard der Testumgebung: die Vertraege sind nicht gebunden.
        $resolver = $this->app->make(AiPipelineResolver::class);

        $this->assertNull($resolver->classifier());
        $this->assertNull($resolver->extractor());

        // Der Loeschpfad haengt ausdruecklich nicht an der KI-Schicht.
        $this->assertInstanceOf(
            NullProviderFileDeleter::class,
            $this->app->make(ProviderFileDeleterResolver::class)->resolve(),
        );
    }

    public function test_mit_eingeschalteter_bindung_liefert_der_container_die_adapterschicht(): void
    {
        $this->bindeKiAnbindung();

        $resolver = $this->app->make(AiPipelineResolver::class);

        $this->assertInstanceOf(AiDocumentClassifier::class, $resolver->classifier());
        $this->assertInstanceOf(AiDocumentExtractor::class, $resolver->extractor());
        $this->assertInstanceOf(
            AiProviderFileDeleter::class,
            $this->app->make(ProviderFileDeleterResolver::class)->resolve(),
        );
    }

    public function test_alle_drei_vertraege_sind_gebunden(): void
    {
        $this->bindeKiAnbindung();

        foreach ([DocumentClassifier::class, DocumentExtractor::class, ProviderFileDeleter::class] as $contract) {
            $this->assertTrue($this->app->bound($contract), $contract.' ist nicht gebunden.');
        }
    }

    public function test_der_router_ist_die_providerunabhaengige_schnittstelle(): void
    {
        $router = $this->app->make(AiDocumentProviderInterface::class);

        $this->assertInstanceOf(AiProviderRouter::class, $router);

        // phpunit.xml stellt den Testprovider ein; es gibt keinen echten
        // Providerzugriff im Standardtestlauf.
        $this->assertSame(AiProviderKey::FAKE, $router->primaryProviderKey());
    }

    public function test_der_testprovider_wird_nur_ausserhalb_der_produktion_aufgebaut(): void
    {
        $gate = $this->app->make(ProviderReleaseGate::class);

        $this->assertTrue($gate->isNonProductionEnvironment());
        $this->assertTrue($gate->isReleased(AiProviderKey::FAKE));

        // Ohne Datenschutzfreigabe bleiben die externen Provider gesperrt.
        $this->assertFalse($gate->isReleased(AiProviderKey::OPENAI));
        $this->assertStringContainsString(
            'AI_DATA_RETENTION_APPROVED',
            (string) $gate->blockReason(AiProviderKey::OPENAI),
        );
    }

    private function bindeKiAnbindung(): void
    {
        config(['ai.bind_document_pipeline' => true]);

        $this->app->register(new AiServiceProvider($this->app), true);
    }
}
