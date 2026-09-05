<?php

declare(strict_types=1);

namespace Tests\Feature\Upload\Concerns;

use App\Application\Documents\Contracts\DocumentClassifier;
use App\Application\Documents\Contracts\DocumentExtractor;
use App\Application\Documents\Contracts\ProviderFileDeleter;
use App\Application\Documents\Dto\ClassificationOutcome;
use App\Application\Documents\Dto\ExtractionOutcome;
use App\Application\Documents\Dto\ProviderFileDeletionReport;
use App\Enums\AiProvider;
use App\Enums\DocumentType;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Portal\DownloadController;
use App\Http\Controllers\Portal\Upload\ChunkUploadController;
use App\Http\Controllers\Portal\Upload\UploadStatusController;
use App\Jobs\DocumentJobRegistry;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Property;
use App\Models\TemporaryUpload;
use App\Models\User;
use App\Services\Queue\DatabaseJobQueue;
use App\Services\Queue\QueueSliceReport;
use App\Services\Queue\QueueSliceRunner;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Unit\Storage\SampleFiles;

/**
 * Gemeinsame Grundlage der Uploadtests.
 *
 * Die Routen werden hier im Test registriert, weil die Verdrahtung in
 * routes/portal.php einem anderen Arbeitspaket gehoert. Pfade, Methoden und
 * Middleware entsprechen exakt der im Bericht genannten Routenliste.
 *
 * Es wird ausschliesslich mit Storage::fake gearbeitet, niemals mit einem
 * echten SFTP-Server.
 */
trait BuildsUploadWorld
{
    /**
     * @var array{user: User, organization: Organization, billingRun: BillingRun}|null
     */
    private ?array $welt = null;

    protected function setUpUploadWorld(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');

        // Die Artefaktablage nutzt im Test die lokale Disk, niemals SFTP.
        config(['filesystems.default' => 'local']);

        Storage::fake(TemporaryUploadStorage::DISK);
        Storage::fake('local');

        $this->registerUploadRoutes();
    }

