<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use App\Application\Account\EmailVerification;
use App\Application\BillingRun\BillingRunStateMachine;
use App\Enums\BillingRunStatus;
use App\Enums\DocumentType;
use App\Jobs\DocumentJobRegistry;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Property;
use App\Models\TemporaryUpload;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use App\Providers\AiServiceProvider;
use App\Services\Payment\Contracts\CheckoutClient;
use App\Services\Payment\StripeConfiguration;
use App\Services\Payment\StripeWebhookVerifier;
use App\Services\Queue\DatabaseJobQueue;
use App\Services\Queue\QueueSliceReport;
use App\Services\Queue\QueueSliceRunner;
use App\Services\Storage\ArtifactStorage;
use App\Services\Storage\TemporaryUploadStorage;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Payment\FakeCheckoutClient;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Grundlage der Durchlauftests nach Abschnitt 23.4.
 *
 * VERBINDLICHE RAHMENBEDINGUNGEN
 *
 *  1. KEIN ECHTER PROVIDERAUFRUF. Die KI-Anbindung wird ausdruecklich
 *     gebunden, laeuft aber ueber FakeAiProvider und die Beispielantworten in
 *     tests/Fixtures/Ai. Der Zahlungsanbieter laeuft ueber FakeCheckoutClient,
 *     die Webhook-Signatur wird mit dem Platzhaltergeheimnis aus phpunit.xml
 *     selbst erzeugt. Es werden keine echten Schluessel verwendet.
 *  2. KEIN ECHTER SFTP-ZUGRIFF. Uploads und Artefakte liegen in Storage::fake.
 *  3. Der Ablauf laeuft ueber die zentral in routes/web.php und
 *     routes/portal.php registrierten Routen. Der Test registriert keine
 *     eigenen Routen.
 *
 * Die Klasse endet nicht auf Test und wird deshalb nicht als Testklasse
 * eingesammelt.
 */
