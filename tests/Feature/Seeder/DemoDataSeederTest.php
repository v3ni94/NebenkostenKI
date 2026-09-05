<?php

declare(strict_types=1);

namespace Tests\Feature\Seeder;

use App\Application\BillingRun\BillingRunStateMachine;
use App\Enums\ApportionmentStatus;
use App\Enums\BillingRunStatus;
use App\Enums\DeletionStatus;
use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\ExtractedField;
use App\Models\Organization;
use App\Models\ProcessingJob;
use App\Models\Property;
use App\Models\TemporaryUpload;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use App\Models\VacancyPeriod;
use Database\Seeders\CostCategorySeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Absicherung des Demodaten-Seeders.
 *
 * Geprueft werden die Sicherungen (Produktionssperre, Abbruch bei bereits
 * vorhandenen Demodaten), die Einhaltung des Loeschkonzepts (keine temporaeren
 * Uploads, keine Unterlage mit noch vorhandener Originaldatei) und die
 * Gueltigkeit der erzeugten Laufzustaende gegen die Uebergangstabelle.
 */
class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'filesystems.default' => 'local',
        ]);

        $this->app->forgetInstance('encrypter');

        Storage::fake('local');

        $this->seed(CostCategorySeeder::class);
    }

    #[Test]
    public function der_seeder_erzeugt_die_erwarteten_datensaetze(): void
    {
        $this->seed(DemoDataSeeder::class);

        self::assertSame(3, User::query()->count(), 'Drei Demokonten');
        self::assertSame(3, Organization::query()->count());

        foreach ([DemoDataSeeder::EMAIL_KUNDE, DemoDataSeeder::EMAIL_ADMIN, DemoDataSeeder::EMAIL_ZWEITKUNDE] as $email) {
            $konto = User::query()->where('email', $email)->first();

            self::assertInstanceOf(User::class, $konto, $email.' fehlt');
            self::assertNotNull($konto->getAttribute('email_verified_at'), $email.' ist nicht bestaetigt');
        }

        // Weg A: Eigentumswohnung mit einer Einheit und einem Mietverhaeltnis
        // ueber das ganze Jahr.
        $wegA = Property::query()->where('label', 'Lindenhofweg 12, Wohnung 7')->firstOrFail();

        self::assertSame(1, Unit::query()->where('property_id', $wegA->getKey())->count());
        self::assertSame(1, Tenancy::query()->where('property_id', $wegA->getKey())->count());

        // Weg B: Mehrfamilienhaus mit sechs Einheiten, Mieterwechsel und
        // Leerstandsmonat.
        $wegB = Property::query()->where('label', 'Buchenstrasse 40')->firstOrFail();

        self::assertSame(6, Unit::query()->where('property_id', $wegB->getKey())->count());
        self::assertSame(8, Tenancy::query()->where('property_id', $wegB->getKey())->count());
        self::assertSame(1, Tenancy::query()
            ->where('property_id', $wegB->getKey())
            ->whereDate('ends_on', '2025-06-30')
            ->count());
        self::assertSame(1, Tenancy::query()
            ->where('property_id', $wegB->getKey())
            ->whereDate('starts_on', '2025-07-01')
            ->count());
        self::assertSame(1, VacancyPeriod::query()
            ->whereDate('starts_on', '2025-08-01')
            ->whereDate('ends_on', '2025-08-31')
            ->count(), 'Der Leerstandsmonat fehlt');

        // Drei Laeufe des ersten Kunden, dazu ein Lauf des zweiten Mandanten.
        self::assertSame(4, BillingRun::query()->count());

        $vorschaulauf = BillingRun::query()
            ->where('property_id', $wegB->getKey())
            ->firstOrFail();

        self::assertGreaterThanOrEqual(10, CostItem::query()
            ->where('billing_run_id', $vorschaulauf->getKey())
            ->distinct()
            ->count('cost_category_id'));

        self::assertSame(16, $vorschaulauf->prepayments()->count());
        self::assertGreaterThanOrEqual(12, $vorschaulauf->allocationKeys()->count());
    }

    #[Test]
    public function der_seeder_bricht_in_der_produktionsumgebung_ab(): void
    {
        $vorher = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/production/');

            (new DemoDataSeeder)->run();
        } finally {
            $this->app['env'] = $vorher;
        }
    }

    #[Test]
    public function der_seeder_schreibt_nichts_in_der_produktionsumgebung(): void
    {
        $vorher = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            (new DemoDataSeeder)->run();
            self::fail('Der Seeder haette abbrechen muessen.');
        } catch (RuntimeException) {
            // erwartet
        } finally {
            $this->app['env'] = $vorher;
        }

        self::assertSame(0, User::query()->count());
        self::assertSame(0, Property::query()->count());
    }

    #[Test]
    public function der_seeder_erzeugt_keine_temporaeren_uploads_und_keine_originalbezuege(): void
    {
        $this->seed(DemoDataSeeder::class);

        self::assertSame(0, TemporaryUpload::query()->count(), 'Es darf keinen Kurzzeitbereich geben');
        self::assertSame(0, ProcessingJob::query()->count(), 'Es darf keine Verarbeitungsjobs geben');
        self::assertGreaterThan(0, Document::query()->count());

        foreach (Document::query()->get() as $dokument) {
            self::assertNotNull(
                $dokument->getAttribute('original_deleted_at'),
                'Jede Unterlage muss als geloescht gekennzeichnet sein'
            );
            self::assertSame(
                DeletionStatus::ERFOLGREICH,
                $dokument->getAttribute('deletion_status'),
                'Der Loeschstatus muss ERFOLGREICH sein'
            );
        }

        // Es bleiben ausschliesslich strukturierte Extraktionsdaten.
        self::assertGreaterThan(0, ExtractedField::query()->count());
    }

    #[Test]
    public function die_erzeugten_laeufe_stehen_in_gueltigen_zustaenden(): void
    {
        $this->seed(DemoDataSeeder::class);

        $erlaubt = [
            BillingRunStatus::DRAFT,
            BillingRunStatus::REVIEW_REQUIRED,
            BillingRunStatus::PREVIEW_READY,
        ];

        $gefunden = [];

        foreach (BillingRun::query()->get() as $lauf) {
            $status = $lauf->getAttribute('status');

            self::assertContains($status, $erlaubt, 'Unerwarteter Zustand '.(string) $status->value);

            $gefunden[] = $status;
        }

        foreach ($erlaubt as $status) {
            self::assertContains($status, $gefunden, 'Der Zustand '.$status->value.' fehlt in den Demodaten');
        }

        // Jeder Statuswechsel ist ueber die Statusmaschine gelaufen und steht
        // damit in der Uebergangstabelle.
        $tabelle = BillingRunStateMachine::transitions();

        $eintraege = AuditLog::query()
            ->where('action', BillingRunStateMachine::AUDIT_ACTION)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        self::assertGreaterThan(0, $eintraege->count(), 'Es fehlen Revisionseintraege der Statusmaschine');

        foreach ($eintraege as $eintrag) {
            $metadaten = $eintrag->getAttribute('metadata');

            self::assertIsArray($metadaten);

            $von = is_string($metadaten['von'] ?? null) ? (string) $metadaten['von'] : null;
            $nach = is_string($metadaten['nach'] ?? null) ? (string) $metadaten['nach'] : null;

            self::assertNotNull($von);
            self::assertNotNull($nach);

            $ziele = array_map(
                static fn (BillingRunStatus $status): string => $status->value,
                $tabelle[$von] ?? []
            );

            self::assertContains($nach, $ziele, 'Uebergang '.$von.' nach '.$nach.' ist nicht erlaubt');
        }
    }

    #[Test]
    public function der_zweite_lauf_bricht_ab_und_erzeugt_keine_dubletten(): void
    {
        $this->seed(DemoDataSeeder::class);

        $konten = User::query()->count();
        $objekte = Property::query()->count();
        $laeufe = BillingRun::query()->count();

        try {
            (new DemoDataSeeder)->run();
            self::fail('Der zweite Lauf haette abbrechen muessen.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('bereits vorhanden', $exception->getMessage());
        }

        self::assertSame($konten, User::query()->count());
        self::assertSame($objekte, Property::query()->count());
        self::assertSame($laeufe, BillingRun::query()->count());
    }

    #[Test]
    public function die_demokonten_koennen_sich_anmelden(): void
    {
        $this->seed(DemoDataSeeder::class);

        foreach ([DemoDataSeeder::EMAIL_KUNDE, DemoDataSeeder::EMAIL_ADMIN, DemoDataSeeder::EMAIL_ZWEITKUNDE] as $email) {
            $this->post(route('login'), [
                'email' => $email,
                'password' => DemoDataSeeder::PASSWORT,
            ])->assertRedirect();

            $this->assertAuthenticated();

            $this->post(route('logout'));
        }
    }

    #[Test]
    public function die_demodaten_enthalten_eine_dublette_und_eine_nicht_umlagefaehige_position(): void
    {
        $this->seed(DemoDataSeeder::class);

        self::assertSame(1, CostItem::query()->whereNotNull('duplicate_of_cost_item_id')->count());

        self::assertSame(1, CostItem::query()
            ->where('apportionment_status', ApportionmentStatus::NICHT_UMLAGEFAEHIG->value)
            ->where('excluded_from_apportionment', true)
            ->count());

        self::assertDatabaseHas('document_relations', ['relation_type' => 'DUBLETTE']);
    }
}
