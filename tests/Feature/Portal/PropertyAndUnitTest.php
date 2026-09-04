<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Application\Review\CostItemDecisions;
use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\BillingRunStatus;
use App\Enums\CostItemStatus;
use App\Enums\ValueSource;
use App\Http\Controllers\Portal\PropertyController;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\ManualOverride;
use App\Models\OccupancyPeriod;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Objekte und Einheiten: Anlegen, Bearbeiten, Loeschen und Plausibilitaet.
 */
final class PropertyAndUnitTest extends PortalTestCase
{
    /**
     * @param  array<string, mixed>  $abweichungen
     * @return array<string, mixed>
     */
    private function objektangaben(array $abweichungen = []): array
    {
        return array_merge([
            'label' => 'Rheinpromenade 13',
            'address_line' => 'Rheinpromenade 13',
            'postal_code' => '40789',
            'city' => 'Monheim am Rhein',
            'kind' => 'MEHRFAMILIENHAUS',
            'total_living_area_sqm' => '480,50',
            'mea_denominator' => '1000',
        ], $abweichungen);
    }

    public function test_objekt_wird_angelegt_und_dem_mandanten_zugeordnet(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->post(route('portal.objekte.store'), $this->objektangaben());

        $neu = Property::query()->where('label', 'Rheinpromenade 13')->first();

        self::assertInstanceOf(Property::class, $neu);
        $antwort->assertRedirect(route('portal.einheiten.index', ['property' => $neu->getKey()]));
        self::assertSame($mandant['organization']->getKey(), $neu->getAttribute('organization_id'));
        self::assertSame($mandant['user']->getKey(), $neu->getAttribute('created_by_user_id'));

        // Das Komma der Eingabe wird zur Dezimalzahl normalisiert.
        self::assertSame('480.5000', (string) $neu->getAttribute('total_living_area_sqm'));
    }

    public function test_anlegen_schreibt_einen_revisionseintrag(): void
    {
        $mandant = $this->mandant();

        $this->actingAs($mandant['user'])->post(route('portal.objekte.store'), $this->objektangaben());

        self::assertTrue(
            AuditLog::query()
                ->where('action', 'property.created')
                ->where('actor_user_id', $mandant['user']->getKey())
                ->exists()
        );
    }

    public function test_objekt_ohne_pflichtangaben_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.objekte.create'))
            ->post(route('portal.objekte.store'), [
                'label' => '',
                'address_line' => '',
                'postal_code' => '',
                'city' => '',
                'kind' => 'GIBT_ES_NICHT',
            ]);

        $antwort->assertSessionHasErrors(['label', 'address_line', 'postal_code', 'city', 'kind']);

