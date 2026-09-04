<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Application\Payment\Dto\FinalViewBundle;
use App\Application\Payment\FinalizeBillingRun;
use App\Enums\BillingRunStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Models\BillingRun;
use App\Models\GeneratedDocument;
use App\Models\User;
use Tests\Feature\Pdf\PdfFixtures;

/**
 * Schritt 12: Downloadbereich (Abschnitt 9 Schritt 12, 19).
 */
final class CompletionAreaTest extends PaymentTestCase
{
    /**
     * @return array{lauf: BillingRun, nutzer: User}
     */
    private function finalisierterLauf(): array
    {
        $this->bestaetigteBetreiberstammdaten();

        $daten = $this->vorschaubereiterLauf(2);
        $this->bezahlterLauf($daten['billingRun'], 4980, 2);

        $this->bindeFinalDocumentViews(new FinalViewBundle(
            [PdfFixtures::statementView(), PdfFixtures::statementView()],
            [null, null],
            PdfFixtures::ownerOverviewView(),
        ));

        app(FinalizeBillingRun::class)(
            BillingRun::query()->findOrFail($daten['billingRun']->getKey()),
            $daten['user'],
        );

        return [
            'lauf' => BillingRun::query()->findOrFail($daten['billingRun']->getKey()),
            'nutzer' => $daten['user'],
        ];
    }

    public function test_der_abschluss_zeigt_einzeldateien_zip_uebersicht_und_rechnung(): void
    {
        $vorgang = $this->finalisierterLauf();

        $antwort = $this->actingAs($vorgang['nutzer'])
            ->get(route('portal.abschluss.show', ['billingRun' => $vorgang['lauf']->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('Ihre Abrechnung ist fertig');
        $antwort->assertSee('ZIP-Paket herunterladen');
        $antwort->assertSee('Eigentümerübersicht herunterladen');
        $antwort->assertSee('Mieterabrechnung 1');
        $antwort->assertSee('Mieterabrechnung 2');
        $antwort->assertSee('Rechnung als PDF');
        $antwort->assertSee('NK-');
    }

    public function test_jede_datei_ist_ueber_die_autorisierte_downloadroute_erreichbar(): void
    {
        $vorgang = $this->finalisierterLauf();

        // Geprueft werden die Finaldokumente. Die Vorschau mit Wasserzeichen
        // des Fixtures liegt nicht als Datei vor.
        $dokumente = GeneratedDocument::query()
            ->where('billing_run_id', $vorgang['lauf']->getKey())
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->get();

        self::assertGreaterThan(0, $dokumente->count());

        foreach ($dokumente as $dokument) {
            $antwort = $this->actingAs($vorgang['nutzer'])
                ->get(route('portal.downloads.stream', ['generatedDocument' => $dokument->getKey()]));

            $antwort->assertOk();
        }
    }

    public function test_der_finale_download_verlangt_eine_bestaetigte_email_adresse(): void
    {
        $vorgang = $this->finalisierterLauf();
        $vorgang['nutzer']->forceFill(['email_verified_at' => null])->save();

        /** @var GeneratedDocument $dokument */
        $dokument = GeneratedDocument::query()
            ->where('billing_run_id', $vorgang['lauf']->getKey())
            ->firstOrFail();

        $this->actingAs($vorgang['nutzer'])
            ->get(route('portal.downloads.stream', ['generatedDocument' => $dokument->getKey()]))
            ->assertForbidden();

        $this->actingAs($vorgang['nutzer'])
            ->get(route('portal.abschluss.show', ['billingRun' => $vorgang['lauf']->getKey()]))
            ->assertForbidden();
    }

    public function test_der_lauf_bleibt_dauerhaft_im_konto_abrufbar(): void
    {
        $vorgang = $this->finalisierterLauf();

        $this->travel(400)->days();

        $this->actingAs($vorgang['nutzer'])
            ->get(route('portal.abschluss.show', ['billingRun' => $vorgang['lauf']->getKey()]))
            ->assertOk();

        self::assertSame(BillingRunStatus::FINALIZED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
    }

    public function test_die_rueckleitung_leitet_nach_der_finalisierung_auf_den_abschluss(): void
    {
        $vorgang = $this->finalisierterLauf();

        $this->actingAs($vorgang['nutzer'])
            ->get(route('portal.checkout.erfolg', ['billingRun' => $vorgang['lauf']->getKey()]))
            ->assertRedirect(route('portal.abschluss.show', ['billingRun' => $vorgang['lauf']->getKey()]));
    }

    public function test_ersetzte_versionen_erscheinen_nur_als_historie(): void
    {
        $vorgang = $this->finalisierterLauf();

        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());
        $lauf->forceFill(['status' => BillingRunStatus::PAID])->save();

        app(FinalizeBillingRun::class)($lauf, $vorgang['nutzer']);

        $antwort = $this->actingAs($vorgang['nutzer'])
            ->get(route('portal.abschluss.show', ['billingRun' => $vorgang['lauf']->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('Frühere Versionen');
        $antwort->assertSee('ersetzt, erstellt am');
    }
}