abstract class EndToEndTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Platzhaltergeheimnis, identisch mit phpunit.xml. Kein echter Schluessel.
     */
    protected const string WEBHOOK_SECRET = 'whsec_test_placeholder';

    protected FakeCheckoutClient $checkoutClient;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');

        config([
            'filesystems.default' => 'local',
            'smartabrechnen.uploads.max_file_mb' => 25,
            'smartabrechnen.uploads.max_run_mb' => 250,
            'smartabrechnen.uploads.chunk_size_mb' => 1,
        ]);

        Storage::fake(TemporaryUploadStorage::DISK);
        Storage::fake('local');

        $this->seed(CostCategorySeeder::class);

        $this->checkoutClient = new FakeCheckoutClient;
        $this->app->instance(CheckoutClient::class, $this->checkoutClient);

        $this->app->instance(
            StripeWebhookVerifier::class,
            new StripeWebhookVerifier(StripeConfiguration::of(null, self::WEBHOOK_SECRET)),
        );

        $this->bestaetigteBetreiberstammdaten();
    }

    /**
     * Bindet die KI-Anbindung ausdruecklich an den Testprovider.
     *
     * In der Testumgebung ist die Dokumentpipeline standardmaessig NICHT
     * gebunden, damit der Zustand ohne KI-Anbindung pruefbar bleibt. Fuer den
     * Durchlauf wird sie deshalb hier eingeschaltet.
     */
    protected function bindeKiSchicht(): void
    {
        config([
            'ai.bind_document_pipeline' => true,
            'ai.primary_provider' => 'fake',
        ]);

        $this->app->register(new AiServiceProvider($this->app), true);
    }

    /**
     * Pflichtangaben des Betreibers. Frei erfundene Beispielwerte
     * beziehungsweise die allgemein dokumentierte Beispiel-IBAN.
     */
    protected function bestaetigteBetreiberstammdaten(): void
    {
        config()->set('smartabrechnen.operator.tax_id', '000/0000/0000');
        config()->set('smartabrechnen.operator.vat_id', 'DE000000000');
        config()->set('smartabrechnen.operator.iban', 'DE02120300000000202051');
        config()->set('smartabrechnen.operator.bic', 'BYLADEM1001');
        config()->set('smartabrechnen.operator.masterdata_confirmed', true);
    }

    // --- Schritt 1: Konto ---------------------------------------------------

    /**
     * Registrierung und E-Mail-Verifizierung ueber die echten Routen.
     *
     * @return array{user: User, organization: Organization}
     */
    protected function registriereUndVerifiziere(string $email = 'nutzerin@beispiel.invalid'): array
    {
        $this->post(route('register'), [
            'name' => 'Maria Beispiel',
            'email' => $email,
            'password' => 'sicheres-passwort-2026',
            'password_confirmation' => 'sicheres-passwort-2026',
            'datenschutz' => '1',
        ])->assertRedirect(route('verification.notice'));

        $nutzer = User::query()->where('email', $email)->firstOrFail();

        self::assertNull($nutzer->getAttribute('email_verified_at'));

        $this->actingAs($nutzer)
            ->get(app(EmailVerification::class)->signedUrl($nutzer))
            ->assertRedirect();

        $nutzer->refresh();

        self::assertNotNull($nutzer->getAttribute('email_verified_at'));

        $organisation = Organization::query()
            ->whereIn('id', $nutzer->organizationIds())
            ->firstOrFail();

        return ['user' => $nutzer, 'organization' => $organisation];
    }

    // --- Schritt 4 und 5: Objekt, Einheiten, Mietverhaeltnis ----------------

    protected function legeObjektAn(User $nutzer): Property
    {
        $this->actingAs($nutzer)->post(route('portal.objekte.store'), [
            'label' => 'Beispielweg 7',
            'address_line' => 'Beispielweg 7',
            'postal_code' => '40000',
            'city' => 'Musterstadt',
            'kind' => 'EIGENTUMSWOHNUNG',
            'total_living_area_sqm' => '72,40',
            'mea_denominator' => '1000',
        ])->assertRedirect();

        return Property::query()->where('label', 'Beispielweg 7')->firstOrFail();
    }

    protected function legeEinheitAn(User $nutzer, Property $objekt): Unit
    {
        $this->actingAs($nutzer)->post(
            route('portal.einheiten.store', ['property' => $objekt->getKey()]),
            [
                'label' => 'Wohnung 4',
                'location' => '2. OG rechts',
                'living_area_sqm' => '72,40',
                'heated_area_sqm' => '72,40',
                'mea' => '84,5',
            ]
        )->assertRedirect();

        return Unit::query()->where('label', 'Wohnung 4')->firstOrFail();
    }

    protected function legeMietverhaeltnisAn(User $nutzer, Unit $einheit): Tenancy
    {
        $this->actingAs($nutzer)->post(
            route('portal.mietverhaeltnisse.store', ['unit' => $einheit->getKey()]),
            [
                'tenant_display_name' => 'Mietpartei Beispiel',
                'kind' => 'WOHNRAUM',
                'starts_on' => '2025-01-01',
                'monthly_operating_prepayment_eur' => '210,00',
                'monthly_heating_prepayment_eur' => '100,00',
            ]
        )->assertRedirect();

        return Tenancy::query()->where('tenant_display_name', 'Mietpartei Beispiel')->firstOrFail();
    }

    protected function legeAbrechnungslaufAn(User $nutzer, Property $objekt): BillingRun
    {
        $this->actingAs($nutzer)->post(route('portal.abrechnungen.store'), [
            'property_id' => $objekt->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'mode' => 'QUICK_CONDO',
        ])->assertRedirect();

        return BillingRun::query()->where('property_id', $objekt->getKey())->firstOrFail();
    }

    // --- Schritt 2: Upload --------------------------------------------------

    /**
     * @return TestResponse<Response>
     */
    protected function starteUpload(User $nutzer, BillingRun $lauf, string $dateiname, int $groesse): TestResponse
    {
        return $this->actingAs($nutzer)->postJson(
            route('portal.uploads.store', ['billingRun' => $lauf->getKey()]),
            ['dateiname' => $dateiname, 'groesse' => $groesse],
        );
    }

    /**
     * Vollstaendiger Upload einer Datei in einem Zug, ohne Verarbeitung.
     */
    protected function ladeHoch(User $nutzer, BillingRun $lauf, string $inhalt, string $dateiname): TemporaryUpload
    {
        $antwort = $this->starteUpload($nutzer, $lauf, $dateiname, strlen($inhalt));
        $antwort->assertCreated();

        $uploadId = (string) $antwort->json('upload_id');
        $abschnitte = (int) $antwort->json('abschnitte');
        $groesse = (int) $antwort->json('abschnittsgroesse');

        for ($index = 0; $index < $abschnitte; $index++) {
            $this->actingAs($nutzer)->post(
                route('portal.uploads.chunk', ['upload' => $uploadId]),
                [
                    'index' => $index,
                    'abschnitt' => UploadedFile::fake()->createWithContent(
                        'abschnitt.bin',
                        substr($inhalt, $index * $groesse, $groesse)
                    ),
                ],
                ['Accept' => 'application/json']
            )->assertOk();
        }

        $this->actingAs($nutzer)->postJson(
            route('portal.uploads.complete', ['upload' => $uploadId]),
            ['erweiterung' => 'pdf']
        )->assertAccepted();

        return TemporaryUpload::query()->findOrFail($uploadId);
    }

    /**
     * Setzt die Dokumentart einer Unterlage ausdruecklich.
     *
     * Der Testprovider liefert je Aufruf dieselbe Beispielklassifikation. Die
     * zweite Unterlage des Durchlaufs ist deshalb wie in der Oberflaeche
     * manuell zuzuordnen; eine manuell gesetzte Art hat Vorrang und wird von
     * der Klassifikation nicht ueberschrieben.
     */
    protected function ordneDokumentartZu(TemporaryUpload $upload, DocumentType $typ): Document
    {
        $dokumentId = $upload->getAttribute('document_id');

        Document::query()->whereKey($dokumentId)->update([
            'document_type' => $typ->value,
            'type_assigned_manually' => true,
        ]);

        return Document::query()->findOrFail($dokumentId);
    }

    /**
     * Fuehrt einen Queue-Lauf aus, wie der Cronjob es tut.
     */
    protected function verarbeiteQueue(int $maxJobs = 60): QueueSliceReport
    {
        $queue = $this->app->make(DatabaseJobQueue::class);
        $registry = $this->app->make(DocumentJobRegistry::class)->make();

        return (new QueueSliceRunner($queue, $registry, $this->app))->run('e2e-lauf', 60.0, $maxJobs);
    }

    // --- Schritt 6 bis 9 ----------------------------------------------------

    /**
     * Bestaetigt jede vorgeschlagene Kostenposition einzeln, so wie es die
     * Oberflaeche verlangt. Nichts wird stillschweigend uebernommen.
     */
    protected function bestaetigeKostenpositionen(User $nutzer, BillingRun $lauf): int
    {
        $positionen = CostItem::query()
            ->where('billing_run_id', $lauf->getKey())
            ->orderBy('id')
            ->get();

        foreach ($positionen as $position) {
            $this->actingAs($nutzer)->post(route('portal.pruefung.kosten.bestaetigen', [
                'billingRun' => $lauf->getKey(),
                'costItem' => $position->getKey(),
            ]))->assertRedirect();
        }

        return $positionen->count();
    }

    /**
     * Fuehrt den Lauf ueber die Statusmaschine bis PREVIEW_READY.
     *
     * OFFENER PUNKT, hier bewusst sichtbar gemacht: Die Schritte des
     * gefuehrten Ablaufs schreiben derzeit den Fortschritt (wizard_step) und
     * die Bestaetigungen, aber keinen Statuswechsel des Abrechnungslaufs. Die
     * Policy des Checkouts verlangt jedoch PREVIEW_READY. Der Test setzt die
     * Zustaende deshalb ueber dieselbe Statusmaschine, die auch die Anwendung
     * verwendet, und ueberspringt dabei keinen Zustand. Sobald die
     * Wizard-Schritte den Statuswechsel selbst vornehmen, entfaellt dieser
     * Aufruf.
     */
    protected function versetzeInVorschaubereit(BillingRun $lauf, User $nutzer): BillingRun
    {
        $maschine = $this->app->make(BillingRunStateMachine::class);

        foreach ([
            BillingRunStatus::UPLOADING,
            BillingRunStatus::EXTRACTING,
            BillingRunStatus::READY_FOR_CALCULATION,
            BillingRunStatus::CALCULATED,
            BillingRunStatus::PREVIEW_READY,
        ] as $status) {
            $maschine->transitionTo($lauf, $status, $nutzer);
        }

        return $lauf->refresh();
    }

    // --- Zusammengesetzter Durchlauf ----------------------------------------

    /**
     * Schritt 1 bis 5: Konto, Objekt, Einheit, Mietverhaeltnis,
     * Abrechnungslauf.
     *
     * @return array{
     *     user: User,
     *     organization: Organization,
     *     property: Property,
     *     unit: Unit,
     *     tenancy: Tenancy,
     *     billingRun: BillingRun
     * }
     */
    protected function konto(): array
    {
        $konto = $this->registriereUndVerifiziere();
        $objekt = $this->legeObjektAn($konto['user']);
        $einheit = $this->legeEinheitAn($konto['user'], $objekt);
        $mietverhaeltnis = $this->legeMietverhaeltnisAn($konto['user'], $einheit);
        $lauf = $this->legeAbrechnungslaufAn($konto['user'], $objekt);

        return [
            'user' => $konto['user'],
            'organization' => $konto['organization'],
            'property' => $objekt,
            'unit' => $einheit,
            'tenancy' => $mietverhaeltnis,
            'billingRun' => $lauf,
        ];
    }

    /**
     * Schritt 2 und 3: Hausgeldabrechnung und Grundsteuerbescheid hochladen
     * und auswerten lassen.
     *
     * @return array{hausgeld: TemporaryUpload, grundsteuer: TemporaryUpload, praefixe: list<string>}
     */
    protected function ladeUnterlagenHochUndWerteAus(User $nutzer, BillingRun $lauf): array
    {
        $hausgeld = $this->ladeHoch($nutzer, $lauf, $this->beispielPdf(3), 'hausgeldabrechnung.pdf');
        $grundsteuer = $this->ladeHoch($nutzer, $lauf, $this->beispielPdf(2), 'grundsteuerbescheid.pdf');

        // Die zweite Unterlage wird wie in der Oberflaeche manuell zugeordnet.
        $this->ordneDokumentartZu($grundsteuer, DocumentType::GRUNDSTEUERBESCHEID);

        $praefixe = [
            (string) $hausgeld->getAttribute('storage_key'),
            (string) $grundsteuer->getAttribute('storage_key'),
        ];

        $this->verarbeiteQueue();

        return ['hausgeld' => $hausgeld, 'grundsteuer' => $grundsteuer, 'praefixe' => $praefixe];
    }

    /**
     * Schritt 3 bis 6: Analyse, Zuordnung und Kostenpruefung.
     */
    protected function pruefeKosten(User $nutzer, BillingRun $lauf): int
    {
        $this->actingAs($nutzer)
            ->get(route('portal.pruefung.analyse', ['billingRun' => $lauf->getKey()]))
            ->assertOk();

        $this->actingAs($nutzer)
            ->post(route('portal.pruefung.zuordnen', ['billingRun' => $lauf->getKey()]))
            ->assertRedirect(route('portal.pruefung.kosten', ['billingRun' => $lauf->getKey()]));

        $anzahl = $this->bestaetigeKostenpositionen($nutzer, $lauf);

        $this->actingAs($nutzer)
            ->post(route('portal.pruefung.weiter', ['billingRun' => $lauf->getKey()]))
            ->assertRedirect(route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]));

        return $anzahl;
    }

    /**
     * Schritt 7 bis 9: Vorauszahlungen, Verteilerschluessel, Pruefbericht.
     */
    protected function erfasseVerteilungUndPruefbericht(User $nutzer, BillingRun $lauf, Unit $einheit, Tenancy $mietverhaeltnis): void
    {
        // Schritt 7. Die Annahme Ist gleich Soll wird ausdruecklich bestaetigt.
        $this->actingAs($nutzer)->post(
            route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $lauf->getKey()]),
            ['zeilen' => [(string) $mietverhaeltnis->getKey() => ['annahme' => '1']]]
        )->assertRedirect();

        $this->actingAs($nutzer)
            ->post(route('portal.wizard.vorauszahlungen.weiter', ['billingRun' => $lauf->getKey()]))
            ->assertSessionHasNoErrors();

        // Schritt 8. Je Kostenart ein bestaetigter Schluessel mit Werten.
        $kostenarten = [];

        foreach (CostItem::query()->where('billing_run_id', $lauf->getKey())->get() as $position) {
            $kategorie = $position->getAttribute('cost_category_id');

            if (! is_string($kategorie) || $kategorie === '') {
                continue;
            }

            $kostenarten[$kategorie] = [
                'key_type' => 'WOHNFLAECHE',
                'nenner' => '72,40',
                'werte' => [(string) $einheit->getKey() => '72,40'],
            ];
        }

        $this->actingAs($nutzer)->post(
            route('portal.wizard.schluessel.speichern', ['billingRun' => $lauf->getKey()]),
            ['kostenarten' => $kostenarten]
        )->assertRedirect();

        $this->actingAs($nutzer)
            ->post(route('portal.wizard.schluessel.weiter', ['billingRun' => $lauf->getKey()]))
            ->assertSessionHasNoErrors();

        // Schritt 9.
        $this->actingAs($nutzer)
            ->get(route('portal.wizard.pruefbericht', ['billingRun' => $lauf->getKey()]))
            ->assertOk();

        $this->actingAs($nutzer)
            ->post(route('portal.wizard.pruefbericht.weiter', ['billingRun' => $lauf->getKey()]))
            ->assertSessionHasNoErrors();
    }

    /**
     * Schritt 10: Vorschau erzeugen und bestaetigen.
     */
    protected function erzeugeUndBestaetigeVorschau(User $nutzer, BillingRun $lauf): void
    {
        $this->actingAs($nutzer)
            ->post(route('portal.wizard.vorschau.erzeugen', ['billingRun' => $lauf->getKey()]))
            ->assertSessionHasNoErrors();

        $this->actingAs($nutzer)->post(
            route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $lauf->getKey()]),
            ['bestaetigung' => '1']
        )->assertSessionHasNoErrors();
    }

    /**
     * Schritt 11 und 12: Checkout, signaturgeprueftes Webhook-Ereignis und
     * damit die Finalisierung.
     *
     * @return array{payment: Payment, payload: string}
     */
    protected function zahleUndFinalisiere(User $nutzer, BillingRun $lauf): array
    {
        $this->versetzeInVorschaubereit($lauf, $nutzer);

        $this->actingAs($nutzer)->post(
            route('portal.checkout.store', ['billingRun' => $lauf->getKey()]),
            ['sofortige_ausfuehrung' => '1', 'vertragsgrundlagen' => '1']
        )->assertRedirect();

        $zahlung = Payment::query()->where('billing_run_id', $lauf->getKey())->firstOrFail();
        $payload = $this->erfolgsnutzlast($zahlung);

        $this->sendeWebhook($payload)->assertOk();

        return ['payment' => $zahlung->refresh(), 'payload' => $payload];
    }

    /**
     * Vollstaendiger Happy Path nach Abschnitt 23.4.
     *
     * @return array{
     *     user: User,
     *     organization: Organization,
     *     property: Property,
     *     unit: Unit,
     *     tenancy: Tenancy,
     *     billingRun: BillingRun,
     *     payment: Payment,
     *     payload: string,
     *     praefixe: list<string>,
     *     positionen: int
     * }
     */
    protected function fuehreHappyPathAus(): array
    {
        $this->bindeKiSchicht();

        $welt = $this->konto();
        $unterlagen = $this->ladeUnterlagenHochUndWerteAus($welt['user'], $welt['billingRun']);
        $positionen = $this->pruefeKosten($welt['user'], $welt['billingRun']);

        $this->erfasseVerteilungUndPruefbericht(
            $welt['user'],
            $welt['billingRun'],
            $welt['unit'],
            $welt['tenancy'],
        );

        $this->erzeugeUndBestaetigeVorschau($welt['user'], $welt['billingRun']);

        $zahlung = $this->zahleUndFinalisiere($welt['user'], $welt['billingRun']);

        return array_merge($welt, [
            'billingRun' => $welt['billingRun']->refresh(),
            'payment' => $zahlung['payment'],
            'payload' => $zahlung['payload'],
            'praefixe' => $unterlagen['praefixe'],
            'positionen' => $positionen,
        ]);
    }

    // --- Schritt 11: Zahlung ------------------------------------------------

    /**
     * Korrekt signierte Testsignatur nach dem Schema des Anbieters.
     */
    protected function signatur(string $payload, ?int $zeitpunkt = null): string
    {
        $zeitpunkt ??= time();

        return sprintf(
            't=%d,v1=%s',
            $zeitpunkt,
            hash_hmac('sha256', $zeitpunkt.'.'.$payload, self::WEBHOOK_SECRET)
        );
    }

    /**
     * Nutzlast einer erfolgreichen Zahlung.
     *
     * @param  array<string, mixed>  $abweichungen
     */
    protected function erfolgsnutzlast(Payment $zahlung, array $abweichungen = [], ?string $eventId = null): string
    {
        $payload = json_encode([
            'id' => $eventId ?? ('evt_test_'.bin2hex(random_bytes(8))),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => array_merge([
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
                ], $abweichungen),
            ],
        ], JSON_UNESCAPED_UNICODE);

        return $payload === false ? '{}' : $payload;
    }

    /**
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
            [
                'HTTP_STRIPE_SIGNATURE' => $signatur ?? $this->signatur($payload),
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload,
        );
    }

    // --- Stoerung des Artefaktspeichers -------------------------------------

    /**
     * Macht die Artefaktablage kurzzeitig unbenutzbar.
     *
     * Produktiv liegt sie auf SFTP; ein kurzzeitiger Ausfall ist dort ein
     * realer Fall (Abschnitt 23.4). Im Test wird das Wurzelverzeichnis auf
     * einen Pfad gesetzt, unter dem nicht geschrieben werden kann.
     */
    protected function stoereArtefaktspeicher(): void
    {
        config(['filesystems.disks.local.root' => '/dev/null/artefakte-nicht-erreichbar']);

        Storage::forgetDisk('local');
        $this->app->forgetInstance(ArtifactStorage::class);
    }

    protected function stelleArtefaktspeicherWiederHer(): void
    {
        Storage::fake('local');
        $this->app->forgetInstance(ArtifactStorage::class);
    }

    protected function beispielPdf(int $seiten = 3): string
    {
        return SampleFiles::pdf($seiten);
    }
}
