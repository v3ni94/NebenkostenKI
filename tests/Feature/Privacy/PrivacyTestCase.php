<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Portal\PrivacyController;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\ExtractedField;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Gemeinsame Grundlage der Datenschutztests.
 *
 * Die Web-Routen des Datenschutzbereichs werden hier registriert. Grund: Die
 * Registrierung in routes/portal.php erfolgt gesammelt, damit die
 * Routendatei nicht von mehreren Arbeitspaketen gleichzeitig verändert wird.
 * Die Tests verwenden dieselben Namen, Pfade und Middleware, die im Bericht
 * zur Aufnahme vorgeschlagen sind.
 *
 * Die Klasse endet nicht auf Test und wird deshalb nicht als Testklasse
 * eingesammelt.
 */
abstract class PrivacyTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Erkennungsmerkmal einer Originaldatei. Es darf in keinem Export stehen.
     */
    protected const ORIGINAL_MARKER = 'GEHEIMER-ORIGINALBELEG-4711';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('temporary_uploads');

        $this->registriereRouten();
    }

    /**
     * Registriert die Routen des Datenschutzbereichs.
     */
    protected function registriereRouten(): void
    {
        // Die Routen stehen inzwischen zentral in routes/portal.php. Diese
        // Registrierung bleibt nur als Rueckfallebene bestehen.
        if (Route::has('portal.datenschutz.show')) {
            return;
        }

        Route::prefix('app')
            ->name('portal.')
            ->middleware(['web', 'auth', 'organisation'])
            ->group(function (): void {
                Route::get('/datenschutz', [PrivacyController::class, 'show'])
                    ->name('datenschutz.show');
                Route::post('/datenschutz/datenexport', [PrivacyController::class, 'export'])
                    ->name('datenschutz.export');
                Route::get('/datenschutz/datenexport/{export}', [PrivacyController::class, 'download'])
                    ->middleware('throttle:downloads')
                    ->name('datenschutz.export.download');
                Route::post('/datenschutz/datenexport/{export}/link', [PrivacyController::class, 'link'])
                    ->name('datenschutz.export.link');
                Route::post('/datenschutz/loeschung', [PrivacyController::class, 'requestDeletion'])
                    ->name('datenschutz.loeschung');
                Route::delete('/datenschutz/loeschung', [PrivacyController::class, 'withdrawDeletion'])
                    ->name('datenschutz.loeschung.zuruecknehmen');

                Route::get('/datenschutz/datenexport/{export}/signiert', [PrivacyController::class, 'signedDownload'])
                    ->middleware(['signed', 'throttle:downloads'])
                    ->name('datenschutz.export.signiert');
            });

        // Die Routensammlung ist beim Start bereits ausgewertet. Ohne diese
        // Auffrischung findet der URL-Generator die neuen Namen nicht.
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    /**
     * Vollstaendiger Mandant mit Stammdaten, Abrechnungslauf, Auslesedaten,
     * erzeugten PDFs und einer HVM-Rechnung.
     *
     * @return array{
     *     user: User,
     *     organization: Organization,
     *     property: Property,
     *     unit: Unit,
     *     tenancy: Tenancy,
     *     billingRun: BillingRun,
     *     document: Document,
     *     field: ExtractedField,
     *     statementPdf: GeneratedDocument,
     *     invoice: Invoice,
     *     invoicePdf: GeneratedDocument
     * }
     */
    protected function mandant(string $kennzeichen = 'A'): array
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create([
            'name' => 'Vermieter '.$kennzeichen,
            'email' => mb_strtolower($kennzeichen).'.vermieter@example.test',
        ]);

        /** @var Organization $organisation */
        $organisation = Organization::factory()->create([
            'name' => 'Mandant '.$kennzeichen,
            'billing_name' => 'Rechnungsempfänger '.$kennzeichen,
            'contact_email' => mb_strtolower($kennzeichen).'.kontakt@example.test',
        ]);

        OrganizationUser::query()->create([
            'organization_id' => $organisation->getKey(),
            'user_id' => $nutzer->getKey(),
            'role' => OrganizationRole::OWNER,
            'joined_at' => now(),
        ]);

        /** @var Property $objekt */
        $objekt = Property::factory()->create([
            'organization_id' => $organisation->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
            'label' => 'Objekt '.$kennzeichen,
        ]);

        /** @var Unit $einheit */
        $einheit = Unit::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
        ]);

        /** @var Tenancy $mietverhaeltnis */
        $mietverhaeltnis = Tenancy::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'unit_id' => $einheit->getKey(),
            'starts_on' => '2025-01-01',
            'ends_on' => null,
        ]);

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
        ]);

        /** @var Document $dokument */
        $dokument = Document::factory()->create([
            'organization_id' => $organisation->getKey(),
            'billing_run_id' => $lauf->getKey(),
        ]);

        /** @var ExtractedField $feld */
        $feld = ExtractedField::factory()->create([
            'organization_id' => $organisation->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'document_id' => $dokument->getKey(),
            'schema_key' => 'betrag_cent_'.mb_strtolower($kennzeichen),
        ]);

        $abrechnungsPdf = $this->artefakt(
            $organisation,
            $lauf,
            GeneratedDocumentKind::MIETERABRECHNUNG,
            GeneratedDocumentVariant::FINAL,
            'abrechnungen/final/abrechnung-'.mb_strtolower($kennzeichen).'.pdf',
            '%PDF-1.4 Mieterabrechnung '.$kennzeichen,
        );

        /** @var Invoice $rechnung */
        $rechnung = Invoice::factory()->create([
            'organization_id' => $organisation->getKey(),
            'user_id' => $nutzer->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'customer_name' => 'Rechnungsempfänger '.$kennzeichen,
        ]);

        $rechnungsPdf = $this->artefakt(
            $organisation,
            $lauf,
            GeneratedDocumentKind::HVM_RECHNUNG,
            GeneratedDocumentVariant::FINAL,
            'rechnungen/rechnung-'.mb_strtolower($kennzeichen).'.pdf',
            '%PDF-1.4 HVM-Rechnung '.$kennzeichen,
            $rechnung,
        );

        return [
            'user' => $nutzer,
            'organization' => $organisation,
            'property' => $objekt,
            'unit' => $einheit,
            'tenancy' => $mietverhaeltnis,
            'billingRun' => $lauf,
            'document' => $dokument,
            'field' => $feld,
            'statementPdf' => $abrechnungsPdf,
            'invoice' => $rechnung,
            'invoicePdf' => $rechnungsPdf,
        ];
    }

    protected function artefakt(
        Organization $organisation,
        BillingRun $lauf,
        GeneratedDocumentKind $art,
        GeneratedDocumentVariant $variante,
        string $pfad,
        string $inhalt,
        ?Invoice $rechnung = null,
    ): GeneratedDocument {
        Storage::disk('local')->put($pfad, $inhalt);

        /** @var GeneratedDocument $dokument */
        $dokument = GeneratedDocument::factory()->create([
            'organization_id' => $organisation->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'invoice_id' => $rechnung?->getKey(),
            'kind' => $art,
            'variant' => $variante,
            'storage_disk' => 'local',
            'storage_path' => $pfad,
            'byte_size' => strlen($inhalt),
            'sha256' => hash('sha256', $inhalt),
        ]);

        return $dokument;
    }

    /**
     * Legt eine Originaldatei im Kurzzeitbereich ab. Sie darf in keinem Export
     * und in keinem Artefakt auftauchen.
     */
    protected function originaldateiAblegen(): string
    {
        $pfad = 'quarantaene/original-beleg.pdf';

        Storage::disk('temporary_uploads')->put($pfad, '%PDF-1.4 '.self::ORIGINAL_MARKER);

        return $pfad;
    }

    /**
     * Liest die Einträge eines ZIP aus der Ablage.
     *
     * @return array<string, string>
     */
    protected function zipEintraege(string $pfad): array
    {
        $inhalt = Storage::disk('local')->get($pfad);

        self::assertIsString($inhalt);

        $temp = tempnam(sys_get_temp_dir(), 'sa-test-zip-');
        self::assertIsString($temp);
        file_put_contents($temp, $inhalt);

        $zip = new \ZipArchive;
        self::assertTrue($zip->open($temp) === true);

        $eintraege = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (! is_string($name)) {
                continue;
            }

            $daten = $zip->getFromIndex($i);
            $eintraege[$name] = is_string($daten) ? $daten : '';
        }

        $zip->close();
        @unlink($temp);

        return $eintraege;
    }
}
