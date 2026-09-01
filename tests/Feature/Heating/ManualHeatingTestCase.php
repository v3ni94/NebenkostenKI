<?php

declare(strict_types=1);

namespace Tests\Feature\Heating;

use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Http\Controllers\Portal\Review\ManualHeatingController;
use App\Models\BillingRun;
use App\Models\Document;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Review\ReviewTestCase;

/**
 * Grundlage der Tests zur manuellen Heizkostenerfassung (Fall B).
 *
 * Die Routen dieses Arbeitspakets werden hier registriert, weil die
 * Eintragung in routes/portal.php zentral erfolgt. Die Definitionen sind
 * identisch mit der im Bericht gelisteten Routenliste, damit die Tests genau
 * das pruefen, was verdrahtet wird.
 *
 * Die Klasse endet nicht auf Test und wird deshalb nicht als Testklasse
 * eingesammelt.
 */
abstract class ManualHeatingTestCase extends ReviewTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::registriereHeizkostenRouten();

        app('router')->getRoutes()->refreshNameLookups();
    }

    /**
     * Routenliste der manuellen Heizkostenerfassung.
     */
    public static function registriereHeizkostenRouten(): void
    {
        if (Route::has('portal.pruefung.heizkosten.erfassung')) {
            return;
        }

        Route::prefix('app')
            ->name('portal.')
            ->middleware(['web', 'auth', 'organisation'])
            ->group(function (): void {
                Route::get('/abrechnungen/{billingRun}/heizkosten/erfassung', [ManualHeatingController::class, 'edit'])
                    ->name('pruefung.heizkosten.erfassung');
                Route::post('/abrechnungen/{billingRun}/heizkosten/erfassung', [ManualHeatingController::class, 'store'])
                    ->name('pruefung.heizkosten.speichern');
            });
    }

    /**
     * Externe Heizkostenabrechnung als konkurrierende Quelle.
     */
    protected function externeAbrechnung(BillingRun $lauf): Document
    {
        return $this->dokument($lauf, DocumentType::HEIZKOSTENABRECHNUNG, 'der Heizkostenabrechnung ista', [
            'processing_status' => DocumentProcessingStatus::ABGESCHLOSSEN,
        ]);
    }

    /**
     * WEG-Summenposition mit Heizkostenanteil als konkurrierende Quelle.
     */
    protected function wegPositionMitHeizkosten(BillingRun $lauf): Document
    {
        $dokument = $this->dokument($lauf, DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, 'der Hausgeldabrechnung', [
            'processing_status' => DocumentProcessingStatus::ABGESCHLOSSEN,
        ]);

        $this->felder($dokument, [
            'heizkosten_anteil_einheit_cent' => 120000,
            'einheitsbezeichnung' => 'Wohnung 1',
        ]);

        return $dokument;
    }
}
