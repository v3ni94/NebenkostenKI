<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Application\BillingRun\OccupancyTimeline;
use App\Domain\Period\DatePeriodRange;
use App\Enums\TenancyKind;
use App\Enums\TenancyStatus;
use App\Models\OccupancyPeriod;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\VacancyPeriod;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Mietverhaeltnisse und Zeitachse.
 *
 * Geprueft werden Ueberschneidung, Luecke, Leerstand als Abdeckung und die
 * Pflicht zur Zustellanschrift bei ausgezogenem Mieter.
 */
final class TenancyTimelineTest extends PortalTestCase
{
    /**
     * @param  array<string, mixed>  $abweichungen
     * @return array<string, mixed>
     */
    private function angaben(array $abweichungen = []): array
    {
        return array_merge([
            'tenant_display_name' => 'Eheleute Beispiel',
            'kind' => 'WOHNRAUM',
            'starts_on' => '2026-01-01',
        ], $abweichungen);
    }

    public function test_mietverhaeltnis_wird_angelegt(): void
    {
        $mandant = $this->mandant();

        // Das aus dem Mandanten stammende Mietverhaeltnis endet, damit der neue
        // Zeitraum frei ist.
        $mandant['tenancy']->forceFill(['ends_on' => '2025-12-31'])->save();

        $antwort = $this->actingAs($mandant['user'])->post(
            route('portal.mietverhaeltnisse.store', ['unit' => $mandant['unit']->getKey()]),
            $this->angaben([
                'monthly_operating_prepayment_eur' => '150,50',
                'monthly_heating_prepayment_eur' => '90',
            ])
        );

        $antwort->assertRedirect(route('portal.mietverhaeltnisse.index', ['unit' => $mandant['unit']->getKey()]));

        $neu = Tenancy::query()->where('tenant_display_name', 'Eheleute Beispiel')->firstOrFail();

        self::assertSame($mandant['organization']->getKey(), $neu->getAttribute('organization_id'));
        self::assertSame(TenancyStatus::AKTIV, $neu->getAttribute('status'));
        self::assertSame(15050, $neu->getAttribute('monthly_operating_prepayment_cent'));
        self::assertSame(9000, $neu->getAttribute('monthly_heating_prepayment_cent'));
    }

    /**
     * U10: Der Punkt mit genau drei Folgeziffern ist ein Tausendertrennzeichen.
     * "1.500" bedeutet 1.500,00 EUR und nicht 1,50 EUR oder kein Betrag.
     *
     * @return array<string, array{string, int}>
     */
    public static function monatsbetraege(): array
    {
        return [
            'deutsche Schreibweise' => ['1.500,00', 150000],
            'Tausenderpunkt ohne Komma' => ['1.500', 150000],
            'Punkt als Dezimaltrennzeichen' => ['1500.00', 150000],
            'Punkt als Dezimaltrennzeichen, vier Vorkommastellen' => ['1234.56', 123456],
            'Punkt als Dezimaltrennzeichen, zwei Vorkommastellen' => ['12.50', 1250],
            'mit Suffix EUR' => ['1.200 EUR', 120000],
            'ganze Zahl' => ['90', 9000],
        ];
    }

    #[DataProvider('monatsbetraege')]
    public function test_monatsbetraege_werden_exakt_in_cent_gespeichert(string $eingabe, int $erwartetCent): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceFill(['ends_on' => '2025-12-31'])->save();

        $this->actingAs($mandant['user'])->post(
            route('portal.mietverhaeltnisse.store', ['unit' => $mandant['unit']->getKey()]),
            $this->angaben(['monthly_operating_prepayment_eur' => $eingabe])
        )->assertRedirect()->assertSessionHasNoErrors();

        $neu = Tenancy::query()->where('tenant_display_name', 'Eheleute Beispiel')->firstOrFail();

