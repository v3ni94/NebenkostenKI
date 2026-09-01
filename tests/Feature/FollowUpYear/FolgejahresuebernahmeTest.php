<?php

declare(strict_types=1);

namespace Tests\Feature\FollowUpYear;

use App\Application\FollowUpYear\CarriedOver;
use App\Application\FollowUpYear\CarryOverToFollowUpYear;
use App\Application\FollowUpYear\KeinFinalisierterVorjahreslaufException;
use App\Application\FollowUpYear\PriorYearComparison;
use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\BillingMode;
use App\Enums\BillingRunStatus;
use App\Enums\TenancyStatus;
use App\Enums\ValueSource;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\CostItem;
use App\Models\Landlord;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Prepayment;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Folgejahresuebernahme (Masterprompt 8.3).
 *
 * Kernaussagen dieser Tests:
 *   - uebernommen werden Objekt, Eigentuemer, Einheiten, laufende
 *     Mietverhaeltnisse, Verteilerschluessel, Kategorien und Bankdaten
 *   - uebernommen wird KEINE einzige Kostenposition
 *   - ein beendetes Mietverhaeltnis wird nicht fortgeschrieben
 *   - Verteilerschluessel tragen die Quellenkennzeichnung VORJAHR
 */
final class FolgejahresuebernahmeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     user: User,
     *     organization: Organization,
     *     property: Property,
     *     run: BillingRun,
     *     unit: Unit,
     *     laufend: Tenancy,
     *     beendet: Tenancy,
     *     kategorie: CostCategory,
     *     key: AllocationKey
     * }
     */
    private function vorjahr(): array
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create();

        $organisation = Organization::factory()->create();

        OrganizationUser::query()->create([
            'organization_id' => $organisation->getKey(),
            'user_id' => $nutzer->getKey(),
            'role' => 'OWNER',
            'joined_at' => now(),
        ]);

        /** @var Landlord $vermieter */
        $vermieter = Landlord::factory()->create([
            'organization_id' => $organisation->getKey(),
        ]);

        /** @var Property $objekt */
        $objekt = Property::factory()->create([
            'organization_id' => $organisation->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
            'landlord_id' => $vermieter->getKey(),
        ]);

        /** @var Unit $einheit */
        $einheit = Unit::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'label' => 'WE 1',
            'living_area_sqm' => '72.5000',
            'individual_key_1_value' => '2.0000',
        ]);

        /** @var Tenancy $laufend */
        $laufend = Tenancy::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'unit_id' => $einheit->getKey(),
            'status' => TenancyStatus::AKTIV,
            'starts_on' => '2024-01-01',
            'ends_on' => null,
        ]);

        /** @var Unit $zweiteEinheit */
        $zweiteEinheit = Unit::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'label' => 'WE 2',
        ]);

        /** @var Tenancy $beendet */
        $beendet = Tenancy::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'unit_id' => $zweiteEinheit->getKey(),
            'status' => TenancyStatus::BEENDET,
            'starts_on' => '2024-01-01',
            'ends_on' => '2025-06-30',
        ]);

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->fullProperty()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'landlord_id' => $vermieter->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
            'billing_year' => 2025,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'status' => BillingRunStatus::FINALIZED,
            'finalized_at' => now(),
            'statement_count' => 2,
        ]);

        /** @var CostCategory $kategorie */
        $kategorie = CostCategory::factory()->create();

        /** @var AllocationKey $schluessel */
        $schluessel = AllocationKey::factory()->create([
            'organization_id' => $organisation->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'cost_category_id' => $kategorie->getKey(),
            'key_type' => AllocationKeyType::WOHNFLAECHE,
            'source' => AllocationKeySource::MIETVERTRAG,
            'confirmed_by_user_id' => $nutzer->getKey(),
            'confirmed_at' => now(),
        ]);

        AllocationKeyValue::factory()->create([
            'organization_id' => $organisation->getKey(),
            'allocation_key_id' => $schluessel->getKey(),
            'unit_id' => $einheit->getKey(),
            'numerator' => '72.500000',
            'source' => ValueSource::MIETVERTRAG,
        ]);

        AllocationKeyValue::factory()->create([
            'organization_id' => $organisation->getKey(),
            'allocation_key_id' => $schluessel->getKey(),
            'tenancy_id' => $beendet->getKey(),
            'numerator' => '30.000000',
            'source' => ValueSource::MIETVERTRAG,
        ]);

        CostItem::factory()->confirmed()->create([
            'organization_id' => $organisation->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'cost_category_id' => $kategorie->getKey(),
            'amount_cent' => 128450,
        ]);

        Prepayment::factory()->create([
            'organization_id' => $organisation->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'tenancy_id' => $laufend->getKey(),
        ]);

        return [
            'user' => $nutzer,
            'organization' => $organisation,
            'property' => $objekt,
            'run' => $lauf,
            'unit' => $einheit,
            'laufend' => $laufend,
            'beendet' => $beendet,
            'kategorie' => $kategorie,
            'key' => $schluessel,
        ];
    }

    private function uebernahme(): CarryOverToFollowUpYear
    {
        return app(CarryOverToFollowUpYear::class);
    }

    public function test_neuer_lauf_uebernimmt_objekt_vermieter_und_zeitraum(): void
    {
        $welt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        $neu = $ergebnis->lauf;

        $this->assertTrue($ergebnis->neuAngelegt);
        $this->assertSame(2026, $neu->getAttribute('billing_year'));
        $this->assertSame('2026-01-01', $neu->getAttribute('period_start')->format('Y-m-d'));
        $this->assertSame('2026-12-31', $neu->getAttribute('period_end')->format('Y-m-d'));
        $this->assertSame(BillingRunStatus::DRAFT, $neu->getAttribute('status'));
        $this->assertSame((string) $welt['property']->getKey(), $neu->getAttribute('property_id'));
        $this->assertSame(
            (string) $welt['property']->getAttribute('landlord_id'),
            $neu->getAttribute('landlord_id')
        );
        $this->assertSame((string) $welt['run']->getKey(), $neu->getAttribute('previous_billing_run_id'));
        $this->assertSame(BillingMode::FULL_PROPERTY, $neu->getAttribute('mode'));
    }

    public function test_keine_einzige_kostenposition_wird_uebernommen(): void
    {
        $welt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        $this->assertSame(
            0,
            CostItem::query()->where('billing_run_id', $ergebnis->lauf->getKey())->count()
        );
        $this->assertSame(1, CostItem::query()->where('billing_run_id', $welt['run']->getKey())->count());
    }

    public function test_vorauszahlungen_und_preisstand_werden_nicht_uebernommen(): void
    {
        $welt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);
        $neu = $ergebnis->lauf;

        $this->assertSame(
            0,
            Prepayment::query()->where('billing_run_id', $neu->getKey())->count()
        );
        $this->assertSame(0, $neu->getAttribute('statement_count'));
        $this->assertNull($neu->getAttribute('price_total_gross_cent'));
        $this->assertNull($neu->getAttribute('paid_at'));
        $this->assertNull($neu->getAttribute('finalized_at'));
        $this->assertNull($neu->getAttribute('heating_supply_case'));
        $this->assertNull($neu->getAttribute('review_confirmed_at'));
    }

    public function test_laufendes_mietverhaeltnis_wird_fortgeschrieben(): void
    {
        $welt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        $this->assertContains((string) $welt['laufend']->getKey(), $ergebnis->mietverhaeltnisse);
    }

    public function test_beendetes_mietverhaeltnis_wird_nicht_fortgeschrieben(): void
    {
        $welt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        $this->assertNotContains((string) $welt['beendet']->getKey(), $ergebnis->mietverhaeltnisse);
        $this->assertCount(1, $ergebnis->mietverhaeltnisse);
    }

    public function test_einheiten_mit_flaechen_und_individuellen_schluesseln_gelten_weiter(): void
    {
        $welt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        $this->assertContains((string) $welt['unit']->getKey(), $ergebnis->einheiten);
        $this->assertCount(2, $ergebnis->einheiten);

        $welt['unit']->refresh();

        $this->assertSame('72.5000', $welt['unit']->getAttribute('living_area_sqm'));
        $this->assertSame('2.0000', $welt['unit']->getAttribute('individual_key_1_value'));
    }

    public function test_verteilerschluessel_kommen_mit_quellenkennzeichnung_vorjahr(): void
    {
        $welt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        $this->assertCount(1, $ergebnis->verteilerschluessel);

        /** @var AllocationKey $kopie */
        $kopie = AllocationKey::query()
            ->where('billing_run_id', $ergebnis->lauf->getKey())
            ->firstOrFail();

        $this->assertSame(AllocationKeySource::VORJAHR, $kopie->getAttribute('source'));
        $this->assertSame('Aus Vorjahr übernommen', AllocationKeySource::VORJAHR->label());
        $this->assertSame(AllocationKeyType::WOHNFLAECHE, $kopie->getAttribute('key_type'));
        $this->assertSame(
            (string) $welt['kategorie']->getKey(),
            $kopie->getAttribute('cost_category_id')
        );
        $this->assertNotSame((string) $welt['key']->getKey(), (string) $kopie->getKey());
    }

    public function test_uebernommene_felder_tragen_den_hinweis_aus_vorjahr_uebernommen(): void
    {
        $welt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        /** @var AllocationKey $kopie */
        $kopie = AllocationKey::query()
            ->where('billing_run_id', $ergebnis->lauf->getKey())
            ->firstOrFail();

        $this->assertSame(CarriedOver::HINWEIS, $kopie->getAttribute('note'));
        $this->assertSame('Aus Vorjahr übernommen', $ergebnis->hinweis());
        $this->assertStringContainsString('Aus Vorjahr übernommen', $ergebnis->zusammenfassung());
    }

    public function test_uebernommener_schluessel_ist_unbestaetigt(): void
    {
        $welt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        /** @var AllocationKey $kopie */
        $kopie = AllocationKey::query()
            ->where('billing_run_id', $ergebnis->lauf->getKey())
            ->firstOrFail();

        $this->assertNull($kopie->getAttribute('confirmed_at'));
        $this->assertNull($kopie->getAttribute('confirmed_by_user_id'));
        $this->assertFalse($kopie->isConfirmed());
    }

    public function test_schluesselwerte_kommen_mit_quelle_vorjahr_und_ohne_beendete_mietverhaeltnisse(): void
    {
        $welt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        /** @var AllocationKey $kopie */
        $kopie = AllocationKey::query()
            ->where('billing_run_id', $ergebnis->lauf->getKey())
            ->firstOrFail();

        $werte = AllocationKeyValue::query()->where('allocation_key_id', $kopie->getKey())->get();

        $this->assertCount(1, $werte);

        /** @var AllocationKeyValue $wert */
        $wert = $werte->firstOrFail();

        $this->assertSame(ValueSource::VORJAHR, $wert->getAttribute('source'));
        $this->assertSame('Aus Vorjahr übernommen', ValueSource::VORJAHR->label());
        $this->assertSame((string) $welt['unit']->getKey(), $wert->getAttribute('unit_id'));
        $this->assertSame('72.500000', $wert->getAttribute('numerator'));
    }

    public function test_positionsbezogener_schluessel_wird_nicht_uebernommen(): void
    {
        $welt = $this->vorjahr();

        /** @var CostItem $position */
        $position = CostItem::query()->where('billing_run_id', $welt['run']->getKey())->firstOrFail();

        AllocationKey::factory()->create([
            'organization_id' => $welt['organization']->getKey(),
            'billing_run_id' => $welt['run']->getKey(),
            'cost_category_id' => $welt['kategorie']->getKey(),
            'cost_item_id' => $position->getKey(),
            'key_type' => AllocationKeyType::VERBRAUCH,
        ]);

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        $this->assertCount(1, $ergebnis->verteilerschluessel);
        $this->assertSame(
            0,
            AllocationKey::query()
                ->where('billing_run_id', $ergebnis->lauf->getKey())
                ->whereNotNull('cost_item_id')
                ->count()
        );
    }

    public function test_kostenkategorien_werden_als_kennung_uebernommen(): void
    {
        $welt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        $this->assertSame([(string) $welt['kategorie']->getKey()], $ergebnis->kostenkategorien);
    }

    public function test_vorjahreswerte_sind_nur_vergleich(): void
    {
        $welt = $this->vorjahr();

        $vergleich = app(PriorYearComparison::class);

        $this->assertSame(128450, $vergleich->gesamtCent($welt['run']));
        $this->assertSame(
            [(string) $welt['kategorie']->getKey() => 128450],
            $vergleich->jeKategorie($welt['run'])
        );

        $hinweis = $vergleich->hinweis($welt['run']);

        $this->assertStringContainsString('1.284,50 EUR', $hinweis);
        $this->assertStringContainsString('nur dem Vergleich', $hinweis);
        $this->assertStringNotContainsString('–', $hinweis);

        $ergebnis = $this->uebernahme()->handle($welt['property'], $welt['user']);

        $this->assertSame(
            0,
            CostItem::query()->where('billing_run_id', $ergebnis->lauf->getKey())->count()
        );
    }

    public function test_uebernahme_ist_idempotent(): void
    {
        $welt = $this->vorjahr();

        $erste = $this->uebernahme()->handle($welt['property'], $welt['user']);
        $zweite = $this->uebernahme()->handle($welt['property'], $welt['user']);

        $this->assertTrue($erste->neuAngelegt);
        $this->assertFalse($zweite->neuAngelegt);
        $this->assertSame((string) $erste->lauf->getKey(), (string) $zweite->lauf->getKey());
        $this->assertSame(
            1,
            BillingRun::query()
                ->where('property_id', $welt['property']->getKey())
                ->where('billing_year', 2026)
                ->count()
        );
        $this->assertCount(1, $zweite->verteilerschluessel);
    }

    public function test_ohne_finalisierten_vorjahreslauf_gibt_es_keine_uebernahme(): void
    {
        $welt = $this->vorjahr();
        $welt['run']->forceFill(['status' => BillingRunStatus::PREVIEW_READY])->save();

        $this->expectException(KeinFinalisierterVorjahreslaufException::class);

        $this->uebernahme()->handle($welt['property'], $welt['user']);
    }

    public function test_uebernahme_wird_revisionssicher_protokolliert(): void
    {
        $welt = $this->vorjahr();

        $this->uebernahme()->handle($welt['property'], $welt['user']);

        /** @var AuditLog|null $eintrag */
        $eintrag = AuditLog::query()
            ->where('action', CarryOverToFollowUpYear::AUDIT_ACTION)
            ->first();

        $this->assertInstanceOf(AuditLog::class, $eintrag);

        /** @var array<string, mixed> $metadaten */
        $metadaten = $eintrag->getAttribute('metadata');

        $this->assertSame(0, $metadaten['kostenpositionen']);
        $this->assertSame(2026, $metadaten['jahr']);
    }

    public function test_mandantentrennung_fremdes_objekt_bleibt_unberuehrt(): void
    {
        $ersteWelt = $this->vorjahr();
        $zweiteWelt = $this->vorjahr();

        $ergebnis = $this->uebernahme()->handle($ersteWelt['property'], $ersteWelt['user']);

        $this->assertSame(
            (string) $ersteWelt['organization']->getKey(),
            $ergebnis->lauf->getAttribute('organization_id')
        );
        $this->assertSame(
            0,
            BillingRun::query()
                ->where('property_id', $zweiteWelt['property']->getKey())
                ->where('billing_year', 2026)
                ->count()
        );

        foreach (AllocationKey::query()->where('billing_run_id', $ergebnis->lauf->getKey())->get() as $schluessel) {
            $this->assertSame(
                (string) $ersteWelt['organization']->getKey(),
                $schluessel->getAttribute('organization_id')
            );
        }
    }
}
