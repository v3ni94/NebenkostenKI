<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Application\Admin\PrivacyMonitor;
use App\Application\Documents\Contracts\DocumentExtractor;
use App\Application\Documents\Contracts\ProviderFileDeleter;
use App\Application\Documents\RetryFailedDeletions;
use App\Application\Documents\StartExtraction;
use App\Enums\AiCallStatus;
use App\Enums\AiProvider;
use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Enums\ProcessingJobStatus;
use App\Jobs\DocumentPipeline;
use App\Jobs\ExtractDocumentJob;
use App\Models\AiCall;
use App\Models\Document;
use App\Models\ProcessingJob;
use App\Models\SourceDeletionEvent;
use App\Models\TemporaryUpload;
use App\Services\Ai\AiConfig;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\AiProviderRouter;
use App\Services\Ai\DualReviewComparator;
use App\Services\Ai\Http\AiHttpClientInterface;
use App\Services\Ai\Http\AiHttpRequest;
use App\Services\Ai\Http\AiHttpResponse;
use App\Services\Ai\Integration\AiIntegrationErrorCode;
use App\Services\Ai\Integration\AiProviderFileDeleter;
use App\Services\Ai\ProviderReleaseGate;
use App\Services\Ai\RedactingLogger;
use App\Services\Queue\DatabaseJobQueue;
use App\Services\Queue\JobContext;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ai\Concerns\BuildsAiIntegration;
use Tests\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;
use Tests\Unit\Ai\Support\RecordingAiHttpClient;

/**
 * Nachweise fuer die Verdrahtung zwischen KI-Schicht und Kurzzeitdatensatz
 * (Abschnitt 6.3 Schritte 9, 13 und 14, ADR-007, Bedrohungen T5, T11, T12):
 *
 * - Die ID einer temporaeren Providerdatei stammt aus dem echten
 *   Extraktionslauf und wird nicht von einer Fixture gesetzt.
 * - Scheitert die Loeschung, sieht der Loeschpfad den Fall, der Nachweis ist
 *   richtig, und die Wiederholung loescht genau diese Datei.
 * - Der Verbrauch verworfener oder abgebrochener Provideraufrufe ist in
 *   ai_calls nachgewiesen.
 * - Der Teiljob verlaengert sein Lease vor jedem Providerrequest.
 *
 * Es findet KEIN Netzwerkaufruf statt; der Transport ist aufgezeichnet.
 */
class ProviderFileLifecycleTest extends TestCase
{
    use BuildsAiIntegration, RefreshDatabase;