        self::assertSame($erwartetCent, $neu->getAttribute('monthly_operating_prepayment_cent'));
    }

    /**
     * U10: Mehr als zwei Nachkommastellen oder eine nicht auswertbare
     * Schreibweise sind ein Fehler. Der Betrag wird nicht still verworfen.
     *
     * @return array<string, array{string}>
     */
    public static function unzulaessigeMonatsbetraege(): array
    {
        return [
            'drei Nachkommastellen mit Komma' => ['100,125'],
            'drei Nachkommastellen mit Punkt' => ['100.125'],
            'englische Tausenderschreibweise' => ['1,500.00'],
            'Text' => ['ca. 150'],
        ];
    }

    #[DataProvider('unzulaessigeMonatsbetraege')]
    public function test_ein_nicht_auswertbarer_monatsbetrag_wird_abgelehnt(string $eingabe): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceFill(['ends_on' => '2025-12-31'])->save();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.mietverhaeltnisse.create', ['unit' => $mandant['unit']->getKey()]))
            ->post(
                route('portal.mietverhaeltnisse.store', ['unit' => $mandant['unit']->getKey()]),
                $this->angaben(['monthly_operating_prepayment_eur' => $eingabe])
            );

        $antwort->assertSessionHasErrors('monthly_operating_prepayment_eur');
        self::assertSame(1, Tenancy::query()->count());
    }

    public function test_ueberschneidung_mit_bestehendem_mietverhaeltnis_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.mietverhaeltnisse.create', ['unit' => $mandant['unit']->getKey()]))
            ->post(
                route('portal.mietverhaeltnisse.store', ['unit' => $mandant['unit']->getKey()]),
                $this->angaben(['starts_on' => '2025-06-01'])
            );

        $antwort->assertSessionHasErrors('starts_on');
        self::assertStringContainsString(
            'überschneidet sich',
            (string) session('errors')?->first('starts_on')
        );
        self::assertSame(1, Tenancy::query()->count());
    }

    public function test_ueberschneidung_mit_leerstand_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceFill(['ends_on' => '2025-12-31'])->save();

        VacancyPeriod::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'unit_id' => $mandant['unit']->getKey(),
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-03-31',
        ]);

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.mietverhaeltnisse.create', ['unit' => $mandant['unit']->getKey()]))
            ->post(
                route('portal.mietverhaeltnisse.store', ['unit' => $mandant['unit']->getKey()]),
                $this->angaben(['starts_on' => '2026-02-01'])
            );

        $antwort->assertSessionHasErrors('starts_on');
        self::assertSame(1, Tenancy::query()->count());
    }

    public function test_auszug_vor_einzug_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.mietverhaeltnisse.create', ['unit' => $mandant['unit']->getKey()]))
            ->post(
                route('portal.mietverhaeltnisse.store', ['unit' => $mandant['unit']->getKey()]),
                $this->angaben(['starts_on' => '2026-06-01', 'ends_on' => '2026-01-01'])
            );

        $antwort->assertSessionHasErrors('ends_on');
        self::assertStringContainsString(
            'Auszug darf nicht vor dem Einzug liegen',
            (string) session('errors')?->first('ends_on')
        );
    }

    public function test_zustellanschrift_ist_bei_ausgezogenem_mieter_pflicht(): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceFill(['ends_on' => '2025-12-31'])->save();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.mietverhaeltnisse.create', ['unit' => $mandant['unit']->getKey()]))
            ->post(
                route('portal.mietverhaeltnisse.store', ['unit' => $mandant['unit']->getKey()]),
                $this->angaben(['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30'])
            );

        $antwort->assertSessionHasErrors([
            'delivery_address_line',
            'delivery_postal_code',
            'delivery_city',
        ]);
        self::assertStringContainsString(
            'beendeten Mietverhältnis',
            (string) session('errors')?->first('delivery_address_line')
        );
    }

    public function test_beendetes_mietverhaeltnis_mit_zustellanschrift_wird_gespeichert(): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceFill(['ends_on' => '2025-12-31'])->save();

        $antwort = $this->actingAs($mandant['user'])->post(
            route('portal.mietverhaeltnisse.store', ['unit' => $mandant['unit']->getKey()]),
            $this->angaben([
                'starts_on' => '2026-01-01',
                'ends_on' => '2026-06-30',
                'delivery_address_line' => 'Neue Straße 5',
                'delivery_postal_code' => '40210',
                'delivery_city' => 'Düsseldorf',
            ])
        );

        $antwort->assertRedirect(route('portal.mietverhaeltnisse.index', ['unit' => $mandant['unit']->getKey()]));

        $neu = Tenancy::query()->where('tenant_display_name', 'Eheleute Beispiel')->firstOrFail();
        self::assertSame(TenancyStatus::BEENDET, $neu->getAttribute('status'));
    }

    public function test_gewerbe_erzeugt_einen_ausdruecklichen_hinweis(): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceFill(['ends_on' => '2025-12-31'])->save();

        $antwort = $this->actingAs($mandant['user'])->post(
            route('portal.mietverhaeltnisse.store', ['unit' => $mandant['unit']->getKey()]),
            $this->angaben(['kind' => 'GEWERBE'])
        );

        $antwort->assertSessionHas('status');
        self::assertStringContainsString(
            'nicht automatisch finalisiert',
            (string) session('status')
        );

        $neu = Tenancy::query()->where('tenant_display_name', 'Eheleute Beispiel')->firstOrFail();
        self::assertSame(TenancyKind::GEWERBE, $neu->getAttribute('kind'));
    }

    public function test_leerstand_zaehlt_als_abdeckung_der_zeitachse(): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceFill([
            'starts_on' => '2025-01-01',
            'ends_on' => '2025-06-30',
        ])->save();

        $zeitachse = app(OccupancyTimeline::class);
        $rahmen = DatePeriodRange::calendarYear(2025);

        /** @var Unit $einheit */
        $einheit = Unit::query()->findOrFail($mandant['unit']->getKey());

        self::assertFalse($zeitachse->isFullyCovered($einheit, $rahmen));

        VacancyPeriod::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'unit_id' => $einheit->getKey(),
            'starts_on' => '2025-07-01',
            'ends_on' => '2025-12-31',
        ]);

        /** @var Unit $frisch */
        $frisch = Unit::query()->findOrFail($einheit->getKey());

        self::assertTrue($zeitachse->isFullyCovered($frisch, $rahmen));
    }

    public function test_luecke_wird_auf_der_uebersicht_benannt(): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceFill([
            'starts_on' => '2025-01-01',
            'ends_on' => '2025-06-30',
            'status' => TenancyStatus::BEENDET,
        ])->save();

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.mietverhaeltnisse.index', ['unit' => $mandant['unit']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('01.07.2025');
        $antwort->assertSee('weder ein Mietverhältnis noch ein Leerstand erfasst');
    }

    public function test_leerstand_wird_angelegt_und_entfernt(): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceFill(['ends_on' => '2025-06-30'])->save();

        $anlegen = $this->actingAs($mandant['user'])->post(
            route('portal.leerstand.store', ['unit' => $mandant['unit']->getKey()]),
            ['starts_on' => '2025-07-01', 'ends_on' => '2025-12-31', 'reason' => 'Renovierung']
        );

        $anlegen->assertRedirect(route('portal.mietverhaeltnisse.index', ['unit' => $mandant['unit']->getKey()]));
        self::assertStringContainsString('Leerstandskosten bleiben beim Eigentümer', (string) session('status'));

        $leerstand = VacancyPeriod::query()->firstOrFail();

        $entfernen = $this->actingAs($mandant['user'])->delete(
            route('portal.leerstand.destroy', ['vacancy' => $leerstand->getKey()])
        );

        $entfernen->assertRedirect(route('portal.mietverhaeltnisse.index', ['unit' => $mandant['unit']->getKey()]));
        self::assertSame(0, VacancyPeriod::query()->count());
    }

    public function test_ueberschneidender_leerstand_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.mietverhaeltnisse.index', ['unit' => $mandant['unit']->getKey()]))
            ->post(
                route('portal.leerstand.store', ['unit' => $mandant['unit']->getKey()]),
                ['starts_on' => '2025-03-01', 'ends_on' => '2025-04-30']
            );

        $antwort->assertSessionHasErrors('starts_on');
        self::assertSame(0, VacancyPeriod::query()->count());
    }

    public function test_belegungszeitraum_mit_personenanzahl_wird_gespeichert(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->post(
            route('portal.belegung.store', ['tenancy' => $mandant['tenancy']->getKey()]),
            ['starts_on' => '2025-01-01', 'ends_on' => '2025-06-30', 'person_count' => 3]
        );

        $antwort->assertRedirect(route('portal.mietverhaeltnisse.index', ['unit' => $mandant['unit']->getKey()]));

        $belegung = OccupancyPeriod::query()->firstOrFail();
        self::assertSame(3, $belegung->getAttribute('person_count'));
    }

    public function test_belegungszeitraum_ausserhalb_des_mietverhaeltnisses_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.mietverhaeltnisse.index', ['unit' => $mandant['unit']->getKey()]))
            ->post(
                route('portal.belegung.store', ['tenancy' => $mandant['tenancy']->getKey()]),
                ['starts_on' => '2024-01-01', 'ends_on' => '2024-06-30', 'person_count' => 2]
            );

        $antwort->assertSessionHasErrors('starts_on');
        self::assertStringContainsString(
            'nicht vor dem Einzug beginnen',
            (string) session('errors')?->first('starts_on')
        );
        self::assertSame(0, OccupancyPeriod::query()->count());
    }

    public function test_sich_ueberschneidende_belegungszeitraeume_werden_abgelehnt(): void
    {
        $mandant = $this->mandant();

        OccupancyPeriod::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'tenancy_id' => $mandant['tenancy']->getKey(),
            'starts_on' => '2025-01-01',
            'ends_on' => '2025-06-30',
            'person_count' => 2,
        ]);

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.mietverhaeltnisse.index', ['unit' => $mandant['unit']->getKey()]))
            ->post(
                route('portal.belegung.store', ['tenancy' => $mandant['tenancy']->getKey()]),
                ['starts_on' => '2025-05-01', 'ends_on' => '2025-09-30', 'person_count' => 3]
            );

        $antwort->assertSessionHasErrors('starts_on');
        self::assertSame(1, OccupancyPeriod::query()->count());
    }

    public function test_fehlende_zustellanschrift_blockiert_die_abrechnung(): void
    {
        $mandant = $this->mandant();

        $mandant['tenancy']->forceFill([
            'ends_on' => '2025-06-30',
            'status' => TenancyStatus::BEENDET,
            'delivery_address_line' => null,
            'delivery_postal_code' => null,
            'delivery_city' => null,
        ])->save();

        $antwort = $this->actingAs($mandant['user'])->get(route('portal.dashboard'));

        $antwort->assertOk();
        $antwort->assertSee('Blockiert die Abrechnung');
        $antwort->assertSee('fehlt die Zustellanschrift');
    }
}
