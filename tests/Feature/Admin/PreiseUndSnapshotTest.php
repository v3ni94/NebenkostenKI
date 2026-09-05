<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Application\Payment\CalculatePrice;
use App\Models\CalculationSnapshot;

/**
 * Preis und Umsatzsteuer (Masterprompt 1.3, 20).
 *
 * VERBINDLICH: Eine Adminaenderung an Preis, Regel oder Prompt wirkt
 * ausschliesslich auf NEUE Berechnungsstaende. Ein bestehender Snapshot bleibt
 * unveraendert und reproduzierbar.
 */
final class PreiseUndSnapshotTest extends AdminTestCase
{
    public function test_die_seite_zeigt_preis_steuersatz_und_korridor(): void
    {
        $antwort = $this->actingAs($this->interneKennung())->get('/admin/preise');

        $antwort->assertOk();
        $antwort->assertSee('24,90 EUR');
        $antwort->assertSee('19 Prozent');
        $antwort->assertSee('20,00 EUR');
        $antwort->assertSee('30,00 EUR');
    }

    public function test_ein_preis_unterhalb_des_korridors_wird_abgelehnt(): void
    {
        $this->actingAs($this->interneKennung())
            ->post('/admin/preise/pruefen', ['preis_brutto_cent' => 1500])
            ->assertSessionHasErrors('preis_brutto_cent');
    }

    public function test_ein_preis_oberhalb_des_korridors_wird_abgelehnt(): void
    {
        $this->actingAs($this->interneKennung())
            ->post('/admin/preise/pruefen', ['preis_brutto_cent' => 4900])
            ->assertSessionHasErrors('preis_brutto_cent');
    }

    public function test_ein_preis_im_korridor_wird_angenommen_und_protokolliert(): void
    {
        $this->actingAs($this->interneKennung())
            ->post('/admin/preise/pruefen', ['preis_brutto_cent' => 2700])
            ->assertRedirect('/admin/preise')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.pricing.checked',
        ]);
    }

    public function test_eine_preisaenderung_wirkt_nicht_auf_einen_bestehenden_snapshot(): void
    {
        /** @var CalculationSnapshot $snapshot */
        $snapshot = CalculationSnapshot::factory()->create();

        $vorherHash = (string) $snapshot->getAttribute('hash');
        $vorherEingabe = $snapshot->getAttribute('input');
        $vorherErgebnis = $snapshot->getAttribute('result');
        $vorherRegelstand = (string) $snapshot->getAttribute('ruleset_version');
        $vorherDomain = (string) $snapshot->getAttribute('domain_version');
        $vorherAktualisiert = (string) $snapshot->getAttribute('updated_at');

        // Adminhandlung: geplanten Preis pruefen.
        $this->actingAs($this->interneKennung())
            ->post('/admin/preise/pruefen', ['preis_brutto_cent' => 2900])
            ->assertRedirect('/admin/preise');

        // Und die Konfiguration selbst aendern, wie es die Serverumgebung tut.
        config()->set('smartabrechnen.pricing.per_statement_gross_cent', 2900);

        /** @var CalculationSnapshot $frisch */
        $frisch = CalculationSnapshot::query()->findOrFail($snapshot->getKey());

        self::assertSame($vorherHash, (string) $frisch->getAttribute('hash'));
        self::assertSame($vorherEingabe, $frisch->getAttribute('input'));
        self::assertSame($vorherErgebnis, $frisch->getAttribute('result'));
        self::assertSame($vorherRegelstand, (string) $frisch->getAttribute('ruleset_version'));
        self::assertSame($vorherDomain, (string) $frisch->getAttribute('domain_version'));
        self::assertSame($vorherAktualisiert, (string) $frisch->getAttribute('updated_at'));
    }

    public function test_ein_neuer_preis_gilt_nur_fuer_einen_neuen_preisstand(): void
    {
        $vorher = app(CalculatePrice::class)->estimate(2);

        self::assertSame(4980, $vorher->grossCent);

        config()->set('smartabrechnen.pricing.per_statement_gross_cent', 2900);

        $nachher = app(CalculatePrice::class)->estimate(2);

        self::assertSame(5800, $nachher->grossCent);

        // Der alte Preisstand ist unveraendert, er wurde nicht nachgerechnet.
        self::assertSame(4980, $vorher->grossCent);
    }

    public function test_die_seite_nennt_die_anzahl_der_geschuetzten_berechnungsstaende(): void
    {
        CalculationSnapshot::factory()->count(2)->create();

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/preise');

        $antwort->assertOk();
        $antwort->assertSee('Geschützte Berechnungsstände');
    }
}
