<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Application\Reconciliation\BillingModeAdvisor;
use App\Application\Reconciliation\ReconcileBillingRun;
use App\Application\Reconciliation\SwitchBillingMode;
use App\Enums\BillingMode;
use App\Enums\CostItemStatus;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\ExtractedField;
use App\Models\Unit;
use Tests\Feature\Review\ReviewTestCase;

/**
 * Automatische Wegerkennung und Wegwechsel (Abschnitt 5.3).
 */
final class BillingModeTest extends ReviewTestCase
{
    public function test_weg_einzelabrechnung_und_grundsteuer_schlagen_die_schnellabrechnung_vor(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property'], ['mode' => BillingMode::FULL_PROPERTY]);

        $this->dokument($lauf, DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, 'Unterlage WEG');
        $this->dokument($lauf, DocumentType::GRUNDSTEUERBESCHEID, 'Unterlage Grundsteuer');

        $vorschlag = app(BillingModeAdvisor::class)->suggest($lauf);

        self::assertSame(BillingMode::QUICK_CONDO, $vorschlag->suggested);
        self::assertTrue($vorschlag->differsFromCurrent());
        self::assertNotSame([], $vorschlag->reasons);
    }

    public function test_viele_einzelbelege_und_mehrere_einheiten_schlagen_die_objektabrechnung_vor(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        Unit::factory()->count(3)->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
        ]);

        $this->dokument($lauf, DocumentType::WASSER_ABWASSERBESCHEID, 'Unterlage Wasser');
        $this->dokument($lauf, DocumentType::MUELLGEBUEHRENBESCHEID, 'Unterlage Müll');
        $this->dokument($lauf, DocumentType::VERSICHERUNGSRECHNUNG, 'Unterlage Versicherung');
        $this->dokument($lauf, DocumentType::MIETER_EINHEITENLISTE, 'Unterlage Mieterliste');

        $vorschlag = app(BillingModeAdvisor::class)->suggest($lauf);

        self::assertSame(BillingMode::FULL_PROPERTY, $vorschlag->suggested);
        self::assertTrue($vorschlag->confident);
    }

    public function test_ohne_unterlagen_bleibt_der_gewaehlte_weg_bestehen(): void
    {
        $mandant = $this->mandant();
        $mandant['unit']->delete();

        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $vorschlag = app(BillingModeAdvisor::class)->suggest($lauf);

        self::assertSame(BillingMode::QUICK_CONDO, $vorschlag->suggested);
        self::assertFalse($vorschlag->confident);
    }

    public function test_wegwechsel_behaelt_die_ausgelesenen_inhaltsdaten(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, 'Unterlage WEG');
        $this->felder($dokument, [
            'einheitsbezeichnung' => 'Wohnung 3',
            'abrechnungszeitraum_von' => '2025-01-01',
            'abrechnungszeitraum_bis' => '2025-12-31',
            'kostenarten[0].bezeichnung' => 'Gartenpflege',
            'kostenarten[0].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[0].anteil_einheit_cent' => 24000,
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $felderVorher = ExtractedField::query()->where('document_id', $dokument->getKey())->count();

        self::assertGreaterThan(0, $felderVorher);

        app(SwitchBillingMode::class)->switch($lauf, BillingMode::FULL_PROPERTY);

        self::assertSame(
            $felderVorher,
            ExtractedField::query()->where('document_id', $dokument->getKey())->count()
        );

        self::assertSame(
            BillingMode::FULL_PROPERTY,
            BillingRun::query()->whereKey($lauf->getKey())->firstOrFail()->getAttribute('mode')
        );

        // Die Positionen werden neu eingeordnet, nicht geloescht.
        self::assertSame(1, CostItem::query()->where('billing_run_id', $lauf->getKey())->count());
    }

    public function test_wegwechsel_behaelt_bestaetigte_positionen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage Rechnung');
        $this->felder($dokument, [
            'belegdatum' => '2025-03-03',
            'gesamtbetrag_brutto_cent' => 10000,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $position = CostItem::query()->where('billing_run_id', $lauf->getKey())->firstOrFail();
        $position->forceFill(['status' => CostItemStatus::BESTAETIGT, 'confirmed_at' => now()])->save();

        app(SwitchBillingMode::class)->switch($lauf, BillingMode::FULL_PROPERTY);

        self::assertSame(
            CostItemStatus::BESTAETIGT,
            CostItem::query()->whereKey($position->getKey())->firstOrFail()->getAttribute('status')
        );
    }

    public function test_wechsel_auf_denselben_weg_aendert_nichts(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        app(SwitchBillingMode::class)->switch($lauf, BillingMode::QUICK_CONDO);

        self::assertSame(
            BillingMode::QUICK_CONDO,
            BillingRun::query()->whereKey($lauf->getKey())->firstOrFail()->getAttribute('mode')
        );
    }

    public function test_route_wechselt_den_weg_und_meldet_den_datenerhalt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, 'Unterlage WEG');
        $this->felder($dokument, [
            'einheitsbezeichnung' => 'Wohnung 3',
            'kostenarten[0].bezeichnung' => 'Gartenpflege',
            'kostenarten[0].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[0].anteil_einheit_cent' => 24000,
        ]);

        $antwort = $this->actingAs($mandant['user'])->put(
            route('portal.pruefung.weg.update', ['billingRun' => $lauf->getKey()]),
            ['mode' => BillingMode::FULL_PROPERTY->value]
        );

        $antwort->assertRedirect(route('portal.pruefung.weg.edit', ['billingRun' => $lauf->getKey()]));
        $antwort->assertSessionHas('status');

        self::assertGreaterThan(0, ExtractedField::query()->where('document_id', $dokument->getKey())->count());
    }

    public function test_seite_zum_abrechnungsweg_zeigt_den_vorschlag(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->dokument($lauf, DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, 'Unterlage WEG');

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.pruefung.weg.edit', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Schnellabrechnung Eigentumswohnung');
        $antwort->assertSee('löscht keine ausgelesenen Inhaltsdaten', false);
    }
}
