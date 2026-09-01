<?php

declare(strict_types=1);

namespace Tests\Feature\Review;

use App\Enums\CostItemStatus;
use App\Models\CostItem;

/**
 * Mandantentrennung fuer jede neue Route dieses Arbeitspakets.
 *
 * Nutzer A darf keine Entitaet von Nutzer B lesen oder aendern, auch nicht bei
 * Kenntnis der Kennung. Die Antwort ist 403 oder 404 und verraet nichts ueber
 * die Existenz des fremden Datensatzes.
 */
final class ReviewTenantIsolationTest extends ReviewTestCase
{
    public function test_lesende_routen_verweigern_fremde_abrechnungslaeufe(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        $fremderLauf = $this->lauf($b['organization'], $b['property']);

        $routen = [
            route('portal.pruefung.analyse', ['billingRun' => $fremderLauf->getKey()]),
            route('portal.pruefung.analyse.status', ['billingRun' => $fremderLauf->getKey()]),
            route('portal.pruefung.kosten', ['billingRun' => $fremderLauf->getKey()]),
            route('portal.pruefung.heizkosten', ['billingRun' => $fremderLauf->getKey()]),
            route('portal.pruefung.weg.edit', ['billingRun' => $fremderLauf->getKey()]),
        ];

        foreach ($routen as $url) {
            $antwort = $this->actingAs($a['user'])->get($url);

            self::assertContains(
                $antwort->getStatusCode(),
                [403, 404],
                sprintf('Die Route %s gibt einen fremden Abrechnungslauf frei.', $url)
            );
        }
    }

    public function test_schreibende_routen_verweigern_fremde_abrechnungslaeufe(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        $fremderLauf = $this->lauf($b['organization'], $b['property']);

        /** @var CostItem $fremdePosition */
        $fremdePosition = CostItem::factory()->create([
            'organization_id' => $b['organization']->getKey(),
            'billing_run_id' => $fremderLauf->getKey(),
            'document_id' => null,
        ]);

        $aufrufe = [
            ['post', route('portal.pruefung.zuordnen', ['billingRun' => $fremderLauf->getKey()]), []],
            ['post', route('portal.pruefung.sammelbestaetigung', ['billingRun' => $fremderLauf->getKey()]), []],
            ['post', route('portal.pruefung.weiter', ['billingRun' => $fremderLauf->getKey()]), []],
            ['post', route('portal.pruefung.kosten.store', ['billingRun' => $fremderLauf->getKey()]), [
                'description' => 'Fremde Position',
                'betrag_euro' => '100,00',
            ]],
            ['post', route('portal.pruefung.kosten.bestaetigen', [
                'billingRun' => $fremderLauf->getKey(),
                'costItem' => $fremdePosition->getKey(),
            ]), []],
            ['post', route('portal.pruefung.kosten.verwerfen', [
                'billingRun' => $fremderLauf->getKey(),
                'costItem' => $fremdePosition->getKey(),
            ]), []],
            ['post', route('portal.pruefung.kosten.ausschliessen', [
                'billingRun' => $fremderLauf->getKey(),
                'costItem' => $fremdePosition->getKey(),
            ]), []],
            ['post', route('portal.pruefung.kosten.einheit', [
                'billingRun' => $fremderLauf->getKey(),
                'costItem' => $fremdePosition->getKey(),
            ]), []],
            ['put', route('portal.pruefung.kosten.update', [
                'billingRun' => $fremderLauf->getKey(),
                'costItem' => $fremdePosition->getKey(),
            ]), ['description' => 'Geändert', 'betrag_euro' => '100,00']],
            ['put', route('portal.pruefung.weg.update', ['billingRun' => $fremderLauf->getKey()]), [
                'mode' => 'FULL_PROPERTY',
            ]],
        ];

        foreach ($aufrufe as [$methode, $url, $daten]) {
            $antwort = $this->actingAs($a['user'])->$methode($url, $daten);

            self::assertContains(
                $antwort->getStatusCode(),
                [403, 404],
                sprintf('Die Route %s %s gibt einen fremden Abrechnungslauf frei.', strtoupper($methode), $url)
            );
        }

        self::assertSame(CostItemStatus::VORGESCHLAGEN, $fremdePosition->refresh()->getAttribute('status'));
    }

    public function test_fremde_kostenposition_im_eigenen_lauf_wird_nicht_gefunden(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        $eigenerLauf = $this->lauf($a['organization'], $a['property']);
        $fremderLauf = $this->lauf($b['organization'], $b['property']);

        /** @var CostItem $fremdePosition */
        $fremdePosition = CostItem::factory()->create([
            'organization_id' => $b['organization']->getKey(),
            'billing_run_id' => $fremderLauf->getKey(),
            'document_id' => null,
        ]);

        $this->actingAs($a['user'])->post(route('portal.pruefung.kosten.bestaetigen', [
            'billingRun' => $eigenerLauf->getKey(),
            'costItem' => $fremdePosition->getKey(),
        ]))->assertNotFound();

        self::assertSame(CostItemStatus::VORGESCHLAGEN, $fremdePosition->refresh()->getAttribute('status'));
    }

    public function test_ohne_anmeldung_ist_kein_zugriff_moeglich(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->get(route('portal.pruefung.kosten', ['billingRun' => $lauf->getKey()]))
            ->assertRedirect();
    }
}
