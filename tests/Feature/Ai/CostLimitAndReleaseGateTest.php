<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Application\Documents\Contracts\DocumentExtractor;
use App\Application\Documents\StartExtraction;
use App\Enums\AiCallPurpose;
use App\Enums\AiCallStatus;
use App\Enums\AiProvider;
use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Models\AiCall;
use App\Models\ExtractedField;
use App\Models\TemporaryUpload;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\Integration\AiIntegrationErrorCode;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ai\Concerns\BuildsAiIntegration;
use Tests\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;
use Tests\Unit\Ai\Support\RecordingAiHttpClient;

/**
 * Nachweise zu Abschnitt 13.5 und 13.8: die Freigabesperre ist nicht
 * umgehbar, und das Tagesbudget bricht die Auswertung sauber ab, ohne
 * Quelldaten liegen zu lassen.
 */
class CostLimitAndReleaseGateTest extends TestCase
{
    use BuildsAiIntegration, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiIntegration();
    }

    public function test_ausgeschoepftes_tagesbudget_bricht_die_extraktion_ab(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $this->verbrauchteHeute(5);

        $http = new RecordingAiHttpClient;

        $outcome = $this->extractor(
            $this->router(AiTestFactory::openAiProvider($http), AiProviderKey::OPENAI),
            dailyLimitCent: 4,
        )->extract($document, $upload);

        $this->assertFalse($outcome->successful);
        $this->assertTrue($outcome->permanent);
        $this->assertSame(AiIntegrationErrorCode::TAGESLIMIT_ERREICHT->value, $outcome->errorCode);

        // Es wurde nichts uebertragen: die Datei verlaesst den Server nicht.
        $this->assertSame(0, $http->callCount());
        $this->assertSame(0, ExtractedField::query()->count());
    }

    public function test_verstaendliche_deutsche_meldung_zum_tagesbudget(): void
    {
        $this->assertStringContainsString(
            'Tagesbudget',
            AiIntegrationErrorCode::TAGESLIMIT_ERREICHT->message(),
        );
        $this->assertStringContainsString(
            'gelöscht',
            AiIntegrationErrorCode::TAGESLIMIT_ERREICHT->message(),
        );
        $this->assertTrue(AiIntegrationErrorCode::TAGESLIMIT_ERREICHT->isPermanent());
    }

    public function test_tagesbudget_abbruch_laesst_keine_quelldaten_liegen(): void
    {
        [$document, $upload, $prefix] = $this->dokumentMitQuelldatei();

        $this->verbrauchteHeute(12);

        $this->app->instance(
            DocumentExtractor::class,
            $this->extractor(
                $this->router(AiTestFactory::openAiProvider(new RecordingAiHttpClient), AiProviderKey::OPENAI),
                dailyLimitCent: 10,
            ),
        );

        $this->app->make(StartExtraction::class)($document);

        $document->refresh();

        $this->assertSame(DocumentProcessingStatus::FEHLGESCHLAGEN, $document->getAttribute('processing_status'));
        $this->assertSame(DeletionStatus::ERFOLGREICH, $document->getAttribute('deletion_status'));
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
        $this->assertTrue(TemporaryUpload::query()->whereKey($upload->getKey())->firstOrFail()->getAttribute('is_tombstone'));
    }

    public function test_verbrauch_anderer_nutzer_zaehlt_nicht_auf_das_eigene_tagesbudget(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        // Aufruf eines fremden Abrechnungslaufs mit eigenem Ersteller.
        AiCall::factory()->create(['cost_cent' => 900]);

        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')),
        );

        $outcome = $this->extractor(
            $this->router(AiTestFactory::openAiProvider($http), AiProviderKey::OPENAI),
            dailyLimitCent: 10,
        )->extract($document, $upload);

        $this->assertTrue($outcome->successful);
        $this->assertSame(1, $http->callCount());
    }

    public function test_verbrauch_von_gestern_belastet_das_heutige_budget_nicht(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $gestern = $this->verbrauchteHeute(900);
        $gestern->forceFill(['created_at' => now()->subDay()->startOfDay()])->save();

        $http = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')),
        );

        $outcome = $this->extractor(
            $this->router(AiTestFactory::openAiProvider($http), AiProviderKey::OPENAI),
            dailyLimitCent: 10,
        )->extract($document, $upload);

        $this->assertTrue($outcome->successful);
    }

    public function test_produktive_umgebung_ohne_datenschutzfreigabe_blockiert_die_extraktion(): void
    {
        [$document, $upload, $prefix] = $this->dokumentMitQuelldatei();

        $http = new RecordingAiHttpClient;

        $router = $this->router(
            AiTestFactory::openAiProvider($http),
            AiProviderKey::OPENAI,
            ['data_retention_approved' => false, 'require_zero_data_retention' => true],
            environment: 'production',
        );

        $outcome = $this->extractor($router)->extract($document, $upload);

        $this->assertFalse($outcome->successful);
        $this->assertSame('KI_SCHICHT_NICHT_VERFUEGBAR', $outcome->errorCode);
        $this->assertFalse(
            $outcome->permanent,
            'Eine fehlende Freigabe ist ein betrieblicher Zustand: der Teiljob wird wiederholt und loescht die Quelldaten spaetestens nach dem letzten Versuch.'
        );

        // Entscheidend: es wurde nichts an den Provider uebertragen.
        $this->assertSame(0, $http->callCount());
        $this->assertSame(0, ExtractedField::query()->count());
        $this->assertSame(0, AiCall::query()->count());

        // Die Quelldatei bleibt fuer den Wiederholungsversuch im Kurzzeitbereich.
        $this->assertNotSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
    }

    public function test_produktive_umgebung_ohne_datenschutzfreigabe_blockiert_die_klassifikation(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $http = new RecordingAiHttpClient;

        $router = $this->router(
            AiTestFactory::openAiProvider($http),
            AiProviderKey::OPENAI,
            ['data_retention_approved' => false, 'require_zero_data_retention' => true],
            environment: 'production',
        );

        $outcome = $this->classifier($router)->classify($document, $upload);

        $this->assertFalse($outcome->successful);
        $this->assertNull($outcome->documentType);
        $this->assertSame('KI_SCHICHT_NICHT_VERFUEGBAR', $outcome->errorCode);
        $this->assertSame(0, $http->callCount());
    }

    public function test_testprovider_ist_produktiv_gesperrt(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $router = $this->router($this->testProvider(), AiProviderKey::FAKE, environment: 'production');

        $outcome = $this->extractor($router)->extract($document, $upload);

        $this->assertFalse($outcome->successful);
        $this->assertSame('KI_SCHICHT_NICHT_VERFUEGBAR', $outcome->errorCode);
        $this->assertSame(0, ExtractedField::query()->count());
    }

    /**
     * Bereits verbrauchtes Tagesbudget des Nutzers dieses Laufs.
     */
    private function verbrauchteHeute(int $costCent): AiCall
    {
        $welt = $this->aiWelt();

        return AiCall::factory()->create([
            'organization_id' => $welt['organization']->getKey(),
            'billing_run_id' => $welt['billingRun']->getKey(),
            'provider' => AiProvider::OPENAI,
            'purpose' => AiCallPurpose::EXTRAKTION,
            'status' => AiCallStatus::ERFOLGREICH,
            'cost_cent' => $costCent,
        ]);
    }
}
