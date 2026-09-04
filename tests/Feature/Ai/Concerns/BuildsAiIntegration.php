<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Concerns;

use App\Application\Documents\Support\ProviderFileDeleterResolver;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Enums\OrganizationRole;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Property;
use App\Models\TemporaryUpload;
use App\Models\User;
use App\Services\Ai\AiConfig;
use App\Services\Ai\AiDocumentProviderInterface;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\AiProviderRouter;
use App\Services\Ai\DailyCostLimiter;
use App\Services\Ai\DualReviewComparator;
use App\Services\Ai\Integration\AiCallRecorder;
use App\Services\Ai\Integration\AiDocumentClassifier;
use App\Services\Ai\Integration\AiDocumentExtractor;
use App\Services\Ai\Integration\DailyCostLedger;
use App\Services\Ai\Integration\DocumentPayloadFactory;
use App\Services\Ai\Integration\DocumentSchemaMap;
use App\Services\Ai\Integration\ExtractedFieldPersister;
use App\Services\Ai\Integration\OpenProviderFileGuard;
use App\Services\Ai\Integration\PromptVersionRegistrar;
use App\Services\Ai\ProviderReleaseGate;
use App\Services\Ai\Providers\FakeAiProvider;
use App\Services\Ai\RedactingLogger;
use App\Services\Ai\Schemas\SchemaRegistry;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Ai\Support\AiTestFactory;
use Tests\Unit\Storage\SampleFiles;

/**
 * Gemeinsame Grundlage der Tests der Adapterschicht.
 *
 * Es findet KEIN Netzwerkaufruf statt. Entweder laeuft der Testprovider ohne
 * Netzwerk oder ein aufgezeichneter Transportadapter. Der API-Key ist immer
 * ein erkennbar unechter Platzhalter.
 *
 * Die Bausteine werden hier bewusst von Hand zusammengesetzt und nicht ueber
 * den Container aufgeloest. So kann jeder Test Provider, Freigabestatus,
 * Konfidenzschwelle und Tagesbudget einzeln setzen, ohne die
 * Anwendungskonfiguration zu veraendern.
 */
trait BuildsAiIntegration
{
    /**
     * @var array{user: User, organization: Organization, billingRun: BillingRun}|null
     */
    private ?array $aiWelt = null;

    protected function setUpAiIntegration(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');

        Storage::fake(TemporaryUploadStorage::DISK);
        Storage::fake('local');
    }

