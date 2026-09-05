<?php

declare(strict_types=1);

namespace Tests\Feature\Heating;

use App\Models\HeatingStatement;

/**
 * Mandantentrennung fuer jede neue Route der manuellen Heizkostenerfassung.
 *
 * Nutzer A darf keine Entitaet von Nutzer B lesen oder aendern, auch nicht bei
 * Kenntnis der Kennung. Die Antwort ist 403 oder 404 und verraet nichts ueber
 * die Existenz des fremden Datensatzes.
 */
final class ManualHeatingTenantIsolationTest extends ManualHeatingTestCase
{
    public function test_die_eingabemaske_verweigert_fremde_abrechnungslaeufe(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        $fremderLauf = $this->lauf($b['organization'], $b['property']);

        $antwort = $this->actingAs($a['user'])->get(
            route('portal.pruefung.heizkosten.erfassung', ['billingRun' => $fremderLauf->getKey()])
        );

        self::assertContains($antwort->getStatusCode(), [403, 404]);
    }

    public function test_das_speichern_verweigert_fremde_abrechnungslaeufe(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        $fremderLauf = $this->lauf($b['organization'], $b['property']);

        $antwort = $this->actingAs($a['user'])->post(
            route('portal.pruefung.heizkosten.speichern', ['billingRun' => $fremderLauf->getKey()]),
            [
                'einheiten' => [
                    (string) $b['unit']->getKey() => ['heizung' => '100,00'],
                ],
            ]
        );

        self::assertContains($antwort->getStatusCode(), [403, 404]);
        self::assertSame(0, HeatingStatement::query()->count());
    }

    public function test_ohne_anmeldung_ist_die_erfassung_nicht_erreichbar(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $antwort = $this->get(route('portal.pruefung.heizkosten.erfassung', ['billingRun' => $lauf->getKey()]));

        self::assertContains($antwort->getStatusCode(), [302, 401, 403, 404]);
        self::assertSame(0, HeatingStatement::query()->count());
    }
}