    /**
     * Routenliste des Arbeitspakets. Wird vom Hauptpaket in routes/portal.php
     * verdrahtet; hier nur fuer den Test.
     */
    protected function registerUploadRoutes(): void
    {
        Route::middleware('web')->prefix('app')->name('portal.')->group(function (): void {
            Route::get('abrechnungen/{billingRun}/upload', [UploadStatusController::class, 'show'])
                ->name('uploads.index');
            Route::get('abrechnungen/{billingRun}/uploads/status', [UploadStatusController::class, 'index'])
                ->name('uploads.status');
            Route::post('abrechnungen/{billingRun}/uploads', [ChunkUploadController::class, 'store'])
                ->name('uploads.store');

            Route::get('uploads/{upload}', [UploadStatusController::class, 'upload'])
                ->name('uploads.show');
            Route::post('uploads/{upload}/abschnitte', [ChunkUploadController::class, 'storeChunk'])
                ->name('uploads.chunk');
            Route::post('uploads/{upload}/abschluss', [ChunkUploadController::class, 'complete'])
                ->name('uploads.complete');

            Route::get('downloads/{generatedDocument}', [DownloadController::class, 'stream'])
                ->name('downloads.stream');
            Route::get('downloads/{generatedDocument}/signiert', [DownloadController::class, 'signed'])
                ->middleware('signed')
                ->name('downloads.signed');
        });

        // Die Routen werden nach dem Booten registriert. Die Namensauflösung
        // fuer route() muss deshalb aktualisiert werden.
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    /**
     * @return array{user: User, organization: Organization, billingRun: BillingRun}
     */
    protected function welt(): array
    {
        return $this->welt ??= $this->makeWorld();
    }

    /**
     * @return array{user: User, organization: Organization, billingRun: BillingRun}
     */
    protected function makeWorld(): array
    {
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

        return ['user' => $user, 'organization' => $organization, 'billingRun' => $billingRun];
    }

    /**
     * Startet einen Upload ueber die HTTP-Route.
     *
     * @return TestResponse<Response>
     */
    protected function starteUpload(string $dateiname, int $groesse, ?string $kategorie = null): TestResponse
    {
        $welt = $this->welt();

        return $this->actingAs($welt['user'])->postJson(
            '/app/abrechnungen/'.$welt['billingRun']->getKey().'/uploads',
            array_filter([
                'dateiname' => $dateiname,
                'groesse' => $groesse,
                'kategorie' => $kategorie,
            ], static fn (mixed $wert): bool => $wert !== null)
        );
    }

    /**
     * @return TestResponse<Response>
     */
    protected function sendeAbschnitt(string $uploadId, int $index, string $inhalt): TestResponse
    {
        return $this->actingAs($this->welt()['user'])->post(
            '/app/uploads/'.$uploadId.'/abschnitte',
            [
                'index' => $index,
                'abschnitt' => UploadedFile::fake()->createWithContent('abschnitt.bin', $inhalt),
            ],
            ['Accept' => 'application/json']
        );
    }

    /**
     * @return TestResponse<Response>
     */
    protected function schliesseUploadAb(string $uploadId, string $erweiterung): TestResponse
    {
        return $this->actingAs($this->welt()['user'])->postJson(
            '/app/uploads/'.$uploadId.'/abschluss',
            ['erweiterung' => $erweiterung]
        );
    }

    /**
     * Vollstaendiger Upload einer Datei in einem Zug, ohne Verarbeitung.
     */
    protected function ladeDateiHoch(string $inhalt, string $erweiterung = 'pdf', ?string $kategorie = null): TemporaryUpload
    {
        $antwort = $this->starteUpload('unterlage.'.$erweiterung, strlen($inhalt), $kategorie);
        $antwort->assertCreated();

        $uploadId = (string) $antwort->json('upload_id');
        $abschnitte = (int) $antwort->json('abschnitte');
        $abschnittsgroesse = (int) $antwort->json('abschnittsgroesse');

        for ($index = 0; $index < $abschnitte; $index++) {
            $teil = substr($inhalt, $index * $abschnittsgroesse, $abschnittsgroesse);
            $this->sendeAbschnitt($uploadId, $index, $teil)->assertOk();
        }

        $this->schliesseUploadAb($uploadId, $erweiterung)->assertAccepted();

        return TemporaryUpload::query()->findOrFail($uploadId);
    }

    /**
     * Fuehrt einen Queue-Lauf aus, wie der Cronjob es tut.
     */
    protected function verarbeiteQueue(int $maxJobs = 25): QueueSliceReport
    {
        $queue = $this->app->make(DatabaseJobQueue::class);
        $registry = $this->app->make(DocumentJobRegistry::class)->make();

        $runner = new QueueSliceRunner($queue, $registry, $this->app);

        return $runner->run('test-lauf', 30.0, $maxJobs);
    }

    /**
     * Bindet eine erfolgreiche KI-Schicht. Die echte Umsetzung liegt in einem
     * anderen Arbeitspaket; hier zaehlt nur der Lebenszyklus.
     */
    protected function bindeErfolgreicheKiSchicht(DocumentType $typ = DocumentType::GRUNDSTEUERBESCHEID): void
    {
        $this->app->bind(DocumentClassifier::class, fn (): DocumentClassifier => new class($typ) implements DocumentClassifier
        {
            public function __construct(private readonly DocumentType $typ) {}

            public function classify(Document $document, TemporaryUpload $upload): ClassificationOutcome
            {
                return ClassificationOutcome::classified($this->typ, 0.95);
            }
        });

        $this->app->bind(DocumentExtractor::class, fn (): DocumentExtractor => new class implements DocumentExtractor
        {
            public function extract(Document $document, TemporaryUpload $upload): ExtractionOutcome
            {
                // Ohne eigene Seitenzahl: die technisch ermittelte bleibt bestehen.
                return ExtractionOutcome::completed(12);
            }
        });
    }

    /**
     * Bindet eine KI-Schicht, deren Extraktion endgueltig scheitert.
     */
    protected function bindeScheiterndeKiSchicht(): void
    {
        $this->app->bind(DocumentClassifier::class, fn (): DocumentClassifier => new class implements DocumentClassifier
        {
            public function classify(Document $document, TemporaryUpload $upload): ClassificationOutcome
            {
                return ClassificationOutcome::classified(DocumentType::SONSTIGES, 0.4);
            }
        });

        $this->app->bind(DocumentExtractor::class, fn (): DocumentExtractor => new class implements DocumentExtractor
        {
            public function extract(Document $document, TemporaryUpload $upload): ExtractionOutcome
            {
                return ExtractionOutcome::failedPermanently('SCHEMA_UNGUELTIG');
            }
        });
    }

    /**
     * Bindet einen Provider-Loescher, der jede Loeschung protokolliert.
     */
    protected function bindeProviderLoescher(bool $erfolgreich = true): void
    {
        $this->app->bind(
            ProviderFileDeleter::class,
            fn (): ProviderFileDeleter => new class($erfolgreich) implements ProviderFileDeleter
            {
                public function __construct(private readonly bool $erfolgreich) {}

                public function deleteProviderFile(AiProvider $provider, string $providerFileId): ProviderFileDeletionReport
                {
                    ProviderLoeschProtokoll::$aufrufe[] = $providerFileId;

                    return $this->erfolgreich
                        ? ProviderFileDeletionReport::deleted()
                        : ProviderFileDeletionReport::failed('PROVIDER_NICHT_ERREICHBAR');
                }
            }
        );
    }

    protected function beispielPdf(int $seiten = 2): string
    {
        return SampleFiles::pdf($seiten);
    }
}
