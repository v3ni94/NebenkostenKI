<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\TestCase;

/**
 * Validierungsmeldungen sind vollstaendig deutsch (Masterprompt 12,
 * ARCHITECTURE.md Grundsatz 11).
 *
 * Es gibt keine Sprachdatei unter lang/. Jede Regel ohne eigene Meldung faellt
 * auf den englischen Text des Frameworks zurueck; die Regel enum traegt sogar
 * einen fest verdrahteten englischen Rueckfall. Die Meldungen der haeufigen
 * Regeln stehen deshalb in GermanFormRequest, und alle Formularanfragen der
 * Anwendung leiten davon ab.
 */
final class DeutscheValidierungsmeldungenTest extends TestCase
{
    use BuildsUploadWorld, RefreshDatabase;

    public function test_ungueltige_auswahl_bei_enum_regel_liefert_eine_deutsche_meldung(): void
    {
        $welt = $this->welt();

        $antwort = $this->actingAs($welt['user'])
            ->from(route('portal.konto.edit'))
            ->put(route('portal.konto.update'), [
                'name' => 'Timo Beispiel',
                'organization_name' => 'Beispiel Immobilien',
                'organization_type' => 'X',
            ]);

        $antwort->assertRedirect(route('portal.konto.edit'));
        $antwort->assertSessionHasErrors('organization_type');

        $meldung = (string) session('errors')?->first('organization_type');

        self::assertStringContainsString('Bitte treffen Sie eine gültige Auswahl bei Art des Kontos.', $meldung);
        self::assertStringNotContainsString('The selected', $meldung);
    }

    public function test_upload_start_meldet_einen_zu_langen_dateinamen_deutsch(): void
    {
        $antwort = $this->starteUpload(str_repeat('a', 300).'.pdf', 1024);

        $antwort->assertStatus(422);

        $meldung = (string) $antwort->json('errors.dateiname.0');

        self::assertStringContainsString('darf höchstens 255 Zeichen lang sein', $meldung);
        self::assertStringNotContainsString('must not be greater', $meldung);
    }

    public function test_upload_start_meldet_eine_ungueltige_groesse_deutsch(): void
    {
        $antwort = $this->starteUpload('beleg.pdf', 0);

        $antwort->assertStatus(422);

        $meldung = (string) $antwort->json('errors.groesse.0');

        self::assertStringContainsString('mindestens 1 betragen', $meldung);
        self::assertStringNotContainsString('must be at least', $meldung);
    }

    public function test_eigene_meldungen_der_upload_anfrage_bleiben_erhalten(): void
    {
        $antwort = $this->starteUpload('beleg.pdf', 1024, 'GIBT_ES_NICHT');

        $antwort->assertStatus(422);
        self::assertSame('Die gewählte Kategorie ist nicht zulässig.', $antwort->json('errors.kategorie.0'));
    }

    public function test_alle_formularanfragen_leiten_von_der_deutschen_basis_ab(): void
    {
        $dateien = glob(app_path('Http/Requests/*/*.php')) ?: [];

        self::assertNotSame([], $dateien);

        foreach ($dateien as $datei) {
            $inhalt = (string) file_get_contents($datei);

            if (! str_contains($inhalt, 'extends ')) {
                continue;
            }

            self::assertStringNotContainsString(
                'extends FormRequest',
                $inhalt,
                basename($datei).' muss von GermanFormRequest ableiten, sonst erscheinen englische Meldungen.'
            );
        }
    }
}
