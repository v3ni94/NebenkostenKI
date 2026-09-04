<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\Landlord;
use App\Models\Property;
use App\Rules\Engine\RuleContextFactory;
use App\Rules\Engine\RuleEngine;
use Database\Factories\TestData;
use Illuminate\Support\Facades\DB;

/**
 * Vermieter als Absender der Mieterabrechnung (Schritt 4, Masterprompt 2.2).
 *
 * Anlage und Bearbeitung ueber die Oberflaeche, Verschluesselung der
 * Bankverbindung, Mandantentrennung und der Blocker VERMIETER_FEHLT im
 * Pruefbericht.
 */
final class LandlordTest extends PortalTestCase
{
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
            'phone' => '02173 000000',
        ], $abweichungen);
    }

    public function test_das_formular_ist_ueber_das_objekt_erreichbar(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.objekte.vermieter.edit', ['property' => $mandant['property']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Vermieter bearbeiten');
        $antwort->assertSee('Bankverbindung in der Abrechnung anzeigen');
        $antwort->assertDontSee('Steuernummer');
    }

    public function test_der_vermieter_wird_angelegt_und_am_objekt_hinterlegt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->put(
            route('portal.objekte.vermieter.update', ['property' => $mandant['property']->getKey()]),
            $this->vermieterangaben()
        );

        $antwort->assertRedirect(route('portal.objekte.index'));
        $antwort->assertSessionHas('status');

        $objekt = Property::query()->findOrFail($mandant['property']->getKey());
        $vermieter = Landlord::query()->where('sender_name', 'Beispiel Vermietung Sonnenweg')->first();

        self::assertInstanceOf(Landlord::class, $vermieter);
        self::assertSame($vermieter->getKey(), $objekt->getAttribute('landlord_id'));
        self::assertSame($mandant['organization']->getKey(), $vermieter->getAttribute('organization_id'));
        self::assertSame('DE', $vermieter->getAttribute('country'));
        self::assertNull($vermieter->getAttribute('iban'));
        self::assertFalse($vermieter->getAttribute('show_bank_details_on_statement'));

        self::assertTrue(
            AuditLog::query()
                ->where('action', 'landlord.created')
                ->where('actor_user_id', $mandant['user']->getKey())
                ->exists()
        );
    }

    public function test_pflichtangaben_werden_verlangt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.objekte.vermieter.edit', ['property' => $mandant['property']->getKey()]))
            ->put(
                route('portal.objekte.vermieter.update', ['property' => $mandant['property']->getKey()]),
                ['sender_name' => '', 'address_line' => '', 'postal_code' => '', 'city' => '']
            );

        $antwort->assertSessionHasErrors(['sender_name', 'address_line', 'postal_code', 'city']);
        self::assertSame(0, Landlord::query()->count());
    }

    public function test_die_bankverbindung_wird_verschluesselt_gespeichert_und_normalisiert(): void
    {
        $mandant = $this->mandant();

        $this->actingAs($mandant['user'])->put(
            route('portal.objekte.vermieter.update', ['property' => $mandant['property']->getKey()]),
            $this->vermieterangaben([
                'iban' => strtolower(chunk_split(TestData::PLACEHOLDER_IBAN, 4, ' ')),
                'bic' => TestData::PLACEHOLDER_BIC,
                'show_bank_details_on_statement' => '1',
            ])
        )->assertRedirect(route('portal.objekte.index'));

        $vermieter = Landlord::query()->firstOrFail();

        self::assertSame(TestData::PLACEHOLDER_IBAN, $vermieter->getAttribute('iban'));
        self::assertSame(TestData::PLACEHOLDER_BIC, $vermieter->getAttribute('bic'));
        self::assertTrue($vermieter->getAttribute('show_bank_details_on_statement'));

        // In der Datenbank liegt die IBAN nur verschluesselt.
        $roh = DB::table('landlords')->where('id', $vermieter->getKey())->value('iban');

        self::assertIsString($roh);
        self::assertStringNotContainsString(TestData::PLACEHOLDER_IBAN, $roh);
        self::assertStringNotContainsString(substr(TestData::PLACEHOLDER_IBAN, 4, 10), $roh);

        // Der Revisionseintrag enthaelt keine Bankdaten.
        $eintrag = AuditLog::query()->where('action', 'landlord.created')->firstOrFail();
        $json = json_encode($eintrag->getAttributes());

        self::assertIsString($json);
        self::assertStringNotContainsString(TestData::PLACEHOLDER_IBAN, $json);
    }

    public function test_eine_ungueltige_iban_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.objekte.vermieter.edit', ['property' => $mandant['property']->getKey()]))
            ->put(
                route('portal.objekte.vermieter.update', ['property' => $mandant['property']->getKey()]),
                $this->vermieterangaben(['iban' => '1234', 'bic' => 'XY'])
            );

        $antwort->assertSessionHasErrors(['iban', 'bic']);
    }

    public function test_ein_vorhandener_vermieter_wird_bearbeitet_statt_neu_angelegt(): void
    {
        $mandant = $this->mandant();

        $this->actingAs($mandant['user'])->put(
            route('portal.objekte.vermieter.update', ['property' => $mandant['property']->getKey()]),
            $this->vermieterangaben()
        );

        $this->actingAs($mandant['user'])->put(
            route('portal.objekte.vermieter.update', ['property' => $mandant['property']->getKey()]),
            $this->vermieterangaben(['sender_name' => 'Neuer Name', 'company_name' => 'Beispiel Wohnen GmbH'])
        )->assertRedirect(route('portal.objekte.index'));

        self::assertSame(1, Landlord::query()->count());

        $vermieter = Landlord::query()->firstOrFail();

        self::assertSame('Neuer Name', $vermieter->getAttribute('sender_name'));
        self::assertSame('Beispiel Wohnen GmbH', $vermieter->getAttribute('company_name'));
        self::assertTrue(AuditLog::query()->where('action', 'landlord.updated')->exists());

        $liste = $this->actingAs($mandant['user'])->get(route('portal.objekte.index'));
        $liste->assertOk();
        $liste->assertSee('Vermieter: Beispiel Wohnen GmbH, Neuer Name');
    }

    public function test_fremde_objekte_erhalten_keinen_vermieter(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        $lesen = $this->actingAs($a['user'])->get(
            route('portal.objekte.vermieter.edit', ['property' => $b['property']->getKey()])
        );
        self::assertContains($lesen->getStatusCode(), [403, 404]);

        $schreiben = $this->actingAs($a['user'])->put(
            route('portal.objekte.vermieter.update', ['property' => $b['property']->getKey()]),
            $this->vermieterangaben(['sender_name' => 'Uebernommen'])
        );
        self::assertContains($schreiben->getStatusCode(), [403, 404]);

        self::assertSame(0, Landlord::query()->count());
        self::assertNull(Property::query()->findOrFail($b['property']->getKey())->getAttribute('landlord_id'));
    }

    public function test_ohne_vermieter_meldet_der_pruefbericht_einen_blocker(): void
    {
        $mandant = $this->mandant();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
        ]);

        $report = (new RuleEngine)->runForContext((new RuleContextFactory)->fromBillingRun($lauf));

        self::assertContains(
            'VERMIETER_FEHLT',
            array_map(static fn ($ergebnis): string => $ergebnis->ruleCode, $report->blockers())
        );

        // Der Vermieter wird nach Anlage des Laufs am Objekt erfasst und gilt
        // trotzdem fuer den Lauf.
        $this->actingAs($mandant['user'])->put(
            route('portal.objekte.vermieter.update', ['property' => $mandant['property']->getKey()]),
            $this->vermieterangaben()
        )->assertRedirect(route('portal.objekte.index'));

        $report = (new RuleEngine)->runForContext((new RuleContextFactory)->fromBillingRun($lauf->refresh()));

        self::assertNotContains(
            'VERMIETER_FEHLT',
            array_map(static fn ($ergebnis): string => $ergebnis->ruleCode, $report->blockers())
        );
    }
}
