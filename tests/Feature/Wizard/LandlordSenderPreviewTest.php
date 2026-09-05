<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Application\Wizard\PreviewBuilder;
use App\Enums\GeneratedDocumentKind;
use App\Models\GeneratedDocument;
use App\Models\Landlord;
use App\Models\Property;
use App\Services\Storage\ArtifactStorage;
use Database\Factories\TestData;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Calculation\CalculationTestCase;
use Tests\Feature\Pdf\PdfTextExtractor;

/**
 * Absender der Mieterabrechnung aus den im Portal erfassten Vermieterdaten.
 *
 * Der Vermieter wird ueber die Oberflaeche erfasst, die Vorschau ueber den
 * echten Renderweg erzeugt und der Text des PDF geprueft.
 */
final class LandlordSenderPreviewTest extends CalculationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @param  array<string, mixed>  $abweichungen
     * @return array<string, mixed>
     */
    private function vermieterangaben(array $abweichungen = []): array
    {
        return array_merge([
            'sender_name' => 'Beispiel Vermietung Sonnenweg',
            'address_line' => 'Sonnenweg 4',
            'postal_code' => '40789',
            'city' => 'Monheim am Rhein',
            'email' => 'vermietung@beispiel.invalid',
        ], $abweichungen);
    }

    /**
     * @param  array<string, mixed>  $szenario
     */
    private function mieterabrechnungstext(array $szenario): string
    {
        app(PreviewBuilder::class)->rebuild($szenario['billingRun']->refresh(), $szenario['user']);

        $dokument = GeneratedDocument::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->where('kind', GeneratedDocumentKind::MIETERABRECHNUNG->value)
            ->firstOrFail();

        $inhalt = (new ArtifactStorage)->disk()->get($dokument->storage_path);

        self::assertIsString($inhalt);

        return PdfTextExtractor::text($inhalt);
    }

    /**
     * Entfernt den im Szenario vorbelegten Vermieter, damit der Weg ueber die
     * Oberflaeche geprueft wird.
     *
     * @param  array<string, mixed>  $szenario
     */
    private function ohneVermieter(array $szenario): void
    {
        Property::query()->whereKey($szenario['property']->getKey())->update(['landlord_id' => null]);
        Landlord::query()->where('organization_id', $szenario['organization']->getKey())->delete();
    }

    public function test_die_mieterabrechnung_traegt_den_im_portal_erfassten_vermieter_als_absender(): void
    {
        $szenario = $this->szenario();
        $this->ohneVermieter($szenario);

        $this->actingAs($szenario['user'])->put(
            route('portal.objekte.vermieter.update', ['property' => $szenario['property']->getKey()]),
            $this->vermieterangaben(['iban' => TestData::PLACEHOLDER_IBAN, 'bic' => TestData::PLACEHOLDER_BIC])
        )->assertRedirect();

        $text = $this->mieterabrechnungstext($szenario);

        self::assertStringContainsString('Beispiel Vermietung Sonnenweg', $text);
        self::assertStringContainsString('Sonnenweg 4', $text);
        self::assertStringContainsString('vermietung@beispiel.invalid', $text);

        // Ohne ausdruecklichen Wunsch erscheint die Bankverbindung nicht.
        self::assertStringNotContainsString(TestData::PLACEHOLDER_IBAN, $text);
        self::assertStringNotContainsString('Zahlungsempfänger', $text);
    }

    public function test_die_bankverbindung_erscheint_nur_auf_ausdruecklichen_wunsch(): void
    {
        $szenario = $this->szenario();
        $this->ohneVermieter($szenario);

        $this->actingAs($szenario['user'])->put(
            route('portal.objekte.vermieter.update', ['property' => $szenario['property']->getKey()]),
            $this->vermieterangaben([
                'company_name' => 'Beispiel Wohnen GmbH',
                'iban' => TestData::PLACEHOLDER_IBAN,
                'bic' => TestData::PLACEHOLDER_BIC,
                'show_bank_details_on_statement' => '1',
            ])
        )->assertRedirect();

        $text = $this->mieterabrechnungstext($szenario);

        self::assertStringContainsString('Beispiel Wohnen GmbH', $text);
        self::assertStringContainsString('Beispiel Vermietung Sonnenweg', $text);
        self::assertStringContainsString(TestData::PLACEHOLDER_IBAN, $text);
        self::assertStringContainsString(TestData::PLACEHOLDER_BIC, $text);
    }

    public function test_ohne_vermieter_wird_die_vorschau_nicht_erzeugt(): void
    {
        $szenario = $this->szenario();
        $this->ohneVermieter($szenario);

        // Schritt 9: der Pruefbericht meldet den fehlenden Vermieter als Blocker.
        $bericht = $this->actingAs($szenario['user'])->get(
            route('portal.wizard.pruefbericht', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $bericht->assertOk();
        $bericht->assertSee('Vermieter als Absender fehlt');
        $bericht->assertSee('Solange Blocker offen sind');

        $this->actingAs($szenario['user'])
            ->post(route('portal.wizard.pruefbericht.weiter', ['billingRun' => $szenario['billingRun']->getKey()]))
            ->assertSessionHasErrors('weiter');

        // Schritt 10: die Vorschau wird mit offenem Blocker nicht erzeugt.
        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertSessionHasErrors('vorschau');

        self::assertSame(
            0,
            GeneratedDocument::query()->where('billing_run_id', $szenario['billingRun']->getKey())->count()
        );
    }
}
