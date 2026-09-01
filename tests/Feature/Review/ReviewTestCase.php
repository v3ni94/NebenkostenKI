<?php

declare(strict_types=1);

namespace Tests\Feature\Review;

use App\Enums\BillingMode;
use App\Enums\DocumentType;
use App\Http\Controllers\Portal\Review\AnalysisStatusController;
use App\Http\Controllers\Portal\Review\BillingModeController;
use App\Http\Controllers\Portal\Review\CostReviewController;
use App\Http\Controllers\Portal\Review\HeatingMatrixController;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\ExtractedField;
use App\Models\Organization;
use App\Models\Property;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Portal\PortalTestCase;

/**
 * Grundlage der Tests zur Reconciliation und zur Pruefoberflaeche.
 *
 * Die Routen dieses Arbeitspakets werden bewusst hier registriert. Die
 * Eintragung in routes/portal.php erfolgt zentral; die Definitionen sind
 * identisch mit der im Bericht gelisteten Routenliste, damit die Tests genau
 * das pruefen, was verdrahtet wird.
 *
 * Die Klasse endet nicht auf Test und wird deshalb nicht als Testklasse
 * eingesammelt.
 */
abstract class ReviewTestCase extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CostCategorySeeder::class);
        self::registriereRouten();

        // Die Namen der zur Laufzeit ergaenzten Routen muessen in die
        // Namensliste aufgenommen werden, damit route() sie findet.
        app('router')->getRoutes()->refreshNameLookups();
    }

    /**
     * Routenliste des Arbeitspakets.
     */
    public static function registriereRouten(): void
    {
        Route::prefix('app')
            ->name('portal.')
            ->middleware(['web', 'auth', 'organisation'])
            ->group(function (): void {
                Route::get('/abrechnungen/{billingRun}/analyse', [AnalysisStatusController::class, 'show'])
                    ->name('pruefung.analyse');
                Route::get('/abrechnungen/{billingRun}/analyse/status', [AnalysisStatusController::class, 'status'])
                    ->name('pruefung.analyse.status');
                Route::post('/abrechnungen/{billingRun}/analyse/zuordnen', [AnalysisStatusController::class, 'reconcile'])
                    ->name('pruefung.zuordnen');

                Route::get('/abrechnungen/{billingRun}/kostenpruefung', [CostReviewController::class, 'index'])
                    ->name('pruefung.kosten');
                Route::post('/abrechnungen/{billingRun}/kostenpruefung/positionen', [CostReviewController::class, 'store'])
                    ->name('pruefung.kosten.store');
                Route::post('/abrechnungen/{billingRun}/kostenpruefung/sammelbestaetigung', [CostReviewController::class, 'bulkConfirm'])
                    ->name('pruefung.sammelbestaetigung');
                Route::post('/abrechnungen/{billingRun}/kostenpruefung/weiter', [CostReviewController::class, 'proceed'])
                    ->name('pruefung.weiter');
                Route::post('/abrechnungen/{billingRun}/kostenpruefung/{costItem}/bestaetigen', [CostReviewController::class, 'confirm'])
                    ->name('pruefung.kosten.bestaetigen');
                Route::post('/abrechnungen/{billingRun}/kostenpruefung/{costItem}/verwerfen', [CostReviewController::class, 'discard'])
                    ->name('pruefung.kosten.verwerfen');
                Route::post('/abrechnungen/{billingRun}/kostenpruefung/{costItem}/ausschliessen', [CostReviewController::class, 'exclude'])
                    ->name('pruefung.kosten.ausschliessen');
                Route::post('/abrechnungen/{billingRun}/kostenpruefung/{costItem}/einheit', [CostReviewController::class, 'assign'])
                    ->name('pruefung.kosten.einheit');
                Route::put('/abrechnungen/{billingRun}/kostenpruefung/{costItem}', [CostReviewController::class, 'update'])
                    ->name('pruefung.kosten.update');

                Route::get('/abrechnungen/{billingRun}/heizkosten', [HeatingMatrixController::class, 'show'])
                    ->name('pruefung.heizkosten');

                Route::get('/abrechnungen/{billingRun}/abrechnungsweg', [BillingModeController::class, 'edit'])
                    ->name('pruefung.weg.edit');
                Route::put('/abrechnungen/{billingRun}/abrechnungsweg', [BillingModeController::class, 'update'])
                    ->name('pruefung.weg.update');
            });
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    protected function lauf(Organization $organisation, Property $objekt, array $attribute = []): BillingRun
    {
        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create(array_merge([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'billing_year' => 2025,
            'mode' => BillingMode::QUICK_CONDO,
        ], $attribute));

        return $lauf;
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    protected function dokument(BillingRun $lauf, DocumentType $art, string $bezeichnung, array $attribute = []): Document
    {
        /** @var Document $dokument */
        $dokument = Document::factory()->create(array_merge([
            'organization_id' => $lauf->getAttribute('organization_id'),
            'billing_run_id' => $lauf->getKey(),
            'document_type' => $art,
            'source_label' => $bezeichnung,
        ], $attribute));

        return $dokument;
    }

    /**
     * Legt ausgelesene Inhaltsdaten an. Die Werte liegen in derselben Huelle
     * wie beim Persister der KI-Schicht.
     *
     * @param  array<string, string|int|float|bool|null>  $felder
     */
    protected function felder(Document $dokument, array $felder, string $konfidenz = '0.9500', int $seite = 1): void
    {
        foreach ($felder as $pfad => $wert) {
            ExtractedField::factory()->create([
                'organization_id' => $dokument->getAttribute('organization_id'),
                'billing_run_id' => $dokument->getAttribute('billing_run_id'),
                'document_id' => $dokument->getKey(),
                'schema_key' => $pfad,
                'value' => ['wert' => $wert],
                'page_number' => $seite,
                'source_excerpt' => 'Fundstelle zu '.$pfad,
                'confidence' => $konfidenz,
            ]);
        }
    }
}
