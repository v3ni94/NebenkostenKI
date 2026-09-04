<?php

declare(strict_types=1);

namespace Tests\Feature\Review;

use App\Application\Reconciliation\ReconcileBillingRun;
use App\Application\Review\CostReviewPresenter;
use App\Application\Review\Dto\WarningBanner;
use App\Application\Review\ReviewGate;
use App\Enums\ApportionmentStatus;
use App\Enums\CostItemSource;
use App\Enums\CostItemStatus;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\CostItem;
use App\Models\Organization;
use App\Models\Unit;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pruefoberflaeche der Kostenpruefung (Schritt 6).
 */
final class CostReviewTest extends ReviewTestCase
{
    public function test_gruppierung_fasst_zwei_gaertnerrechnungen_zu_einer_zeile_zusammen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->gartenrechnung($lauf, $mandant['organization'], 'Unterlage A', 'RE-1', 25000);
        $this->gartenrechnung($lauf, $mandant['organization'], 'Unterlage B', 'RE-2', 15000);

        $uebersicht = app(CostReviewPresenter::class)->overview($lauf);

        self::assertCount(1, $uebersicht->groups);
        self::assertSame('Gartenpflege', $uebersicht->groups[0]->name);
        self::assertSame(40000, $uebersicht->groups[0]->sumCent);
        self::assertSame(2, $uebersicht->groups[0]->positionCount());
    }

    public function test_oberflaeche_zeigt_gruppe_summe_und_quellenangabe(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::HAUSMEISTER_REINIGUNG_GARTEN, 'Unterlage 01');
        $this->felder($dokument, [
            'aussteller' => 'Gartenbau Beispiel',
            'belegdatum' => '2025-06-30',
            'gesamtbetrag_brutto_cent' => 25000,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.pruefung.kosten', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Gartenpflege');
        $antwort->assertSee('250,00 EUR');
        $antwort->assertSee('Unterlage 01');
        $antwort->assertSee('Seite 1');
        $antwort->assertSee('Erkennungssicherheit');
    }

    public function test_oberflaeche_erklaert_dass_keine_seitenansicht_moeglich_ist(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->gartenrechnung($lauf, $mandant['organization'], 'Unterlage A', 'RE-1', 25000);

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.pruefung.kosten', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Eine Seitenansicht der Unterlagen ist hier nicht möglich', false);
        $antwort->assertSee('nach der Auswertung gelöscht', false);
    }

    public function test_warnbanner_bei_nicht_umlagefaehiger_kategorie(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->position($lauf, $mandant['organization'], 'Verwaltervergütung', 'VERWALTUNGSKOSTEN', 30000);

        $uebersicht = app(CostReviewPresenter::class)->overview($lauf);

        $arten = array_map(static fn (WarningBanner $banner): string => $banner->kind, $uebersicht->banners);

        self::assertContains(WarningBanner::KIND_NOT_ALLOCABLE, $arten);

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.pruefung.kosten', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertSee('nicht umlagefähig', false);
        $antwort->assertSee('keine Rechtsberatung im Einzelfall', false);
    }

    public function test_warnbanner_bei_leistungszeitraum_ausserhalb_des_zeitraums(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->position($lauf, $mandant['organization'], 'Gartenpflege Vorjahr', 'GARTENPFLEGE', 25000, [
            'service_period_start' => '2024-01-01',
            'service_period_end' => '2024-12-31',
        ]);

        $uebersicht = app(CostReviewPresenter::class)->overview($lauf);

        $arten = array_map(static fn (WarningBanner $banner): string => $banner->kind, $uebersicht->banners);

        self::assertContains(WarningBanner::KIND_OUTSIDE_PERIOD, $arten);

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.pruefung.kosten', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertSee('außerhalb des Abrechnungszeitraums', false);
    }

    public function test_position_wird_bestaetigt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Gartenpflege', 'GARTENPFLEGE', 25000);

        $this->actingAs($mandant['user'])->post(route('portal.pruefung.kosten.bestaetigen', [
            'billingRun' => $lauf->getKey(),
            'costItem' => $position->getKey(),
        ]))->assertRedirect(route('portal.pruefung.kosten', ['billingRun' => $lauf->getKey()]));

        $position->refresh();

        self::assertSame(CostItemStatus::BESTAETIGT, $position->getAttribute('status'));
        self::assertNotNull($position->getAttribute('confirmed_at'));
        self::assertSame($mandant['user']->getKey(), $position->getAttribute('confirmed_by_user_id'));
    }

    public function test_position_wird_verworfen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Gartenpflege', 'GARTENPFLEGE', 25000);

        $this->actingAs($mandant['user'])->post(route('portal.pruefung.kosten.verwerfen', [
            'billingRun' => $lauf->getKey(),
            'costItem' => $position->getKey(),
        ]))->assertRedirect();

        $position->refresh();

        self::assertSame(CostItemStatus::VERWORFEN, $position->getAttribute('status'));
        self::assertTrue($position->getAttribute('excluded_from_apportionment'));
    }

    public function test_position_wird_von_der_umlage_ausgeschlossen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Gartenpflege', 'GARTENPFLEGE', 25000);

        $this->actingAs($mandant['user'])->post(route('portal.pruefung.kosten.ausschliessen', [
            'billingRun' => $lauf->getKey(),
            'costItem' => $position->getKey(),
        ]), ['grund' => 'Nicht vereinbart'])->assertRedirect();

        $position->refresh();

        self::assertTrue($position->getAttribute('excluded_from_apportionment'));
        self::assertSame(ApportionmentStatus::NICHT_UMLAGEFAEHIG, $position->getAttribute('apportionment_status'));
        self::assertSame('Nicht vereinbart', $position->getAttribute('apportionment_override_reason'));
    }

    public function test_position_wird_bearbeitet_und_kategorie_geaendert(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Unklare Leistung', null, 25000);
        $kategorie = $this->kategorie('GARTENPFLEGE');

        $this->actingAs($mandant['user'])->put(route('portal.pruefung.kosten.update', [
            'billingRun' => $lauf->getKey(),
            'costItem' => $position->getKey(),
        ]), [
            'description' => 'Gartenpflege Sommer',
            'betrag_euro' => '1.234,56',
            'cost_category_id' => $kategorie->getKey(),
        ])->assertRedirect();

        $position->refresh();

        self::assertSame('Gartenpflege Sommer', $position->getAttribute('description'));
        self::assertSame(123456, $position->getAttribute('amount_cent'));
        self::assertSame($kategorie->getKey(), $position->getAttribute('cost_category_id'));
        self::assertSame(ApportionmentStatus::UMLAGEFAEHIG, $position->getAttribute('apportionment_status'));
        self::assertSame(CostItemStatus::VORGESCHLAGEN, $position->getAttribute('status'));
    }

    /**
     * Grundsatz 8: Ein Eurobetrag wird ohne Zwischenschritt ueber float in Cent
     * umgerechnet. Die Werte sind so gewaehlt, dass eine Multiplikation mit 100
     * als binaerer Gleitkommawert nicht exakt ist.
     */
    #[DataProvider('betragsangaben')]
    public function test_eurobetraege_werden_exakt_in_cent_umgerechnet(string $eingabe, int $erwartetCent): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Unklare Leistung', null, 25000);
        $kategorie = $this->kategorie('GARTENPFLEGE');

        $this->actingAs($mandant['user'])->put(route('portal.pruefung.kosten.update', [
            'billingRun' => $lauf->getKey(),
            'costItem' => $position->getKey(),
        ]), [
            'description' => 'Gartenpflege Sommer',
            'betrag_euro' => $eingabe,
            'cost_category_id' => $kategorie->getKey(),
        ])->assertRedirect();

        $position->refresh();

        self::assertSame($erwartetCent, $position->getAttribute('amount_cent'));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function betragsangaben(): array
    {
        return [
            'einfacher Betrag' => ['300,00', 30000],
            'Tausendertrennzeichen' => ['8.235,70', 823570],
            'nicht exakt als float darstellbar' => ['1.234,29', 123429],
            'kleiner Restcent' => ['0,07', 7],
            'grosser Betrag' => ['99.999,99', 9999999],
            'negative Gutschrift' => ['-1.845,30', -184530],
            // B29, B38: Punkt nur bei genau drei Folgeziffern als Tausendertrennzeichen.
            'Punkt als Dezimaltrennzeichen' => ['1234.56', 123456],
            'Punkt als Dezimaltrennzeichen, zwei Vorkommastellen' => ['12.50', 1250],
            'Punkt als Dezimaltrennzeichen, drei Vorkommastellen' => ['150.00', 15000],
            'Punkt als Tausendertrennzeichen ohne Komma' => ['1.500', 150000],
            'Tausenderpunkt mit Suffix EUR' => ['1.200 EUR', 120000],
        ];
    }

    /**
     * B29, B38: Ein nicht auswertbarer Betrag ist ein Validierungsfehler. Er
     * wird weder geschaetzt noch gerundet noch still verworfen.
     *
     * @return array<string, array{string}>
     */
    public static function unleserlicheBetraege(): array
    {
        return [
            'Text' => ['etwa dreihundert'],
            'drei Nachkommastellen' => ['12,345'],
            'englische Tausenderschreibweise' => ['1,234.56'],
        ];
    }

    #[DataProvider('unleserlicheBetraege')]
    public function test_ein_unleserlicher_betrag_wird_nicht_geschaetzt(string $eingabe): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Unklare Leistung', null, 25000);

        $antwort = $this->actingAs($mandant['user'])->put(route('portal.pruefung.kosten.update', [
            'billingRun' => $lauf->getKey(),
            'costItem' => $position->getKey(),
        ]), [
            'description' => 'Geänderte Leistung',
            'betrag_euro' => $eingabe,
        ]);

        // Der Nutzer erhaelt eine Fehlermeldung statt einer Erfolgsmeldung.
        $antwort->assertSessionHasErrors('betrag_euro');
        self::assertStringContainsString(
            '1.234,56',
            (string) session('errors')?->first('betrag_euro')
        );

        $position->refresh();

        // Grundsatz 5: ein nicht lesbarer Wert wird nicht uebernommen und nicht
        // geschaetzt. Der bisherige Betrag und die Position bleiben unveraendert.
        self::assertSame(25000, $position->getAttribute('amount_cent'));
        self::assertSame('Unklare Leistung', $position->getAttribute('description'));
    }

    /**
     * B29: Ein unleserlicher Lohnanteil wird ebenfalls abgelehnt.
     */
    public function test_ein_unleserlicher_lohnanteil_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Hauswart', 'HAUSWART', 30000);

        $this->actingAs($mandant['user'])->put(route('portal.pruefung.kosten.update', [
            'billingRun' => $lauf->getKey(),
            'costItem' => $position->getKey(),
        ]), [
            'description' => 'Hauswart',
            'betrag_euro' => '300,00',
            'lohnanteil_euro' => '100,125',
        ])->assertSessionHasErrors('lohnanteil_euro');

        self::assertSame(30000, $position->refresh()->getAttribute('amount_cent'));
    }

    /**
     * B29: Eine manuelle Position mit unleserlichem Betrag wird nicht mit
     * 0,00 EUR angelegt.
     */
    public function test_manuelle_position_mit_unleserlichem_betrag_wird_nicht_angelegt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);
        $kategorie = $this->kategorie('GARTENPFLEGE');

        $this->actingAs($mandant['user'])->post(
            route('portal.pruefung.kosten.store', ['billingRun' => $lauf->getKey()]),
            [
                'description' => 'Gartenpflege Herbst',
                'betrag_euro' => 'ca. 450',
                'cost_category_id' => $kategorie->getKey(),
            ]
        )->assertSessionHasErrors('betrag_euro');

        self::assertSame(0, CostItem::query()->where('billing_run_id', $lauf->getKey())->count());
    }

    public function test_aufnahme_einer_nicht_umlagefaehigen_position_erfordert_begruendung(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Verwaltervergütung', 'VERWALTUNGSKOSTEN', 30000);

        $this->actingAs($mandant['user'])->put(route('portal.pruefung.kosten.update', [
            'billingRun' => $lauf->getKey(),
            'costItem' => $position->getKey(),
        ]), [
            'description' => 'Verwaltervergütung',
            'betrag_euro' => '300,00',
            'include_despite_status' => '1',
        ])->assertSessionHasErrors('apportionment_override_reason');

        $position->refresh();

        self::assertTrue($position->getAttribute('excluded_from_apportionment'));
    }

    public function test_aufnahme_mit_begruendung_wird_gespeichert(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Hauswart', 'HAUSWART', 30000);

        $this->actingAs($mandant['user'])->put(route('portal.pruefung.kosten.update', [
            'billingRun' => $lauf->getKey(),
            'costItem' => $position->getKey(),
        ]), [
            'description' => 'Hauswart',
            'betrag_euro' => '300,00',
            'include_despite_status' => '1',
            'apportionment_override_reason' => 'Im Mietvertrag ausdrücklich vereinbart.',
        ])->assertRedirect();

        $position->refresh();

        self::assertFalse($position->getAttribute('excluded_from_apportionment'));
        self::assertSame(
            'Im Mietvertrag ausdrücklich vereinbart.',
            $position->getAttribute('apportionment_override_reason')
        );
    }

    public function test_position_wird_direkt_einer_einheit_zugeordnet(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Gartenpflege', 'GARTENPFLEGE', 25000);

        $this->actingAs($mandant['user'])->post(route('portal.pruefung.kosten.einheit', [
            'billingRun' => $lauf->getKey(),
            'costItem' => $position->getKey(),
        ]), ['unit_id' => $mandant['unit']->getKey()])->assertRedirect();

        $position->refresh();

        self::assertSame($mandant['unit']->getKey(), $position->getAttribute('direct_unit_id'));
    }

    public function test_fremde_einheit_wird_nicht_zugeordnet(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        $lauf = $this->lauf($a['organization'], $a['property']);
        $position = $this->position($lauf, $a['organization'], 'Gartenpflege', 'GARTENPFLEGE', 25000);

        /** @var Unit $fremdeEinheit */
        $fremdeEinheit = $b['unit'];

        $this->actingAs($a['user'])->post(route('portal.pruefung.kosten.einheit', [
            'billingRun' => $lauf->getKey(),
            'costItem' => $position->getKey(),
        ]), ['unit_id' => $fremdeEinheit->getKey()])->assertRedirect();

        $position->refresh();

        self::assertNull($position->getAttribute('direct_unit_id'));
    }

    public function test_manuelle_position_wird_angelegt_und_ist_nicht_bestaetigt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);
        $kategorie = $this->kategorie('GARTENPFLEGE');

        $this->actingAs($mandant['user'])->post(
            route('portal.pruefung.kosten.store', ['billingRun' => $lauf->getKey()]),
            [
                'description' => 'Gartenpflege Herbst',
                'betrag_euro' => '450,00',
                'cost_category_id' => $kategorie->getKey(),
            ]
        )->assertRedirect();

        $position = CostItem::query()->where('billing_run_id', $lauf->getKey())->firstOrFail();

        self::assertSame(CostItemSource::MANUELL, $position->getAttribute('source'));
        self::assertSame(CostItemStatus::VORGESCHLAGEN, $position->getAttribute('status'));
        self::assertSame(45000, $position->getAttribute('amount_cent'));
        self::assertNull($position->getAttribute('confidence'));
    }

    public function test_sammelbestaetigung_ueberspringt_konfliktbehaftete_positionen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $sauber = $this->position($lauf, $mandant['organization'], 'Gartenpflege', 'GARTENPFLEGE', 25000);
        $nichtUmlagefaehig = $this->position($lauf, $mandant['organization'], 'Verwaltervergütung', 'VERWALTUNGSKOSTEN', 30000);
        $niedrigeKonfidenz = $this->position($lauf, $mandant['organization'], 'Allgemeinstrom', 'ALLGEMEINSTROM', 9000, [
            'confidence' => '0.4200',
        ]);
        $ohneKategorie = $this->position($lauf, $mandant['organization'], 'Unklare Leistung', null, 5000);
        $ausserhalb = $this->position($lauf, $mandant['organization'], 'Gartenpflege Vorjahr', 'GARTENPFLEGE', 12000, [
            'service_period_start' => '2024-01-01',
            'service_period_end' => '2024-12-31',
        ]);
        $dublette = $this->position($lauf, $mandant['organization'], 'Gartenpflege doppelt', 'GARTENPFLEGE', 25000, [
            'duplicate_of_cost_item_id' => $sauber->getKey(),
        ]);

        $this->actingAs($mandant['user'])->post(
            route('portal.pruefung.sammelbestaetigung', ['billingRun' => $lauf->getKey()])
        )->assertRedirect();

        self::assertSame(CostItemStatus::BESTAETIGT, $sauber->refresh()->getAttribute('status'));

        foreach ([$nichtUmlagefaehig, $niedrigeKonfidenz, $ohneKategorie, $ausserhalb, $dublette] as $position) {
            self::assertSame(
                CostItemStatus::VORGESCHLAGEN,
                $position->refresh()->getAttribute('status'),
                sprintf('Die Position "%s" darf nicht sammelbestätigt werden.', $position->getAttribute('description'))
            );
        }
    }

    public function test_sammelbestaetigung_akzeptiert_keine_fremde_kennung_aus_dem_formular(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $konflikt = $this->position($lauf, $mandant['organization'], 'Verwaltervergütung', 'VERWALTUNGSKOSTEN', 30000);

        $this->actingAs($mandant['user'])->post(
            route('portal.pruefung.sammelbestaetigung', ['billingRun' => $lauf->getKey()]),
            ['kostenpositionen' => [(string) $konflikt->getKey()]]
        )->assertRedirect();

        self::assertSame(CostItemStatus::VORGESCHLAGEN, $konflikt->refresh()->getAttribute('status'));
    }

    public function test_weiter_ist_gesperrt_solange_positionen_offen_sind(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->position($lauf, $mandant['organization'], 'Gartenpflege', 'GARTENPFLEGE', 25000);

        $antwort = $this->actingAs($mandant['user'])->post(
            route('portal.pruefung.weiter', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertRedirect(route('portal.pruefung.kosten', ['billingRun' => $lauf->getKey()]));
        $antwort->assertSessionHasErrors('weiter');

        self::assertFalse(app(ReviewGate::class)->mayProceed($lauf));
    }

    public function test_weiter_ist_moeglich_wenn_alle_positionen_entschieden_sind(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Gartenpflege', 'GARTENPFLEGE', 25000);
        $position->forceFill(['status' => CostItemStatus::BESTAETIGT, 'confirmed_at' => now()])->save();

        self::assertTrue(app(ReviewGate::class)->mayProceed($lauf));

        $this->actingAs($mandant['user'])->post(
            route('portal.pruefung.weiter', ['billingRun' => $lauf->getKey()])
        )->assertRedirect(route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]));
    }

    public function test_weiter_ist_gesperrt_bei_offenem_blocker(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $position = $this->position($lauf, $mandant['organization'], 'Gartenpflege', 'GARTENPFLEGE', 25000);
        $position->forceFill(['status' => CostItemStatus::BESTAETIGT, 'confirmed_at' => now()])->save();

        $dokument = $this->dokument($lauf, DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, 'Unterlage WEG');
        $this->felder($dokument, [
            'einheitsbezeichnung' => 'Wohnung 3',
            'hausgeldvorauszahlungen_cent' => 360000,
            'kostenaufschluesselung_vorhanden' => false,
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        self::assertFalse(app(ReviewGate::class)->mayProceed($lauf));
    }

    public function test_uebersicht_trennt_umlagefaehige_und_ausgeschlossene_summen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->position($lauf, $mandant['organization'], 'Gartenpflege', 'GARTENPFLEGE', 25000);
        $this->position($lauf, $mandant['organization'], 'Verwaltervergütung', 'VERWALTUNGSKOSTEN', 30000);

        $uebersicht = app(CostReviewPresenter::class)->overview($lauf);

        self::assertSame(25000, $uebersicht->apportionableSumCent);
        self::assertSame(30000, $uebersicht->excludedSumCent);
        self::assertSame('250,00 EUR', $uebersicht->apportionableSumLabel);
        self::assertFalse($uebersicht->canProceed);
        self::assertNotNull($uebersicht->blockedReason);
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    private function position(
        BillingRun $lauf,
        Organization $organisation,
        string $bezeichnung,
        ?string $kategoriecode,
        int $betrag,
        array $attribute = [],
    ): CostItem {
        $kategorie = $kategoriecode === null ? null : $this->kategorie($kategoriecode);

        $status = $kategorie?->getAttribute('apportionment_status');

        /** @var CostItem $position */
        $position = CostItem::factory()->create(array_merge([
            'organization_id' => $organisation->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'cost_category_id' => $kategorie?->getKey(),
            'document_id' => null,
            'description' => $bezeichnung,
            'supplier_name' => 'Lieferant Beispiel',
            'invoice_number' => null,
            'amount_cent' => $betrag,
            'document_date' => '2025-06-30',
            'service_period_start' => '2025-01-01',
            'service_period_end' => '2025-12-31',
            'status' => CostItemStatus::VORGESCHLAGEN,
            'apportionment_status' => $status instanceof ApportionmentStatus ? $status : ApportionmentStatus::PRUEFPFLICHTIG,
            'excluded_from_apportionment' => $status === ApportionmentStatus::NICHT_UMLAGEFAEHIG,
            'confidence' => '0.9600',
            'source_page' => 1,
        ], $attribute));

        return $position;
    }

    private function gartenrechnung(
        BillingRun $lauf,
        Organization $organisation,
        string $bezeichnung,
        string $belegnummer,
        int $betrag,
    ): CostItem {
        return $this->position($lauf, $organisation, 'Gartenpflege '.$bezeichnung, 'GARTENPFLEGE', $betrag, [
            'invoice_number' => $belegnummer,
        ]);
    }

    private function kategorie(string $code): CostCategory
    {
        /** @var CostCategory $kategorie */
        $kategorie = CostCategory::query()->where('code', $code)->firstOrFail();

        return $kategorie;
    }
}
