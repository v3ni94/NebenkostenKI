<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Application\Documents\Contracts\ProviderFileDeleter;
use App\Application\Documents\DeleteOriginalSources;
use App\Application\Documents\Dto\DeletionReason;
use App\Application\Documents\RetryFailedDeletions;
use App\Enums\AiProvider;
use App\Enums\DeletionStatus;
use App\Models\Document;
use App\Models\SourceDeletionEvent;
use App\Models\TemporaryUpload;
use App\Services\Ai\AiConfig;
use App\Services\Ai\Integration\AiProviderFileDeleter;
use App\Services\Ai\RedactingLogger;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ai\Concerns\BuildsAiIntegration;
use Tests\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;
use Tests\Unit\Ai\Support\RecordingAiHttpClient;

/**
 * Nachweise zu Abschnitt 6.3 Schritt 14 und 18: die temporaere Providerdatei
 * wird ueber die Loeschschnittstelle entfernt, und ein Fehlschlag ist niemals
 * ein stiller Erfolg.
 *
 * Es findet KEIN Netzwerkaufruf statt; der Transport ist aufgezeichnet.
 */
class ProviderFileDeletionTest extends TestCase
{
    use BuildsAiIntegration, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiIntegration();
    }

    public function test_openai_loeschung_geht_an_die_files_api_und_gilt_erst_mit_bestaetigung(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(['id' => 'file-0815', 'deleted' => true]);

        $bericht = $this->deleter($http)->deleteProviderFile(AiProvider::OPENAI, 'file-0815');

        $this->assertTrue($bericht->isSuccessful());
        $this->assertSame(DeletionStatus::ERFOLGREICH, $bericht->status);

        $anfrage = $http->requestAt(0);

        $this->assertSame('DELETE', $anfrage->method);
        $this->assertSame('https://api.openai.com/v1/files/file-0815', $anfrage->url);
        $this->assertSame('Bearer '.AiTestFactory::API_KEY_PLACEHOLDER, $anfrage->headers['authorization']);
        $this->assertNull($anfrage->body(), 'Ein Loeschaufruf traegt keinen Body und damit keinen Inhalt.');
    }

    public function test_anthropic_loeschung_nutzt_die_eigenen_kopfzeilen(): void
    {
        $http = (new RecordingAiHttpClient)->pushRaw('', 204);

        $bericht = $this->deleter($http)->deleteProviderFile(AiProvider::ANTHROPIC, 'file_4711');

        $this->assertTrue($bericht->isSuccessful());

        $anfrage = $http->requestAt(0);

        $this->assertSame('https://api.anthropic.com/v1/files/file_4711', $anfrage->url);
        $this->assertSame(AiTestFactory::API_KEY_PLACEHOLDER, $anfrage->headers['x-api-key']);
        $this->assertSame('2023-06-01', $anfrage->headers['anthropic-version']);
    }

    public function test_nicht_bestaetigte_loeschung_gilt_als_fehlgeschlagen(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(['id' => 'file-0815', 'deleted' => false]);

        $bericht = $this->deleter($http)->deleteProviderFile(AiProvider::OPENAI, 'file-0815');

        $this->assertFalse($bericht->isSuccessful());
        $this->assertSame(DeletionStatus::FEHLGESCHLAGEN, $bericht->status);
        $this->assertSame(AiProviderFileDeleter::ERROR_NOT_CONFIRMED, $bericht->errorCode);
    }

    public function test_fehlerhafte_antwort_wird_als_fehlgeschlagen_gemeldet(): void
    {
        $http = (new RecordingAiHttpClient)->pushJson(['error' => ['code' => 'file_not_found']], 404);

        $bericht = $this->deleter($http)->deleteProviderFile(AiProvider::OPENAI, 'file-0815');

        $this->assertFalse($bericht->isSuccessful());
        $this->assertSame('file_not_found', $bericht->errorCode);
    }

    public function test_transportfehler_wird_nicht_zu_einem_stillen_erfolg(): void
    {
        $http = (new RecordingAiHttpClient)->pushTransportError('openai');

        $bericht = $this->deleter($http)->deleteProviderFile(AiProvider::OPENAI, 'file-0815');

        $this->assertFalse($bericht->isSuccessful());
        $this->assertSame(AiProviderFileDeleter::ERROR_CALL_FAILED, $bericht->errorCode);
    }

    public function test_fehlende_providerkonfiguration_meldet_einen_offenen_loeschvorgang(): void
    {
        $config = AiConfig::fromArray(AiTestFactory::configArray([
            'providers' => [
                'openai' => [
                    'api_key' => null,
                    'base_uri' => 'https://api.openai.com/v1/',
                    'model_extract' => 'gpt-5.6-luna',
                    'model_analyze' => 'gpt-5.6-terra',
                    'timeout_seconds' => 120,
                ],
            ],
        ]));

        $http = new RecordingAiHttpClient;

        $bericht = (new AiProviderFileDeleter($config, $http, new RedactingLogger))
            ->deleteProviderFile(AiProvider::OPENAI, 'file-0815');

        $this->assertFalse($bericht->isSuccessful());
        $this->assertSame(AiProviderFileDeleter::ERROR_NOT_CONFIGURED, $bericht->errorCode);
        $this->assertSame(0, $http->callCount());
    }

    public function test_leere_datei_id_erfordert_keine_loeschung(): void
    {
        $http = new RecordingAiHttpClient;

        $bericht = $this->deleter($http)->deleteProviderFile(AiProvider::OPENAI, '   ');

        $this->assertTrue($bericht->isSuccessful());
        $this->assertSame(DeletionStatus::NICHT_ERFORDERLICH, $bericht->status);
        $this->assertSame(0, $http->callCount());
    }

    public function test_fehlgeschlagene_providerloeschung_wird_wiederholt_und_beim_zweiten_lauf_erfolgreich(): void
    {
        [$document, $upload, $prefix] = $this->dokumentMitQuelldatei();

        $upload->forceFill([
            'provider' => AiProvider::OPENAI,
            'provider_file_id' => 'file-wiederholung-0815',
        ])->save();

        $fehlschlag = (new RecordingAiHttpClient)->setDefaultJson(['error' => ['code' => 'server_error']], 500);

        $this->app->instance(ProviderFileDeleter::class, $this->deleter($fehlschlag));

        $this->app->make(DeleteOriginalSources::class)(
            $document,
            DeletionReason::EXTRAKTION_ABGESCHLOSSEN,
        );

        $document->refresh();
        $upload->refresh();

        // Der lokale Loeschpfad laeuft trotz Providerfehler vollstaendig.
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
        $this->assertSame(DeletionStatus::FEHLGESCHLAGEN, $document->getAttribute('deletion_status'));
        $this->assertSame(DeletionStatus::FEHLGESCHLAGEN, $upload->getAttribute('provider_deletion_status'));
        $this->assertFalse($upload->getAttribute('is_tombstone'));

        $this->assertSame(
            1,
            SourceDeletionEvent::query()
                ->where('provider_deletion_status', DeletionStatus::FEHLGESCHLAGEN->value)
                ->count(),
        );

        // Zweiter Anlauf mit erreichbarer Loeschschnittstelle.
        $erfolg = (new RecordingAiHttpClient)->setDefaultJson(['id' => 'file-wiederholung-0815', 'deleted' => true]);

        $this->app->instance(ProviderFileDeleter::class, $this->deleter($erfolg));

        $bericht = $this->app->make(RetryFailedDeletions::class)();

        $this->assertSame(1, $bericht->deleted);

        $upload->refresh();

        $this->assertSame(DeletionStatus::ERFOLGREICH, $upload->getAttribute('provider_deletion_status'));
        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertNull($upload->getAttribute('provider_file_id'), 'Die Provider-Datei-ID wird nicht dauerhaft gespeichert.');
        $this->assertSame(
            DeletionStatus::ERFOLGREICH,
            Document::query()->whereKey($document->getKey())->firstOrFail()->getAttribute('deletion_status'),
        );
    }

    public function test_loeschnachweis_fuehrt_keine_datei_id_im_klartext(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $upload->forceFill([
            'provider' => AiProvider::OPENAI,
            'provider_file_id' => 'file-geheim-4711',
        ])->save();

        $this->app->instance(
            ProviderFileDeleter::class,
            $this->deleter((new RecordingAiHttpClient)->setDefaultJson(['deleted' => true])),
        );

        $this->app->make(DeleteOriginalSources::class)(
            $document,
            DeletionReason::EXTRAKTION_ABGESCHLOSSEN,
        );

        $nachweis = SourceDeletionEvent::query()->firstOrFail();

        foreach ($nachweis->getAttributes() as $spalte => $wert) {
            if (! is_string($wert)) {
                continue;
            }

            $this->assertStringNotContainsString(
                'file-geheim-4711',
                $wert,
                sprintf('Die Spalte "%s" fuehrt eine Provider-Datei-ID im Klartext.', $spalte),
            );
        }

        $this->assertNull(
            TemporaryUpload::query()->whereKey($upload->getKey())->firstOrFail()->getAttribute('provider_file_id'),
        );
    }

    private function deleter(RecordingAiHttpClient $http): AiProviderFileDeleter
    {
        return new AiProviderFileDeleter(
            AiConfig::fromArray(AiTestFactory::configArray()),
            $http,
            new RedactingLogger,
        );
    }
}