        $fehler = session('errors');
        self::assertNotNull($fehler);
        self::assertStringContainsString('Bitte füllen Sie das Feld', (string) $fehler->first('label'));
    }

    public function test_objekt_wird_bearbeitet(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->put(
            route('portal.objekte.update', ['property' => $mandant['property']->getKey()]),
            $this->objektangaben(['label' => 'Neue Bezeichnung'])
        );

        $antwort->assertRedirect(route('portal.objekte.index'));
        self::assertSame(
            'Neue Bezeichnung',
            Property::query()->findOrFail($mandant['property']->getKey())->getAttribute('label')
        );
    }

    public function test_objekt_wird_nur_weich_geloescht(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->delete(
            route('portal.objekte.destroy', ['property' => $mandant['property']->getKey()])
        );

        $antwort->assertRedirect(route('portal.objekte.index'));
        self::assertNull(Property::query()->find($mandant['property']->getKey()));
        self::assertNotNull(Property::query()->withTrashed()->find($mandant['property']->getKey()));
    }

    public function test_objekt_mit_abrechnungslaeufen_wird_nicht_geloescht(): void
    {
        $mandant = $this->mandant();

        BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'created_by_user_id' => $mandant['user']->getKey(),
        ]);

        $antwort = $this->actingAs($mandant['user'])->delete(
            route('portal.objekte.destroy', ['property' => $mandant['property']->getKey()])
        );

        $antwort->assertRedirect(route('portal.objekte.index'));
        $antwort->assertSessionHas('status', PropertyController::MELDUNG_LAEUFE_VORHANDEN);

        // Das Objekt bleibt aktiv, es entsteht kein Geisterlauf ohne Objekt.
        self::assertNotNull(Property::query()->find($mandant['property']->getKey()));
        self::assertFalse(AuditLog::query()->where('action', 'property.deleted')->exists());
    }

    public function test_doppelte_bezeichnung_im_selben_objekt_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();
        $bezeichnung = (string) $mandant['unit']->getAttribute('label');

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.einheiten.create', ['property' => $mandant['property']->getKey()]))
            ->post(route('portal.einheiten.store', ['property' => $mandant['property']->getKey()]), [
                'label' => $bezeichnung,
            ]);

        $antwort->assertSessionHasErrors('label');
        self::assertStringContainsString('bereits vergeben', (string) session('errors')?->first('label'));
        self::assertSame(1, Unit::query()->where('property_id', $mandant['property']->getKey())->count());
    }

    public function test_dieselbe_bezeichnung_in_einem_anderen_objekt_ist_zulaessig(): void
    {
        $mandant = $this->mandant();
        $bezeichnung = (string) $mandant['unit']->getAttribute('label');

        /** @var Property $zweitesObjekt */
        $zweitesObjekt = Property::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'created_by_user_id' => $mandant['user']->getKey(),
        ]);

        $this->actingAs($mandant['user'])
            ->post(route('portal.einheiten.store', ['property' => $zweitesObjekt->getKey()]), [
                'label' => $bezeichnung,
            ])
            ->assertSessionHasNoErrors();

        self::assertSame(1, Unit::query()->where('property_id', $zweitesObjekt->getKey())->count());
    }

    public function test_bearbeitung_auf_eine_vergebene_bezeichnung_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();

        Unit::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'label' => 'Erdgeschoss rechts',
        ]);

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.einheiten.edit', ['unit' => $mandant['unit']->getKey()]))
            ->put(route('portal.einheiten.update', ['unit' => $mandant['unit']->getKey()]), [
                'label' => 'Erdgeschoss rechts',
            ]);

        $antwort->assertSessionHasErrors('label');

        // Die eigene Bezeichnung darf beim Bearbeiten unveraendert bleiben.
        $this->actingAs($mandant['user'])
            ->put(route('portal.einheiten.update', ['unit' => $mandant['unit']->getKey()]), [
                'label' => (string) $mandant['unit']->getAttribute('label'),
                'living_area_sqm' => '80',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_entfernte_bezeichnung_kann_ohne_fehler_erneut_angelegt_werden(): void
    {
        $mandant = $this->mandant();
        $bezeichnung = (string) $mandant['unit']->getAttribute('label');

        $this->actingAs($mandant['user'])
            ->delete(route('portal.einheiten.destroy', ['unit' => $mandant['unit']->getKey()]))
            ->assertRedirect();

        // Die weich geloeschte Zeile belegt den Unique-Index weiter. Das
        // Wiederanlegen derselben Bezeichnung darf nicht am Index scheitern
        // und erzeugt eine NEUE Einheit; die entfernte wird nicht restauriert.
        $antwort = $this->actingAs($mandant['user'])
            ->post(route('portal.einheiten.store', ['property' => $mandant['property']->getKey()]), [
                'label' => $bezeichnung,
                'living_area_sqm' => '55',
            ]);

        $antwort->assertRedirect(route('portal.einheiten.index', ['property' => $mandant['property']->getKey()]));
        $antwort->assertSessionHasNoErrors();

        $aktive = Unit::query()
            ->where('property_id', $mandant['property']->getKey())
            ->where('label', $bezeichnung)
            ->get();

        self::assertCount(1, $aktive);
        self::assertSame('55.0000', (string) $aktive->first()?->getAttribute('living_area_sqm'));
        self::assertNull($aktive->first()?->getAttribute('deleted_at'));
        self::assertNotSame($mandant['unit']->getKey(), $aktive->first()?->getKey());
    }

    /**
     * Befund N16: Das Loeschen einer Einheit loescht ihre Mietverhaeltnisse
     * weich mit. Das Wiederanlegen derselben Bezeichnung bringt weder die alte
     * Einheit noch deren Mietverhaeltnisse, Belegungen und Vorauszahlungen
     * zurueck.
     */
    public function test_wiederangelegte_einheit_hat_keine_alten_mietverhaeltnisse(): void
    {
        $mandant = $this->mandant();
        $bezeichnung = (string) $mandant['unit']->getAttribute('label');

        OccupancyPeriod::query()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'tenancy_id' => $mandant['tenancy']->getKey(),
            'starts_on' => '2025-01-01',
            'ends_on' => '2025-12-31',
            'person_count' => 2,
            'source' => ValueSource::MANUELL,
        ]);

        $this->actingAs($mandant['user'])
            ->delete(route('portal.einheiten.destroy', ['unit' => $mandant['unit']->getKey()]))
            ->assertRedirect();

        // Das Mietverhaeltnis ist mit der Einheit weich geloescht.
        self::assertNull(Tenancy::query()->find($mandant['tenancy']->getKey()));
        self::assertNotNull(Tenancy::withTrashed()->find($mandant['tenancy']->getKey()));

        $this->actingAs($mandant['user'])
            ->post(route('portal.einheiten.store', ['property' => $mandant['property']->getKey()]), [
                'label' => $bezeichnung,
                'living_area_sqm' => '55',
            ])
            ->assertSessionHasNoErrors();

        /** @var Unit $neu */
        $neu = Unit::query()
            ->where('property_id', $mandant['property']->getKey())
            ->where('label', $bezeichnung)
            ->firstOrFail();

        self::assertNotSame($mandant['unit']->getKey(), $neu->getKey());
        self::assertSame(0, $neu->tenancies()->count());
        self::assertSame(0, $neu->tenancies()->withTrashed()->count());

        // Die alte Zeile bleibt wegen ihrer Bezuege erhalten, gibt aber die
        // Bezeichnung frei.
        $alt = Unit::withTrashed()->find($mandant['unit']->getKey());

        self::assertNotNull($alt);
        self::assertNotSame($bezeichnung, $alt->getAttribute('label'));
        self::assertStringStartsWith($bezeichnung.' (entfernt', (string) $alt->getAttribute('label'));

        $liste = $this->actingAs($mandant['user'])->get(
            route('portal.mietverhaeltnisse.index', ['unit' => $neu->getKey()])
        );

        $liste->assertOk();
        $liste->assertDontSee((string) $mandant['tenancy']->getAttribute('tenant_display_name'));
    }

    public function test_entfernte_einheit_ohne_bezuege_wird_beim_wiederanlegen_endgueltig_entfernt(): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceDelete();
        $bezeichnung = (string) $mandant['unit']->getAttribute('label');

        $this->actingAs($mandant['user'])
            ->delete(route('portal.einheiten.destroy', ['unit' => $mandant['unit']->getKey()]))
            ->assertRedirect();

        $this->actingAs($mandant['user'])
            ->post(route('portal.einheiten.store', ['property' => $mandant['property']->getKey()]), [
                'label' => $bezeichnung,
                'living_area_sqm' => '55',
            ])
            ->assertSessionHasNoErrors();

        self::assertNull(Unit::withTrashed()->find($mandant['unit']->getKey()));
        self::assertSame(1, Unit::withTrashed()->where('property_id', $mandant['property']->getKey())->count());

        // Befund R5: Das endgueltige Entfernen ist protokolliert.
        self::assertTrue(
            AuditLog::query()
                ->where('action', 'unit.purged')
                ->where('subject_id', $mandant['unit']->getKey())
                ->exists()
        );
    }

    /**
     * Befund R5: Eine Kostenposition, die der entfernten Einheit direkt
     * zugeordnet war, faellt nicht still auf den Kategorieschluessel zurueck.
     * Sie wird wieder pruefpflichtig, die Zuordnung wird geloest und der
     * Vorgang wird nachvollziehbar festgehalten.
     */
    public function test_direkt_zugeordnete_position_wird_beim_entfernen_der_einheit_pruefpflichtig(): void
    {
        $mandant = $this->mandant();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'created_by_user_id' => $mandant['user']->getKey(),
            'status' => BillingRunStatus::REVIEW_REQUIRED,
        ]);

        /** @var CostItem $position */
        $position = CostItem::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'description' => 'Grundsteuer Wohnung 1',
            'status' => CostItemStatus::BESTAETIGT,
            'confirmed_by_user_id' => $mandant['user']->getKey(),
            'confirmed_at' => now(),
            'direct_unit_id' => $mandant['unit']->getKey(),
        ]);

        $this->actingAs($mandant['user'])
            ->delete(route('portal.einheiten.destroy', ['unit' => $mandant['unit']->getKey()]))
            ->assertRedirect();

        $position->refresh();

        self::assertSame(CostItemStatus::VORGESCHLAGEN, $position->status);
        self::assertNull($position->direct_unit_id);
        self::assertNull($position->confirmed_at);

        $vermerk = ManualOverride::query()
            ->where('subject_type', CostItem::class)
            ->where('subject_id', $position->getKey())
            ->where('field', 'direct_unit_id')
            ->first();

        self::assertNotNull($vermerk);
        self::assertStringContainsString('Zieleinheit', (string) $vermerk->reason);
        self::assertSame($mandant['unit']->getKey(), $vermerk->old_value['einheit'] ?? null);
        self::assertTrue(
            AuditLog::query()->where('action', CostItemDecisions::AUDIT_DIRECT_UNIT_REMOVED)->exists()
        );
    }

    /**
     * Befund R5: Positionen eines bereits finalisierten Laufs bleiben
     * unangetastet; ihr Berechnungsstand ist gesperrt.
     */
    public function test_positionen_finalisierter_laeufe_bleiben_beim_entfernen_der_einheit_unveraendert(): void
    {
        $mandant = $this->mandant();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'created_by_user_id' => $mandant['user']->getKey(),
            'status' => BillingRunStatus::FINALIZED,
        ]);

        /** @var CostItem $position */
        $position = CostItem::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'status' => CostItemStatus::BESTAETIGT,
            'confirmed_at' => now(),
            'direct_unit_id' => $mandant['unit']->getKey(),
        ]);

        $this->actingAs($mandant['user'])
            ->delete(route('portal.einheiten.destroy', ['unit' => $mandant['unit']->getKey()]))
            ->assertRedirect();

        $position->refresh();

        self::assertSame(CostItemStatus::BESTAETIGT, $position->status);
        self::assertSame($mandant['unit']->getKey(), $position->direct_unit_id);
    }

    /**
     * Befund R5: Eine entfernte Einheit mit direkt zugeordneter Kostenposition
     * oder mit Schluesselwerten wird beim Wiederanlegen der Bezeichnung nicht
     * endgueltig geloescht. Sonst setzte die Datenbank direct_unit_id still
     * auf null und die Position fiele auf den Kategorieschluessel zurueck.
     *
     * @return array<string, array{0: string}>
     */
    public static function abrechnungsbezuege(): array
    {
        return [
            'direkt zugeordnete Kostenposition' => ['kostenposition'],
            'Schluesselwert der Einheit' => ['schluesselwert'],
        ];
    }

    #[DataProvider('abrechnungsbezuege')]
    public function test_entfernte_einheit_mit_abrechnungsbezug_wird_beim_wiederanlegen_nicht_endgueltig_entfernt(string $bezug): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceDelete();
        $bezeichnung = (string) $mandant['unit']->getAttribute('label');

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'created_by_user_id' => $mandant['user']->getKey(),
            'status' => BillingRunStatus::FINALIZED,
        ]);

        if ($bezug === 'kostenposition') {
            CostItem::factory()->create([
                'organization_id' => $mandant['organization']->getKey(),
                'billing_run_id' => $lauf->getKey(),
                'status' => CostItemStatus::BESTAETIGT,
                'direct_unit_id' => $mandant['unit']->getKey(),
            ]);
        } else {
            /** @var AllocationKey $schluessel */
            $schluessel = AllocationKey::factory()->create([
                'organization_id' => $mandant['organization']->getKey(),
                'billing_run_id' => $lauf->getKey(),
                'key_type' => AllocationKeyType::WOHNFLAECHE,
                'source' => AllocationKeySource::MANUELL,
            ]);

            AllocationKeyValue::query()->create([
                'organization_id' => $mandant['organization']->getKey(),
                'allocation_key_id' => $schluessel->getKey(),
                'unit_id' => $mandant['unit']->getKey(),
                'numerator' => '72.5000',
                'source' => ValueSource::MANUELL,
            ]);
        }

        $this->actingAs($mandant['user'])
            ->delete(route('portal.einheiten.destroy', ['unit' => $mandant['unit']->getKey()]))
            ->assertRedirect();

        $this->actingAs($mandant['user'])
            ->post(route('portal.einheiten.store', ['property' => $mandant['property']->getKey()]), [
                'label' => $bezeichnung,
                'living_area_sqm' => '55',
            ])
            ->assertSessionHasNoErrors();

        $alt = Unit::withTrashed()->find($mandant['unit']->getKey());

        self::assertNotNull($alt);
        self::assertStringStartsWith($bezeichnung.' (entfernt', (string) $alt->getAttribute('label'));
        self::assertSame(2, Unit::withTrashed()->where('property_id', $mandant['property']->getKey())->count());

        if ($bezug === 'kostenposition') {
            self::assertSame(1, CostItem::query()->where('direct_unit_id', $mandant['unit']->getKey())->count());
        } else {
            self::assertSame(1, AllocationKeyValue::query()->where('unit_id', $mandant['unit']->getKey())->count());
        }
    }

    public function test_einheit_wird_mit_allen_schluesselwerten_angelegt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->post(
            route('portal.einheiten.store', ['property' => $mandant['property']->getKey()]),
            [
                'label' => 'Dachgeschoss links',
                'location' => '2. OG links',
                'living_area_sqm' => '72,50',
                'heated_area_sqm' => '70,25',
                'mea' => '87,5',
                'individual_key_1_value' => '2',
                'individual_key_5_value' => '1,5',
            ]
        );

        $antwort->assertRedirect(route('portal.einheiten.index', ['property' => $mandant['property']->getKey()]));

        $einheit = Unit::query()->where('label', 'Dachgeschoss links')->firstOrFail();

        self::assertSame($mandant['organization']->getKey(), $einheit->getAttribute('organization_id'));
        self::assertSame('72.5000', (string) $einheit->getAttribute('living_area_sqm'));
        self::assertSame('87.500000', (string) $einheit->getAttribute('mea'));
        self::assertSame('2.0000', (string) $einheit->getAttribute('individual_key_1_value'));
        self::assertSame('1.5000', (string) $einheit->getAttribute('individual_key_5_value'));
    }

    public function test_einheit_ohne_bezeichnung_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.einheiten.create', ['property' => $mandant['property']->getKey()]))
            ->post(route('portal.einheiten.store', ['property' => $mandant['property']->getKey()]), [
                'label' => '',
            ]);

        $antwort->assertSessionHasErrors('label');
        self::assertStringContainsString(
            'Bezeichnung für die Einheit',
            (string) session('errors')?->first('label')
        );
    }

    public function test_negative_flaeche_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.einheiten.create', ['property' => $mandant['property']->getKey()]))
            ->post(route('portal.einheiten.store', ['property' => $mandant['property']->getKey()]), [
                'label' => 'WE 99',
                'living_area_sqm' => '-5',
            ]);

        $antwort->assertSessionHasErrors('living_area_sqm');
    }

    public function test_einheit_wird_bearbeitet_und_entfernt(): void
    {
        $mandant = $this->mandant();

        $bearbeiten = $this->actingAs($mandant['user'])->put(
            route('portal.einheiten.update', ['unit' => $mandant['unit']->getKey()]),
            ['label' => 'WE neu', 'living_area_sqm' => '80']
        );

        $bearbeiten->assertRedirect(route('portal.einheiten.index', ['property' => $mandant['property']->getKey()]));
        self::assertSame('WE neu', Unit::query()->findOrFail($mandant['unit']->getKey())->getAttribute('label'));

        $entfernen = $this->actingAs($mandant['user'])->delete(
            route('portal.einheiten.destroy', ['unit' => $mandant['unit']->getKey()])
        );

        $entfernen->assertRedirect(route('portal.einheiten.index', ['property' => $mandant['property']->getKey()]));
        self::assertNull(Unit::query()->find($mandant['unit']->getKey()));
    }

    public function test_plausibilitaetshinweis_bei_abweichender_flaechensumme(): void
    {
        $mandant = $this->mandant();

        // Objekt fuehrt 480,00 m², die einzige Einheit hat 72,50 m².
        $mandant['property']->forceFill(['total_living_area_sqm' => '480.0000'])->save();

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.einheiten.index', ['property' => $mandant['property']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Hinweis zur Plausibilität');
        $antwort->assertSee('Die Summe der Einheitenflächen beträgt 72,5 Quadratmeter');
        $antwort->assertSee('Abweichungen sind nicht zwingend ein Fehler');
    }

    public function test_plausibilitaetshinweis_bei_abweichender_anteilssumme(): void
    {
        $mandant = $this->mandant();

        $mandant['property']->forceFill([
            'total_living_area_sqm' => '72.5000',
            'mea_denominator' => '1000.000000',
        ])->save();

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.einheiten.index', ['property' => $mandant['property']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Die Summe der Miteigentumsanteile beträgt 87,5');
    }

    public function test_keine_hinweise_bei_stimmigen_summen(): void
    {
        $mandant = $this->mandant();

        $mandant['property']->forceFill([
            'total_living_area_sqm' => '72.5000',
            'total_heated_area_sqm' => '70.2500',
            'mea_denominator' => '87.500000',
        ])->save();

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.einheiten.index', ['property' => $mandant['property']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertDontSee('Hinweis zur Plausibilität');
    }
}