    /**
     * Inline-Grenze in Byte, unter der die Beispieldatei bewusst liegt, damit
     * der Umweg ueber die Files-API des Providers genommen wird.
     */
    private const FILES_API_GRENZE = 10;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiIntegration();
    }

    public function test_fehlgeschlagene_providerloeschung_im_extraktionslauf_wird_persistiert_wiederholt_und_richtig_nachgewiesen(): void
    {
        [$document, $upload, $prefix] = $this->dokumentMitQuelldatei();

        // Upload gelingt, Extraktion ist schemavalide, DELETE antwortet 500.
        $http = (new RecordingAiHttpClient)
            ->pushJson(['id' => 'file_lauf_0815', 'object' => 'file'])
            ->pushJson(AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')))
            ->pushJson(['error' => ['code' => 'server_error']], 500);

        $this->app->instance(
            DocumentExtractor::class,
            $this->extractor($this->router(AiTestFactory::openAiProvider($http, inlineMaxBytes: self::FILES_API_GRENZE), AiProviderKey::OPENAI)),
        );

        // Auch der Loeschpfad erreicht den Provider zunaechst nicht.
        $loeschversuche = (new RecordingAiHttpClient)->setDefaultJson(['error' => ['code' => 'server_error']], 500);
        $this->app->instance(ProviderFileDeleter::class, $this->deleter($loeschversuche));

        $outcome = $this->app->make(StartExtraction::class)($document);

        $this->assertTrue($outcome->successful, 'Die Extraktion selbst bleibt gueltig, die Loeschung ist getrennt.');

        $document->refresh();
        $upload->refresh();

        // Der Fall ist im Kurzzeitdatensatz sichtbar: Provider, ID und Status.
        $this->assertSame(AiProvider::OPENAI, $upload->getAttribute('provider'));
        $this->assertSame('file_lauf_0815', $upload->getAttribute('provider_file_id'));
        $this->assertSame(DeletionStatus::FEHLGESCHLAGEN, $upload->getAttribute('provider_deletion_status'));
        $this->assertFalse($upload->getAttribute('is_tombstone'));

        // Die ID liegt nur verschluesselt in der Datenbank.
        $roh = (string) DB::table('temporary_uploads')->where('id', $upload->getKey())->value('provider_file_id');
        $this->assertNotSame('', $roh);
        $this->assertStringNotContainsString('file_lauf_0815', $roh);

        // Der Loeschpfad hat die Loeschung sofort erneut versucht, mit genau
        // dieser ID, und den Fehlschlag richtig nachgewiesen.
        $this->assertSame('DELETE https://api.openai.com/v1/files/file_lauf_0815', $loeschversuche->urls()[0]);
        $this->assertSame(DocumentProcessingStatus::ABGESCHLOSSEN, $document->getAttribute('processing_status'));
        $this->assertSame(DeletionStatus::FEHLGESCHLAGEN, $document->getAttribute('deletion_status'));
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix), 'Die lokale Loeschung laeuft trotzdem.');

        $nachweis = SourceDeletionEvent::query()->firstOrFail();
        $this->assertSame(DeletionStatus::FEHLGESCHLAGEN, $nachweis->getAttribute('provider_deletion_status'));
        $this->assertSame(AiProvider::OPENAI, $nachweis->getAttribute('provider'));
        $this->assertSame(UploadErrorCode::PROVIDER_LOESCHUNG_FEHLGESCHLAGEN->value, $nachweis->getAttribute('error_code'));

        // Der Adminbereich zeigt den Alarm.
        $this->assertSame(1, $this->app->make(PrivacyMonitor::class)->failedDeletionCount());
        $this->assertSame(1, $this->app->make(RetryFailedDeletions::class)->openAlertCount());

        // Zweiter Anlauf mit erreichbarer Loeschschnittstelle: die Wiederholung
        // loescht genau die Datei aus dem Extraktionslauf.
        $erfolg = (new RecordingAiHttpClient)->setDefaultJson(['id' => 'file_lauf_0815', 'deleted' => true]);
        $this->app->instance(ProviderFileDeleter::class, $this->deleter($erfolg));

        $bericht = $this->app->make(RetryFailedDeletions::class)();

        $this->assertSame(1, $bericht->deleted);
        $this->assertSame('DELETE https://api.openai.com/v1/files/file_lauf_0815', $erfolg->urls()[0]);

        $upload->refresh();
        $document->refresh();

        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertNull($upload->getAttribute('provider_file_id'), 'Nach bestaetigter Loeschung wird die ID entfernt.');
        $this->assertSame(DeletionStatus::ERFOLGREICH, $upload->getAttribute('provider_deletion_status'));
        $this->assertSame(DeletionStatus::ERFOLGREICH, $document->getAttribute('deletion_status'));
        $this->assertSame(0, $this->app->make(RetryFailedDeletions::class)->openAlertCount());
    }

    public function test_erfolgreiche_providerloeschung_entfernt_die_id_und_erscheint_im_nachweis_als_erfolgreich(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $http = (new RecordingAiHttpClient)
            ->pushJson(['id' => 'file_lauf_4711', 'object' => 'file'])
            ->pushJson(AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')))
            ->pushJson(['id' => 'file_lauf_4711', 'deleted' => true]);

        $this->app->instance(
            DocumentExtractor::class,
            $this->extractor($this->router(AiTestFactory::openAiProvider($http, inlineMaxBytes: self::FILES_API_GRENZE), AiProviderKey::OPENAI)),
        );

        $loescher = new RecordingAiHttpClient;
        $this->app->instance(ProviderFileDeleter::class, $this->deleter($loescher));

        $this->app->make(StartExtraction::class)($document);

        $upload->refresh();

        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertNull($upload->getAttribute('provider_file_id'));
        $this->assertSame(AiProvider::OPENAI, $upload->getAttribute('provider'));
        $this->assertSame(DeletionStatus::ERFOLGREICH, $upload->getAttribute('provider_deletion_status'));
        $this->assertNotNull($upload->getAttribute('provider_file_deleted_at'));
        $this->assertSame(0, $loescher->callCount(), 'Eine bereits geloeschte Datei wird nicht erneut geloescht.');

        // Der Nachweis sagt nicht "nicht erforderlich": es gab eine Providerdatei.
        $nachweis = SourceDeletionEvent::query()->firstOrFail();
        $this->assertSame(DeletionStatus::ERFOLGREICH, $nachweis->getAttribute('provider_deletion_status'));
        $this->assertSame(AiProvider::OPENAI, $nachweis->getAttribute('provider'));
    }

    public function test_waehrend_der_verarbeitung_ist_die_providerdatei_als_offen_sichtbar(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        $monitor = $this->app->make(PrivacyMonitor::class);

        // Der Transport prueft mitten im Aufruf, was der Datenschutzmonitor
        // zu diesem Zeitpunkt zeigt: die Datei liegt beim Provider.
        $http = new class($upload, $monitor) implements AiHttpClientInterface
        {
            /** @var array{offen: int, status: mixed, id: mixed}|null */
            public ?array $gesehen = null;

            private readonly RecordingAiHttpClient $inner;

            public function __construct(
                private readonly TemporaryUpload $upload,
                private readonly PrivacyMonitor $monitor,
            ) {
                $this->inner = (new RecordingAiHttpClient)
                    ->pushJson(['id' => 'file_lauf_0099', 'object' => 'file'])
                    ->pushJson(AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')))
                    ->pushJson(['id' => 'file_lauf_0099', 'deleted' => true]);
            }

            public function send(AiHttpRequest $request): AiHttpResponse
            {
                if ($request->method === 'POST' && str_ends_with($request->url, '/responses')) {
                    $this->upload->refresh();

                    $this->gesehen = [
                        'offen' => $this->monitor->openProviderDeletionCount(),
                        'status' => $this->upload->getAttribute('provider_deletion_status'),
                        'id' => $this->upload->getAttribute('provider_file_id'),
                    ];
                }

                return $this->inner->send($request);
            }
        };

        $this->extractor($this->router(AiTestFactory::openAiProvider($http, inlineMaxBytes: self::FILES_API_GRENZE), AiProviderKey::OPENAI))
            ->extract($document, $upload);

        $gesehen = $http->gesehen;

        $this->assertIsArray($gesehen);
        $this->assertSame(1, $gesehen['offen'], 'Vor dem Verarbeitungsrequest ist die Providerdatei bereits nachverfolgbar.');
        $this->assertSame(DeletionStatus::OFFEN, $gesehen['status']);
        $this->assertSame('file_lauf_0099', $gesehen['id']);

        $this->assertSame(0, $monitor->openProviderDeletionCount());
    }

    public function test_solange_eine_providerdatei_offen_ist_wird_keine_zweite_angelegt(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei();

        // Zustand nach einer fehlgeschlagenen Loeschung der Klassifikationsdatei.
        $upload->forceFill([
            'provider' => AiProvider::OPENAI,
            'provider_file_id' => 'file_klassifikation_offen',
            'provider_deletion_status' => DeletionStatus::FEHLGESCHLAGEN,
        ])->save();

        $http = new RecordingAiHttpClient;

        $outcome = $this->extractor($this->router(AiTestFactory::openAiProvider($http, inlineMaxBytes: self::FILES_API_GRENZE), AiProviderKey::OPENAI))
            ->extract($document, $upload);

        $this->assertFalse($outcome->successful);
        $this->assertSame(0, $http->callCount(), 'Es darf keine zweite Providerdatei angelegt werden, solange die erste offen ist.');
        $this->assertSame('file_klassifikation_offen', $upload->refresh()->getAttribute('provider_file_id'), 'Die offene ID bleibt erhalten.');
    }

    public function test_verbrauch_des_primaerproviders_bleibt_bei_schema_fallback_nachgewiesen(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei(DocumentType::RECHNUNG);

        $fehlerhaft = AiTestFactory::fixture('rechnung_bescheid_schemaverletzung.json');

        $openai = (new RecordingAiHttpClient)
            ->pushJson(AiTestFactory::openAiResponseBody($fehlerhaft, 8_000, 400))
            ->pushJson(AiTestFactory::openAiResponseBody($fehlerhaft, 8_000, 400))
            ->pushJson(AiTestFactory::openAiResponseBody($fehlerhaft, 8_000, 400));

        $anthropic = (new RecordingAiHttpClient)->pushJson(
            AiTestFactory::anthropicResponseBody(AiTestFactory::fixture('rechnung_bescheid.json'), 5_000, 900),
        );

        $outcome = $this->extractor($this->routerMitFallback($openai, $anthropic))->extract($document, $upload);

        $this->assertTrue($outcome->successful);
        $this->assertSame(3, $openai->callCount());
        $this->assertSame(1, $anthropic->callCount());

        $calls = AiCall::query()->orderBy('created_at')->orderBy('id')->get();

        $this->assertCount(2, $calls, 'Beide Provideraufrufe sind nachgewiesen.');

        $primaer = $calls->firstWhere('provider', AiProvider::OPENAI);
        $fallback = $calls->firstWhere('provider', AiProvider::ANTHROPIC);

        $this->assertNotNull($primaer);
        $this->assertNotNull($fallback);

        $this->assertSame(AiCallStatus::SCHEMA_FEHLER, $primaer->getAttribute('status'));
        $this->assertSame(24_000, $primaer->getAttribute('input_tokens'));
        $this->assertSame(1_200, $primaer->getAttribute('output_tokens'));
        $this->assertSame(3, $primaer->getAttribute('attempt'));
        $this->assertSame(AiIntegrationErrorCode::SCHEMA_UNGUELTIG->value, $primaer->getAttribute('error_code'));
        // Rechenweg: 24.000 Eingabetoken zu 100 US-Cent je Million ergeben
        // 2.400 Tausendstel-Cent, 1.200 Ausgabetoken zu 500 US-Cent je
        // Million ergeben 600 Tausendstel-Cent. Summe 3.000, also 3 Cent.
        $this->assertSame(3, $primaer->getAttribute('cost_cent'));

        $this->assertSame(AiCallStatus::ERFOLGREICH, $fallback->getAttribute('status'));
        $this->assertSame(5_000, $fallback->getAttribute('input_tokens'));
    }

    public function test_verbrauch_vor_einer_ratenbegrenzung_bleibt_nachgewiesen(): void
    {
        [$document, $upload] = $this->dokumentMitQuelldatei(DocumentType::RECHNUNG);

        $http = (new RecordingAiHttpClient)
            ->pushJson(AiTestFactory::openAiResponseBody(AiTestFactory::fixture('rechnung_bescheid_schemaverletzung.json'), 8_000, 400))
            ->pushJson(['error' => ['code' => 'rate_limit_exceeded']], 429, ['retry-after' => '30']);

        $outcome = $this->extractor($this->router(AiTestFactory::openAiProvider($http), AiProviderKey::OPENAI))
            ->extract($document, $upload);

        $this->assertFalse($outcome->successful);
        $this->assertFalse($outcome->permanent);

        $call = AiCall::query()->firstOrFail();

        $this->assertSame(AiCallStatus::RATE_LIMIT, $call->getAttribute('status'));
        $this->assertSame(8_000, $call->getAttribute('input_tokens'));
        $this->assertSame(400, $call->getAttribute('output_tokens'));
        $this->assertSame(2, $call->getAttribute('attempt'));
        $this->assertSame(AiIntegrationErrorCode::PROVIDER_RATE_LIMIT->value, $call->getAttribute('error_code'));
        $this->assertFalse($call->getAttribute('schema_valid'));
    }

    public function test_teiljob_verlaengert_sein_lease_vor_jedem_providerrequest(): void
    {
        [$document] = $this->dokumentMitQuelldatei();

        $start = Carbon::parse('2026-09-04 10:00:00');
        Carbon::setTestNow($start);

        // Jeder Providerrequest dauert 100 Sekunden. Upload, Verarbeitung und
        // Loeschung ergeben 300 Sekunden, also genau die Laufzeit des Lease.
        $http = new class implements AiHttpClientInterface
        {
            private readonly RecordingAiHttpClient $inner;

            public function __construct()
            {
                $this->inner = (new RecordingAiHttpClient)
                    ->pushJson(['id' => 'file_lauf_0300', 'object' => 'file'])
                    ->pushJson(AiTestFactory::openAiResponseBody(AiTestFactory::fixture('hausgeldabrechnung.json')))
                    ->pushJson(['id' => 'file_lauf_0300', 'deleted' => true]);
            }

            public function send(AiHttpRequest $request): AiHttpResponse
            {
                Carbon::setTestNow(Carbon::now()->addSeconds(100));

                return $this->inner->send($request);
            }
        };

        $this->app->instance(
            DocumentExtractor::class,
            $this->extractor($this->router(AiTestFactory::openAiProvider($http, inlineMaxBytes: self::FILES_API_GRENZE), AiProviderKey::OPENAI)),
        );

        $queue = $this->app->make(DatabaseJobQueue::class);
        $this->app->make(DocumentPipeline::class)->queueExtraction($document);

        $job = $queue->claim('lauf-a');
        $this->assertInstanceOf(ProcessingJob::class, $job);
        $this->assertTrue($start->equalTo($job->getAttribute('heartbeat_at')));

        $context = new JobContext($queue, $job, 'lauf-a', microtime(true) + 30.0);

        $this->app->make(ExtractDocumentJob::class)->handle($job, $context);

        $job->refresh();

        $this->assertSame(ProcessingJobStatus::GELEAST, $job->getAttribute('status'));
        $this->assertTrue(
            $job->getAttribute('heartbeat_at')->greaterThan($start),
            'Der Heartbeat muss waehrend des Aufrufs erfolgt sein.',
        );
        $this->assertTrue(
            $job->getAttribute('leased_until')->greaterThan(Carbon::now()),
            'Das Lease darf nach 300 Sekunden Providerlaufzeit nicht abgelaufen sein, sonst uebernimmt ein zweiter Lauf.',
        );
        $this->assertSame(0, $queue->reclaimExpiredLeases(), 'Ein zweiter Slice darf den Job nicht zurueckholen.');
        $this->assertSame(
            DocumentProcessingStatus::ABGESCHLOSSEN,
            Document::query()->whereKey($document->getKey())->firstOrFail()->getAttribute('processing_status'),
        );
    }

    /**
     * Router mit Primaer- und Fallbackprovider, Fallback aktiv. Beide Provider
     * laufen ueber aufgezeichnete Transportadapter.
     */
    private function routerMitFallback(RecordingAiHttpClient $openai, RecordingAiHttpClient $anthropic): AiProviderRouter
    {
        $config = AiConfig::fromArray(AiTestFactory::configArray([
            'primary_provider' => 'openai',
            'fallback_provider' => 'anthropic',
            'fallback_enabled' => true,
            'dual_review_enabled' => false,
        ]));

        return new AiProviderRouter(
            $config,
            ProviderReleaseGate::fromConfig($config, 'testing'),
            new DualReviewComparator,
            new RedactingLogger,
            [
                'openai' => AiTestFactory::openAiProvider($openai),
                'anthropic' => AiTestFactory::anthropicProvider($anthropic),
            ],
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