    /**
     * @return array{user: User, organization: Organization, billingRun: BillingRun}
     */
    protected function aiWelt(): array
    {
        if ($this->aiWelt !== null) {
            return $this->aiWelt;
        }

        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationUser::factory()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => OrganizationRole::OWNER,
        ]);

        $property = Property::factory()->create([
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $user->getKey(),
        ]);

        $billingRun = BillingRun::factory()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
            'created_by_user_id' => $user->getKey(),
            'uploaded_bytes' => 0,
        ]);

        return $this->aiWelt = [
            'user' => $user,
            'organization' => $organization,
            'billingRun' => $billingRun,
        ];
    }

    /**
     * Dokument mit Quelldatei im Kurzzeitbereich, wie es die Pipeline vor der
     * Extraktion hinterlaesst.
     *
     * @return array{0: Document, 1: TemporaryUpload, 2: string}
     */
    protected function dokumentMitQuelldatei(
        DocumentType $type = DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL,
        ?string $inhalt = null,
        string $mimeType = 'application/pdf',
        int $seiten = 3,
    ): array {
        $welt = $this->aiWelt();
        $storage = new TemporaryUploadStorage;
        $prefix = $storage->newPrefix();

        // Verschluesselt ueber den Kurzzeitbereich, wie es die Pipeline tut.
        $storage->put($storage->originalKey($prefix), $inhalt ?? SampleFiles::pdf($seiten));

        $document = Document::factory()->processing()->create([
            'organization_id' => $welt['organization']->getKey(),
            'billing_run_id' => $welt['billingRun']->getKey(),
            'document_type' => $type,
            'source_label' => 'Dokument 01 - '.$type->label(),
            'mime_type' => $mimeType,
            'page_count' => $seiten,
            'processing_status' => DocumentProcessingStatus::EXTRAKTION,
        ]);

        $upload = TemporaryUpload::factory()->create([
            'organization_id' => $welt['organization']->getKey(),
            'document_id' => $document->getKey(),
            'storage_key' => $prefix,
        ]);

        return [$document, $upload, $prefix];
    }

    /**
     * Testprovider ohne Netzwerkaufruf, mit den Beispielantworten aus
     * tests/Fixtures/Ai.
     *
     * @param  array<string, string>  $fixtures  Schemaschluessel auf Dateiname.
     */
    protected function testProvider(array $fixtures = [], ?string $fixtureDirectory = null): FakeAiProvider
    {
        $provider = new FakeAiProvider(
            $fixtureDirectory ?? AiTestFactory::fixtureDirectory(),
            new SchemaRegistry,
            AiTestFactory::prompts(),
            AiTestFactory::validator(),
            AiTestFactory::confidence(),
            AiTestFactory::costEstimator(),
            new RedactingLogger,
        );

        foreach ($fixtures as $schemaKey => $fileName) {
            $provider = $provider->withFixture($schemaKey, $fileName);
        }

        return $provider;
    }

    /**
     * Router mit genau einem Provider. Fallback ist abgeschaltet, damit ein
     * Test nicht versehentlich einen zweiten Provider befragt.
     *
     * @param  array<string, mixed>  $configOverrides
     */
    protected function router(
        AiDocumentProviderInterface $provider,
        AiProviderKey $key = AiProviderKey::FAKE,
        array $configOverrides = [],
        string $environment = 'testing',
    ): AiProviderRouter {
        $config = AiConfig::fromArray(AiTestFactory::configArray(array_replace([
            'primary_provider' => $key->value,
            'fallback_provider' => null,
            'fallback_enabled' => false,
            'dual_review_enabled' => false,
        ], $configOverrides)));

        return new AiProviderRouter(
            $config,
            ProviderReleaseGate::fromConfig($config, $environment),
            new DualReviewComparator,
            new RedactingLogger,
            [$key->value => $provider],
        );
    }

    protected function persister(float $threshold = 0.80): ExtractedFieldPersister
    {
        return new ExtractedFieldPersister($threshold);
    }

    protected function callRecorder(): AiCallRecorder
    {
        return new AiCallRecorder(new PromptVersionRegistrar, new RedactingLogger);
    }

    protected function ledger(?int $dailyLimitCent = null): DailyCostLedger
    {
        return new DailyCostLedger(new DailyCostLimiter($dailyLimitCent));
    }

    protected function extractor(
        AiProviderRouter $router,
        ?int $dailyLimitCent = null,
        float $threshold = 0.80,
    ): AiDocumentExtractor {
        return new AiDocumentExtractor(
            $router,
            new DocumentPayloadFactory(new TemporaryUploadStorage),
            new DocumentSchemaMap(new SchemaRegistry),
            new SchemaRegistry,
            $this->ledger($dailyLimitCent),
            $this->callRecorder(),
            $this->persister($threshold),
            new RedactingLogger,
            $this->providerFileGuard(),
        );
    }

    /**
     * Raeumt offene Providerdateien ueber den im Container gebundenen
     * Loescher weg; ohne Bindung greift NullProviderFileDeleter, der nichts
     * bestaetigt.
     */
    protected function providerFileGuard(): OpenProviderFileGuard
    {
        return new OpenProviderFileGuard(new ProviderFileDeleterResolver($this->app), new RedactingLogger);
    }

    protected function classifier(AiProviderRouter $router, ?int $dailyLimitCent = null): AiDocumentClassifier
    {
        return new AiDocumentClassifier(
            $router,
            new DocumentPayloadFactory(new TemporaryUploadStorage),
            $this->ledger($dailyLimitCent),
            $this->callRecorder(),
            new RedactingLogger,
            $this->providerFileGuard(),
        );
    }
}
