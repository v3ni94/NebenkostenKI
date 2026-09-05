<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Application\Payment\Contracts\FinalDocumentViews;
use App\Application\Payment\Dto\FinalViewBundle;
use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\BillingMode;
use App\Enums\BillingRunStatus;
use App\Enums\CalculationSnapshotStatus;
use App\Enums\PaymentStatus;
use App\Enums\PrepaymentKind;
use App\Enums\UnitStatementStatus;
use App\Enums\ValueSource;
use App\Http\Controllers\Portal\Checkout\CheckoutController;
use App\Http\Controllers\Portal\Checkout\CheckoutReturnController;
use App\Http\Controllers\Portal\Download\CompletionController;
use App\Http\Controllers\Webhook\StripeWebhookController;
use App\Models\AllocationKey;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use App\Models\CostCategory;
use App\Models\CostItem;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Prepayment;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\UnitStatement;
use App\Models\User;
use App\Services\Payment\Contracts\CheckoutClient;
use App\Services\Payment\StripeConfiguration;
use App\Services\Payment\StripeWebhookVerifier;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Portal\PortalTestCase;

/**
 * Grundlage der Tests zu Preis, Zahlung, Webhook, Finalisierung und Downloads.
 *
 * VERBINDLICH: In keinem Test entsteht ein echter Aufruf beim Zahlungsanbieter.
 * Der Checkout laeuft ueber FakeCheckoutClient, die Webhook-Signaturen werden
 * mit dem Platzhaltergeheimnis aus phpunit.xml selbst erzeugt. Es werden keine
 * echten Schluessel verwendet, auch nicht als Beispielwerte.
 *
 * Die Routen dieses Arbeitspakets werden hier registriert. Die Eintragung in
 * routes/portal.php und routes/web.php erfolgt zentral; die Definitionen sind
 * identisch mit der im Uebergabebericht gelisteten Routenliste.
 *
 * Die Klasse endet nicht auf Test und wird deshalb nicht als Testklasse
 * eingesammelt.
 */
abstract class PaymentTestCase extends PortalTestCase
{
    /**
     * Platzhaltergeheimnis, identisch mit phpunit.xml. Kein echter Schluessel.
     */
    protected const string WEBHOOK_SECRET = 'whsec_test_placeholder';

    protected FakeCheckoutClient $checkoutClient;

    protected string $artefaktverzeichnis = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Eigene Artefaktablage je Test. Storage::fake wuerde das gemeinsame
        // Testverzeichnis verwenden; ein parallel laufender Testlauf koennte es
        // waehrend der Pruefung leeren. Die Ablage liegt deshalb in einem
        // eigenen Verzeichnis und wird nach dem Test entfernt.
        $this->artefaktverzeichnis = storage_path('framework/testing/zahlung-'.Str::random(12));

        config()->set('filesystems.disks.local.root', $this->artefaktverzeichnis);

        $this->checkoutClient = new FakeCheckoutClient;
        $this->app->instance(CheckoutClient::class, $this->checkoutClient);

        $this->app->instance(
            StripeWebhookVerifier::class,
            new StripeWebhookVerifier(StripeConfiguration::of(null, self::WEBHOOK_SECRET)),
        );

        self::registriereRouten();

        app('router')->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        if ($this->artefaktverzeichnis !== '' && File::isDirectory($this->artefaktverzeichnis)) {
            File::deleteDirectory($this->artefaktverzeichnis);
        }

