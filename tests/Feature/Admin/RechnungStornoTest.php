<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;

/**
 * Storno einer Leistungsrechnung ueber den Adminbereich
 * (Masterprompt 15.2, 20).
 *
 * VERBINDLICH: Ein Storno verlangt die Freigabe der Geschaeftsfuehrung und eine
 * Begruendung, erzeugt eine eigene Stornorechnung mit Referenz und
 * ueberschreibt nichts.
 */
final class RechnungStornoTest extends AdminTestCase
{
    private function rechnung(): Invoice
    {
        /** @var Invoice $rechnung */
        $rechnung = Invoice::factory()->create([
            'number' => 'NK-2026-000041',
            'status' => InvoiceStatus::BEZAHLT,
        ]);

        return $rechnung;
    }

    public function test_die_stornoseite_nennt_die_erforderliche_freigabe(): void
    {
        $this->bestaetigteBetreiberstammdaten();

        $antwort = $this->actingAs($this->interneKennung())
            ->get('/admin/rechnungen/'.$this->rechnung()->getKey().'/storno');

        $antwort->assertOk();
        $antwort->assertSee('Freigabe der Geschäftsführung erforderlich');
        $antwort->assertSee('Begründung');
    }

    public function test_ohne_begruendung_wird_kein_storno_erzeugt(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $rechnung = $this->rechnung();

        $this->actingAs($this->interneKennung())
            ->post('/admin/rechnungen/'.$rechnung->getKey().'/storno', [
                'freigabe_geschaeftsfuehrung' => '1',
            ])
            ->assertSessionHasErrors('grund');

        self::assertSame(0, Invoice::query()->whereNotNull('cancels_invoice_id')->count());
    }

    public function test_ohne_freigabe_der_geschaeftsfuehrung_wird_kein_storno_erzeugt(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $rechnung = $this->rechnung();

        $this->actingAs($this->interneKennung())
            ->post('/admin/rechnungen/'.$rechnung->getKey().'/storno', [
                'grund' => 'Korrektur nach Rücksprache mit dem Kunden.',
            ])
            ->assertSessionHasErrors('freigabe_geschaeftsfuehrung');

        self::assertSame(0, Invoice::query()->whereNotNull('cancels_invoice_id')->count());
    }

    public function test_das_storno_erzeugt_eine_eigene_rechnung_mit_referenz_und_ueberschreibt_nichts(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $rechnung = $this->rechnung();

        $originalNummer = (string) $rechnung->getAttribute('number');
        $originalBrutto = (int) $rechnung->getAttribute('gross_cent');

        $this->actingAs($this->interneKennung())
            ->post('/admin/rechnungen/'.$rechnung->getKey().'/storno', [
                'grund' => 'Korrektur nach Rücksprache mit dem Kunden.',
                'freigabe_geschaeftsfuehrung' => '1',
            ])
            ->assertRedirect('/admin/zahlungen');

        /** @var Invoice $storno */
        $storno = Invoice::query()->where('cancels_invoice_id', $rechnung->getKey())->firstOrFail();

        self::assertNotSame($originalNummer, (string) $storno->getAttribute('number'));
        self::assertSame(InvoiceStatus::STORNORECHNUNG, $storno->getAttribute('status'));
        self::assertSame(-1 * $originalBrutto, (int) $storno->getAttribute('gross_cent'));

        /** @var Invoice $unveraendert */
        $unveraendert = Invoice::query()->findOrFail($rechnung->getKey());

        self::assertSame($originalNummer, (string) $unveraendert->getAttribute('number'));
        self::assertSame($originalBrutto, (int) $unveraendert->getAttribute('gross_cent'));
        self::assertSame(InvoiceStatus::STORNIERT, $unveraendert->getAttribute('status'));
    }

    public function test_die_begruendung_und_die_freigabe_werden_protokolliert(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $rechnung = $this->rechnung();
        $grund = 'Storno nach Freigabe der Geschäftsführung wegen falscher Anzahl.';

        $this->actingAs($this->interneKennung())
            ->post('/admin/rechnungen/'.$rechnung->getKey().'/storno', [
                'grund' => $grund,
                'freigabe_geschaeftsfuehrung' => '1',
            ])
            ->assertRedirect('/admin/zahlungen');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.invoice.cancellation_requested',
            'subject_id' => $rechnung->getKey(),
            'reason' => $grund,
        ]);
    }

    public function test_ein_zweites_storno_erzeugt_keine_zweite_stornorechnung(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $rechnung = $this->rechnung();

        for ($versuch = 0; $versuch < 2; $versuch++) {
            $this->actingAs($this->interneKennung())
                ->post('/admin/rechnungen/'.$rechnung->getKey().'/storno', [
                    'grund' => 'Korrektur nach Rücksprache mit dem Kunden.',
                    'freigabe_geschaeftsfuehrung' => '1',
                ]);
        }

        self::assertSame(
            1,
            Invoice::query()->where('cancels_invoice_id', $rechnung->getKey())->count(),
        );
    }

    public function test_ohne_bestaetigte_betreiberstammdaten_wird_kein_storno_erzeugt(): void
    {
        $rechnung = $this->rechnung();

        $this->actingAs($this->interneKennung())
            ->post('/admin/rechnungen/'.$rechnung->getKey().'/storno', [
                'grund' => 'Korrektur nach Rücksprache mit dem Kunden.',
                'freigabe_geschaeftsfuehrung' => '1',
            ])
            ->assertRedirect('/admin/rechnungen/'.$rechnung->getKey().'/storno');

        self::assertSame(0, Invoice::query()->whereNotNull('cancels_invoice_id')->count());
    }

    public function test_der_rechnungsnummernkreis_ist_einsehbar(): void
    {
        Invoice::factory()->create(['number' => 'NK-'.now()->format('Y').'-000001']);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/zahlungen');

        $antwort->assertOk();
        $antwort->assertSee('Rechnungsnummernkreis');
        $antwort->assertSee('Nächste Nummer');
    }
}
