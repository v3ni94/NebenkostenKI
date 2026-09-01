<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Application\Payment\Dto\FinalViewBundle;
use App\Application\Payment\FinalizeBillingRun;
use App\Models\BillingRun;
use App\Models\GeneratedDocument;
use App\Models\Payment;
use Tests\Feature\Pdf\PdfFixtures;

/**
 * Mandantentrennung aller neuen Routen (Abschnitt 19, ARCHITECTURE.md T1).
 *
 * Ein fremder Datensatz fuehrt zu 404 und nicht zu 403: ein 403 wuerde
 * bestaetigen, dass die Kennung existiert.
 */
final class PaymentTenantIsolationTest extends PaymentTestCase
{
    public function test_die_zahlungsseite_eines_fremden_laufs_ist_nicht_auffindbar(): void
    {
        $eigen = $this->vorschaubereiterLauf(1);
        $fremd = $this->vorschaubereiterLauf(1);

        $this->actingAs($eigen['user'])
            ->get(route('portal.checkout.show', ['billingRun' => $fremd['billingRun']->getKey()]))
            ->assertNotFound();
    }

    public function test_der_checkout_eines_fremden_laufs_ist_nicht_auffindbar(): void
    {
        $eigen = $this->vorschaubereiterLauf(1);
        $fremd = $this->vorschaubereiterLauf(1);

        $this->actingAs($eigen['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $fremd['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertNotFound();

        self::assertSame(0, Payment::query()->count());
    }

    public function test_der_abbruch_eines_fremden_laufs_ist_nicht_auffindbar(): void
    {
        $eigen = $this->vorschaubereiterLauf(1);
        $fremd = $this->vorschaubereiterLauf(1);

        $this->actingAs($eigen['user'])
            ->delete(route('portal.checkout.destroy', ['billingRun' => $fremd['billingRun']->getKey()]))
            ->assertNotFound();
    }

    public function test_die_rueckleitungen_eines_fremden_laufs_sind_nicht_auffindbar(): void
    {
        $eigen = $this->vorschaubereiterLauf(1);
        $fremd = $this->vorschaubereiterLauf(1);

        $this->actingAs($eigen['user'])
            ->get(route('portal.checkout.erfolg', ['billingRun' => $fremd['billingRun']->getKey()]))
            ->assertNotFound();

        $this->actingAs($eigen['user'])
            ->get(route('portal.checkout.abbruch', ['billingRun' => $fremd['billingRun']->getKey()]))
            ->assertNotFound();
    }

    public function test_der_abschluss_eines_fremden_laufs_ist_nicht_auffindbar(): void
    {
        $eigen = $this->vorschaubereiterLauf(1);
        $fremd = $this->vorschaubereiterLauf(1);

        $this->actingAs($eigen['user'])
            ->get(route('portal.abschluss.show', ['billingRun' => $fremd['billingRun']->getKey()]))
            ->assertNotFound();
    }

    public function test_ein_fremdes_finales_dokument_ist_nicht_abrufbar(): void
    {
        $this->bestaetigteBetreiberstammdaten();

        $eigen = $this->vorschaubereiterLauf(1);
        $fremd = $this->vorschaubereiterLauf(1);

        $this->bezahlterLauf($fremd['billingRun'], 2490, 1);
        $this->bindeFinalDocumentViews(new FinalViewBundle([PdfFixtures::statementView()], [null]));

        app(FinalizeBillingRun::class)(
            BillingRun::query()->findOrFail($fremd['billingRun']->getKey()),
            $fremd['user'],
        );

        /** @var GeneratedDocument $fremdesDokument */
        $fremdesDokument = GeneratedDocument::query()
            ->where('billing_run_id', $fremd['billingRun']->getKey())
            ->firstOrFail();

        $this->actingAs($eigen['user'])
            ->get(route('portal.downloads.stream', ['generatedDocument' => $fremdesDokument->getKey()]))
            ->assertNotFound();

        // Der eigene Mandant sieht das fremde Dokument auch nicht in der Liste.
        $this->actingAs($eigen['user'])
            ->get(route('portal.abschluss.show', ['billingRun' => $eigen['billingRun']->getKey()]))
            ->assertOk()
            ->assertDontSee((string) $fremdesDokument->getKey());
    }

    public function test_ein_gast_erreicht_die_zahlungsseite_nicht(): void
    {
        $daten = $this->vorschaubereiterLauf(1);

        $this->get(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->assertRedirect(route('login'));
    }
}