        parent::tearDown();
    }

    /**
     * Routenliste des Arbeitspakets.
     */
    public static function registriereRouten(): void
    {
        if (Route::has('portal.checkout.show')) {
            return;
        }

        Route::prefix('app')
            ->name('portal.')
            ->middleware(['web', 'auth', 'organisation'])
            ->group(function (): void {
                Route::get('/abrechnungen/{billingRun}/zahlung', [CheckoutController::class, 'show'])
                    ->name('checkout.show');
                Route::post('/abrechnungen/{billingRun}/zahlung', [CheckoutController::class, 'store'])
                    ->middleware('can:email-verified')
                    ->name('checkout.store');
                Route::delete('/abrechnungen/{billingRun}/zahlung', [CheckoutController::class, 'destroy'])
                    ->name('checkout.destroy');

                Route::get('/abrechnungen/{billingRun}/zahlung/erfolg', [CheckoutReturnController::class, 'success'])
                    ->name('checkout.erfolg');
                Route::get('/abrechnungen/{billingRun}/zahlung/abbruch', [CheckoutReturnController::class, 'cancel'])
                    ->name('checkout.abbruch');

                Route::get('/abrechnungen/{billingRun}/abschluss', [CompletionController::class, 'show'])
                    ->middleware('can:email-verified')
                    ->name('abschluss.show');
            });

        // Ohne Session und ohne CSRF-Token. Die Ausnahme fuer webhooks/* ist in
        // bootstrap/app.php gesetzt. Die Ratenbegrenzung "webhooks" ist bei der
        // zentralen Eintragung zu ergaenzen, siehe Uebergabebericht.
        Route::post('/webhooks/stripe', StripeWebhookController::class)
            ->name('webhooks.stripe');
    }

    /**
     * Vollstaendiger Mandant mit Vorschaustand, Snapshot und der gewuenschten
     * Anzahl erzeugter Mieterabrechnungen.
     *
     * Der Lauf ist fachlich vollstaendig: eine bestaetigte Kostenposition mit
     * bestaetigtem Verteilerschluessel, erfasste Vorauszahlungen je
     * Mietverhaeltnis und eine gueltige Vorschau mit Wasserzeichen zum
     * aktiven Berechnungsstand. Ohne diese Angaben verweigert StartCheckout
     * den Checkout (keine gueltige Vorschau, offene Sperrgruende).
     *
     * @return array{
     *     user: User,
     *     organization: Organization,
     *     property: Property,
     *     unit: Unit,
     *     tenancy: Tenancy,
     *     billingRun: BillingRun,
     *     snapshot: CalculationSnapshot
     * }
     */
    protected function vorschaubereiterLauf(int $abrechnungen = 3, bool $bestaetigt = true): array
    {
        $mandant = $this->mandant();

        $mandant['user']->forceFill(['email_verified_at' => now()])->save();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'created_by_user_id' => $mandant['user']->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'billing_year' => 2025,
            'mode' => BillingMode::QUICK_CONDO,
            'status' => BillingRunStatus::PREVIEW_READY,
            'review_confirmed_at' => $bestaetigt ? now() : null,
            'responsibility_confirmed_at' => $bestaetigt ? now() : null,
        ]);

        /** @var CalculationSnapshot $snapshot */
        $snapshot = CalculationSnapshot::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'status' => CalculationSnapshotStatus::BERECHNET,
            'statement_count' => $abrechnungen,
            'locked_at' => null,
        ]);

        $lauf->forceFill(['active_calculation_snapshot_id' => $snapshot->getKey()])->save();

        $this->erzeugeKostenposition($mandant, $lauf);
        $this->erzeugeAbrechnungen($mandant, $lauf, $snapshot, $abrechnungen);

        return array_merge($mandant, [
            'billingRun' => $lauf->refresh(),
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * Erzeugte Mieterabrechnungen. Preisgrundlage ist die erzeugte Abrechnung,
     * nicht die Wohnung: alle Abrechnungen haengen hier bewusst an derselben
     * Einheit, wie es bei einem Mieterwechsel entsteht.
     *
     * @param  array{user: User, organization: Organization, property: Property, unit: Unit, tenancy: Tenancy}  $mandant
     */
    protected function erzeugeAbrechnungen(
        array $mandant,
        BillingRun $lauf,
        CalculationSnapshot $snapshot,
        int $anzahl,
    ): void {
        for ($nummer = 1; $nummer <= $anzahl; $nummer++) {
            // Je Abrechnung ein eigenes Mietverhaeltnis an derselben Einheit,
            // genau wie bei einem Mieterwechsel im laufenden Jahr.
            $mietverhaeltnis = $nummer === 1
                ? $mandant['tenancy']
                : Tenancy::factory()->create([
                    'organization_id' => $mandant['organization']->getKey(),
                    'property_id' => $mandant['property']->getKey(),
                    'unit_id' => $mandant['unit']->getKey(),
                    'starts_on' => '2025-01-01',
                    'ends_on' => null,
                ]);

            UnitStatement::factory()->create([
                'organization_id' => $mandant['organization']->getKey(),
                'billing_run_id' => $lauf->getKey(),
                'tenancy_id' => $mietverhaeltnis->getKey(),
                'unit_id' => $mandant['unit']->getKey(),
                'calculation_snapshot_id' => $snapshot->getKey(),
                'sequence_number' => $nummer,
                'status' => UnitStatementStatus::VORSCHAU,
            ]);

            // Erfasste Ist-Vorauszahlung, damit Schritt 7 abgeschlossen ist.
            Prepayment::query()->create([
                'organization_id' => $mandant['organization']->getKey(),
                'billing_run_id' => $lauf->getKey(),
                'tenancy_id' => $mietverhaeltnis->getKey(),
                'kind' => PrepaymentKind::BETRIEBSKOSTEN,
                'period_start' => '2025-01-01',
                'period_end' => '2025-12-31',
                'target_cent' => 288000,
                'actual_cent' => 288000,
                'source' => ValueSource::ZAHLUNGSUEBERSICHT,
                'assumed_equal_to_target' => false,
                'confirmed_at' => now(),
            ]);

            // Gueltige Vorschau mit Wasserzeichen zum aktiven Berechnungsstand.
            GeneratedDocument::factory()->create([
                'organization_id' => $mandant['organization']->getKey(),
                'billing_run_id' => $lauf->getKey(),
                'calculation_snapshot_id' => $snapshot->getKey(),
            ]);
        }
    }

    /**
     * Bestaetigte Kostenposition mit bestaetigtem Verteilerschluessel nach
     * Einheiten. Damit meldet weder die Kostenpruefung noch Schritt 8 einen
     * Sperrgrund.
     *
     * @param  array{organization: Organization}  $mandant
     */
    protected function erzeugeKostenposition(array $mandant, BillingRun $lauf): void
    {
        /** @var CostCategory $kategorie */
        $kategorie = CostCategory::factory()->create([
            'default_allocation_key_type' => AllocationKeyType::EINHEITEN,
        ]);

        CostItem::factory()->confirmed()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'cost_category_id' => $kategorie->getKey(),
            'amount_cent' => 120000,
        ]);

        AllocationKey::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'cost_category_id' => $kategorie->getKey(),
            'key_type' => AllocationKeyType::EINHEITEN,
            'source' => AllocationKeySource::MIETVERTRAG,
            'denominator' => null,
            'measurement_unit' => null,
            'label' => AllocationKeyType::EINHEITEN->label(),
            'confirmed_at' => now(),
        ]);
    }

    /**
     * Bestaetigte Zahlung zu einem Lauf, wie sie nach dem Webhook vorliegt.
     */
    protected function bezahlterLauf(BillingRun $lauf, int $bruttoCent, int $anzahl): Payment
    {
        /** @var Payment $zahlung */
        $zahlung = Payment::factory()->create([
            'organization_id' => $lauf->getAttribute('organization_id'),
            'billing_run_id' => $lauf->getKey(),
            'user_id' => $lauf->getAttribute('created_by_user_id'),
            'amount_cent' => $bruttoCent,
            'statement_count' => $anzahl,
            'status' => PaymentStatus::BEZAHLT,
            'paid_at' => now(),
        ]);

        $lauf->forceFill([
            'status' => BillingRunStatus::PAID,
            'paid_at' => now(),
            'price_total_gross_cent' => $bruttoCent,
            'statement_count' => $anzahl,
        ])->save();

        return $zahlung;
    }

    /**
     * Bindet die Aufbereitung des gesperrten Berechnungsstandes.
     *
     * Im Testlauf liefert sie feste Beispieldaten aus PdfFixtures. Die
     * produktive Umsetzung stellt das Berechnungs- und Vorschaupaket bereit.
     */
    protected function bindeFinalDocumentViews(FinalViewBundle $bundle): void
    {
        $this->app->instance(FinalDocumentViews::class, new FakeFinalDocumentViews($bundle));
    }

    /**
     * Macht die Artefaktablage kurzzeitig unbenutzbar. Produktiv liegt sie auf
     * SFTP; ein kurzzeitiger Ausfall im Moment des Webhooks ist dort ein
     * realer Fall (Abschnitt 23.4).
     */
    protected function stoereArtefaktspeicher(): void
    {
        config()->set('filesystems.disks.local.root', '/dev/null/artefakte-nicht-erreichbar');

        Storage::forgetDisk('local');
        $this->app->forgetInstance(ArtifactStorage::class);
    }

    protected function stelleArtefaktspeicherWiederHer(): void
    {
        config()->set('filesystems.disks.local.root', $this->artefaktverzeichnis);

        Storage::forgetDisk('local');
        $this->app->forgetInstance(ArtifactStorage::class);
    }

    /**
     * Bestaetigte Pflichtangaben des Betreibers fuer die Rechnungserzeugung.
     *
     * Die Werte sind frei erfundene Beispielangaben beziehungsweise die
     * allgemein dokumentierte Beispiel-IBAN. Es werden ausdruecklich keine
     * echten Steuer- oder Bankdaten verwendet.
     */
    protected function bestaetigteBetreiberstammdaten(): void
    {
        config()->set('smartabrechnen.operator.tax_id', '000/0000/0000');
        config()->set('smartabrechnen.operator.vat_id', 'DE000000000');
        config()->set('smartabrechnen.operator.iban', 'DE02120300000000202051');
        config()->set('smartabrechnen.operator.bic', 'BYLADEM1001');
        config()->set('smartabrechnen.operator.masterdata_confirmed', true);
    }

    /**
     * Korrekt signierte Testsignatur nach dem Schema des Anbieters. Es wird
     * kein echter Schluessel verwendet.
     */
    protected function signatur(string $payload, ?int $zeitpunkt = null, ?string $geheimnis = null): string
    {
        $zeitpunkt ??= time();
        $signatur = hash_hmac('sha256', $zeitpunkt.'.'.$payload, $geheimnis ?? self::WEBHOOK_SECRET);

        return sprintf('t=%d,v1=%s', $zeitpunkt, $signatur);
    }

    /**
     * Nutzlast einer Providerbenachrichtigung.
     *
     * @param  array<string, mixed>  $object
     */
    protected function nutzlast(string $typ, array $object, ?string $eventId = null): string
    {
        $payload = json_encode([
            'id' => $eventId ?? ('evt_test_'.bin2hex(random_bytes(8))),
            'type' => $typ,
            'data' => ['object' => $object],
        ], JSON_UNESCAPED_UNICODE);

        return $payload === false ? '{}' : $payload;
    }

    /**
     * Nutzlast einer erfolgreichen Zahlung zu einer konkreten Zahlung.
     *
     * @param  array<string, mixed>  $abweichungen
     */
    protected function erfolgsnutzlast(Payment $zahlung, array $abweichungen = [], ?string $eventId = null): string
    {
        $object = array_merge([
            'id' => (string) $zahlung->getAttribute('checkout_session_id'),
            'object' => 'checkout.session',
            'client_reference_id' => (string) $zahlung->getAttribute('billing_run_id'),
            'payment_intent' => 'pi_test_'.bin2hex(random_bytes(6)),
            'payment_status' => 'paid',
            'amount_total' => (int) $zahlung->getAttribute('amount_cent'),
            'currency' => (string) $zahlung->getAttribute('currency'),
            'metadata' => [
                'billing_run_id' => (string) $zahlung->getAttribute('billing_run_id'),
                'payment_id' => (string) $zahlung->getKey(),
            ],
        ], $abweichungen);

        return $this->nutzlast('checkout.session.completed', $object, $eventId);
    }

    /**
     * Sendet eine korrekt signierte Benachrichtigung an die Webhook-Route.
     *
     * @return TestResponse<Response>
     */
    protected function sendeWebhook(string $payload, ?string $signatur = null): TestResponse
    {
        return $this->call(
            'POST',
            route('webhooks.stripe'),
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signatur ?? $this->signatur($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );
    }
}
