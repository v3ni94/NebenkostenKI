<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Application\Privacy\CreateDataExport;
use Illuminate\Support\Carbon;

/**
 * Uebersichtsseite im Konto (Masterprompt 8.2 und 19) und Mandantentrennung
 * jeder neuen Route (ARCHITECTURE.md T1).
 */
final class PrivacyPageTest extends PrivacyTestCase
{
    public function test_seite_zeigt_export_loeschung_und_auskunft(): void
    {
        $a = $this->mandant('A');

        $antwort = $this->actingAs($a['user'])->get(route('portal.datenschutz.show'));

        $antwort->assertOk();
        $antwort->assertSee('Datenschutz und Löschung');
        $antwort->assertSee('Datenexport anfordern');
        $antwort->assertSee('Löschung des Kontos');
        $antwort->assertSee('Was dauerhaft gespeichert wird');
        $antwort->assertSee('Was nicht dauerhaft gespeichert wird');
        $antwort->assertSee('Löschung beantragen');
    }

    public function test_seite_weist_auf_die_eigene_aufbewahrungspflicht_hin(): void
    {
        $a = $this->mandant('A');

        $antwort = $this->actingAs($a['user'])->get(route('portal.datenschutz.show'));

        $antwort->assertSee('Bewahren Sie Ihre Originalbelege selbst auf');
        $antwort->assertSee('Bitte bewahren Sie Ihre Originalrechnungen, Bescheide und Mietverträge', false);
    }

    public function test_seite_nennt_erhaltene_hvm_rechnungen_und_frist(): void
    {
        $a = $this->mandant('A');

        $antwort = $this->actingAs($a['user'])->get(route('portal.datenschutz.show'));

        $antwort->assertSee('Das bleibt erhalten');
        $antwort->assertSee('Rechnungen der Hausverwaltung Müller GmbH', false);
        $antwort->assertSee('30 Tagen', false);
    }

    public function test_seite_zeigt_den_laufenden_loeschantrag_mit_termin(): void
    {
        $a = $this->mandant('A');

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

        $this->actingAs($a['user'])->post(route('portal.datenschutz.loeschung'), ['bestaetigung' => '1']);

        $antwort = $this->actingAs($a['user'])->get(route('portal.datenschutz.show'));

        $antwort->assertSee('01.10.2026');
        $antwort->assertSee('Löschantrag zurücknehmen');
        $antwort->assertDontSee('Löschung beantragen');

        Carbon::setTestNow();
    }

    public function test_seite_listet_bereitstehende_exporte(): void
    {
        $a = $this->mandant('A');

        /** @var CreateDataExport $export */
        $export = app(CreateDataExport::class);
        $export($a['user'], $a['organization']);

        $antwort = $this->actingAs($a['user'])->get(route('portal.datenschutz.show'));

        $antwort->assertOk();
        $antwort->assertSee('Herunterladen');
        $antwort->assertSee('Kurzlebigen Link erzeugen');
    }

    public function test_seite_zeigt_keine_fremden_exporte(): void
    {
        $a = $this->mandant('A');
        $b = $this->mandant('B');

        /** @var CreateDataExport $export */
        $export = app(CreateDataExport::class);
        $fremd = $export($b['user'], $b['organization'])->document;

        $antwort = $this->actingAs($a['user'])->get(route('portal.datenschutz.show'));

        $antwort->assertOk();
        $antwort->assertDontSee((string) $fremd->getKey());
        $antwort->assertSee('Es steht derzeit kein Datenexport bereit.');
    }

    public function test_alle_routen_verlangen_eine_anmeldung(): void
    {
        $a = $this->mandant('A');

        /** @var CreateDataExport $export */
        $export = app(CreateDataExport::class);
        $eigen = $export($a['user'], $a['organization'])->document;

        $routen = [
            ['get', route('portal.datenschutz.show')],
            ['post', route('portal.datenschutz.export')],
            ['get', route('portal.datenschutz.export.download', ['export' => $eigen->getKey()])],
            ['post', route('portal.datenschutz.export.link', ['export' => $eigen->getKey()])],
            ['post', route('portal.datenschutz.loeschung')],
            ['delete', route('portal.datenschutz.loeschung.zuruecknehmen')],
        ];

        foreach ($routen as [$verb, $url]) {
            $antwort = $this->{$verb}($url);

            self::assertContains(
                $antwort->getStatusCode(),
                [302, 401, 403],
                'Die Route '.$url.' ist ohne Anmeldung erreichbar.'
            );
        }
    }

    public function test_mandantentrennung_jeder_neuen_route(): void
    {
        $a = $this->mandant('A');
        $b = $this->mandant('B');

        /** @var CreateDataExport $export */
        $export = app(CreateDataExport::class);
        $fremd = $export($b['user'], $b['organization'])->document;

        $routen = [
            ['get', route('portal.datenschutz.export.download', ['export' => $fremd->getKey()])],
            ['post', route('portal.datenschutz.export.link', ['export' => $fremd->getKey()])],
        ];

        foreach ($routen as [$verb, $url]) {
            $antwort = $this->actingAs($a['user'])->{$verb}($url);

            self::assertContains(
                $antwort->getStatusCode(),
                [403, 404],
                'Die Route '.$url.' gibt einen fremden Export frei.'
            );

            self::assertStringNotContainsString(
                (string) $b['user']->getAttribute('email'),
                (string) $antwort->getContent()
            );
        }
    }

    public function test_loeschantrag_wirkt_nur_auf_das_eigene_konto(): void
    {
        $a = $this->mandant('A');
        $b = $this->mandant('B');

        $this->actingAs($a['user'])->post(route('portal.datenschutz.loeschung'), ['bestaetigung' => '1']);

        $antwort = $this->actingAs($b['user'])->get(route('portal.datenschutz.show'));

        $antwort->assertSee('Löschung beantragen');
        $antwort->assertDontSee('Löschantrag zurücknehmen');
    }

    public function test_seite_behauptet_keine_forensische_ueberschreibung(): void
    {
        $a = $this->mandant('A');

        $antwort = $this->actingAs($a['user'])->get(route('portal.datenschutz.show'));

        $inhalt = (string) $antwort->getContent();

        self::assertStringNotContainsString('forensisch', $inhalt);
        self::assertStringNotContainsString('überschrieben', $inhalt);
        self::assertStringContainsString('logische Löschung', $inhalt);
    }
}
