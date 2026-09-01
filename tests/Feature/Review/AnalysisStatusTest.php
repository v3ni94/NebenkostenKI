<?php

declare(strict_types=1);

namespace Tests\Feature\Review;

use App\Application\Review\AnalysisProgressReporter;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Models\CostItem;
use App\Models\Unit;

/**
 * Statusseite der automatischen Analyse (Schritt 3).
 */
final class AnalysisStatusTest extends ReviewTestCase
{
    public function test_statusseite_nennt_konkrete_fortschrittsangaben(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        Unit::factory()->count(2)->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
        ]);

        $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 01');
        $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 02');

        CostItem::factory()->count(3)->create([
            'organization_id' => $mandant['organization']->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'document_id' => null,
        ]);

        $bericht = app(AnalysisProgressReporter::class)->report($lauf);

        self::assertSame(2, $bericht->documentsTotal);
        self::assertSame(2, $bericht->documentsEvaluated);
        self::assertSame(3, $bericht->unitsRecognized);
        self::assertSame(3, $bericht->costItemsAssigned);
        self::assertSame(100, $bericht->percent());
        self::assertTrue($bericht->complete);

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.pruefung.analyse', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('2 von 2 Unterlagen ausgewertet');
        $antwort->assertSee('3 Einheiten erkannt');
        $antwort->assertSee('3 Kostenpositionen zugeordnet');
        $antwort->assertSee('Angaben müssen geprüft werden', false);
    }

    public function test_statusseite_nennt_keine_providernamen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 01');

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.pruefung.analyse', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertDontSee('OpenAI');
        $antwort->assertDontSee('Anthropic');
        $antwort->assertDontSee('Claude');
        $antwort->assertDontSee('GPT');
    }

    public function test_statusseite_erklaert_die_loeschung_der_originale(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.pruefung.analyse', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('nach der Auswertung gelöscht', false);
    }

    public function test_fehlgeschlagene_unterlagen_werden_verstaendlich_gemeldet(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 01', [
            'processing_status' => DocumentProcessingStatus::FEHLGESCHLAGEN,
        ]);

        $bericht = app(AnalysisProgressReporter::class)->report($lauf);

        self::assertSame(1, $bericht->documentsFailed);

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.pruefung.analyse', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertSee('konnten nicht ausgewertet werden', false);
    }

    public function test_statusabruf_liefert_json_ohne_technische_details(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 01');

        $antwort = $this->actingAs($mandant['user'])->getJson(
            route('portal.pruefung.analyse.status', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertJsonStructure([
            'unterlagen_gesamt',
            'unterlagen_geprueft',
            'einheiten_erkannt',
            'kostenpositionen',
            'offene_pruefungen',
            'prozent',
            'abgeschlossen',
            'meldungen',
        ]);
    }

    public function test_zuordnung_kann_ueber_die_route_gestartet_werden(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 01');
        $this->felder($dokument, [
            'belegdatum' => '2025-06-30',
            'gesamtbetrag_brutto_cent' => 25000,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        $antwort = $this->actingAs($mandant['user'])->post(
            route('portal.pruefung.zuordnen', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertRedirect(route('portal.pruefung.kosten', ['billingRun' => $lauf->getKey()]));
        $antwort->assertSessionHas('status');

        self::assertSame(1, CostItem::query()->where('billing_run_id', $lauf->getKey())->count());
    }

    public function test_heizkostenseite_zeigt_die_matrix(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::HEIZKOSTENABRECHNUNG, 'Unterlage Heizkosten');
        $this->felder($dokument, [
            'abrechnungsdienst' => 'Abrechnungsdienst Beispiel',
            'abrechnungszeitraum_von' => '2025-01-01',
            'abrechnungszeitraum_bis' => '2025-12-31',
            'gesamtkosten_summe_cent' => 90000,
            'einheiten[0].einheitsbezeichnung' => 'Wohnung 3',
            'einheiten[0].summe_cent' => 90000,
            'einheiten[0].nutzungszeitraum_von' => '2025-01-01',
            'einheiten[0].nutzungszeitraum_bis' => '2025-12-31',
        ]);

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.pruefung.heizkosten', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Externe Heizkostenabrechnung');
        $antwort->assertSee('900,00 EUR');
        $antwort->assertSee('01.01.2025 bis 31.12.2025');
        $antwort->assertSee('Mieteranteil, Direktzuordnung');
    }
}
