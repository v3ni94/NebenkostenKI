<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Application\Privacy\CreateDataExport;
use App\Enums\GeneratedDocumentKind;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\SignedDownloadUrlFactory;
use Illuminate\Support\Facades\Storage;

/**
 * Auslieferung des Datenexports (Masterprompt 19, ARCHITECTURE.md T2).
 *
 * Der Export wird ausschliesslich ueber eine autorisierte Route oder einen
 * kurzlebigen signierten Link ausgeliefert. Ein signierter Link ersetzt die
 * Autorisierung nicht.
 */
final class DataExportDeliveryTest extends PrivacyTestCase
{
    public function test_export_wird_ueber_die_oberflaeche_angefordert(): void
    {
        $a = $this->mandant('A');

        $antwort = $this->actingAs($a['user'])->post(route('portal.datenschutz.export'));

        $antwort->assertRedirect(route('portal.datenschutz.show'));
        $antwort->assertSessionHas('status');

        self::assertSame(1, $this->exportAnzahl());
    }

    public function test_autorisierter_download_liefert_das_zip(): void
    {
        $a = $this->mandant('A');
        $export = $this->export($a);

        $antwort = $this->actingAs($a['user'])->get(route('portal.datenschutz.export.download', [
            'export' => $export->getKey(),
        ]));

        $antwort->assertOk();
        $antwort->assertHeader('content-type', 'application/zip');
        $antwort->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_download_ohne_anmeldung_ist_gesperrt(): void
    {
        $a = $this->mandant('A');
        $export = $this->export($a);

        $antwort = $this->get(route('portal.datenschutz.export.download', [
            'export' => $export->getKey(),
        ]));

        self::assertContains($antwort->getStatusCode(), [302, 401, 403]);
    }

    public function test_fremder_export_ist_nicht_abrufbar(): void
    {
        $a = $this->mandant('A');
        $b = $this->mandant('B');
        $fremd = $this->export($b);

        $antwort = $this->actingAs($a['user'])->get(route('portal.datenschutz.export.download', [
            'export' => $fremd->getKey(),
        ]));

        self::assertContains($antwort->getStatusCode(), [403, 404]);
    }

    public function test_signierter_link_ist_gueltig_und_laeuft_ab(): void
    {
        $a = $this->mandant('A');
        $export = $this->export($a);

        /** @var SignedDownloadUrlFactory $urls */
        $urls = app(SignedDownloadUrlFactory::class);

        $gueltig = $urls->forRoute('portal.datenschutz.export.signiert', [
            'export' => (string) $export->getKey(),
        ]);

        $this->actingAs($a['user'])->get($gueltig)->assertOk();

        $abgelaufen = $urls->expiredForRoute('portal.datenschutz.export.signiert', [
            'export' => (string) $export->getKey(),
        ]);

        $this->actingAs($a['user'])->get($abgelaufen)->assertForbidden();
    }

    public function test_signierter_link_ersetzt_die_autorisierung_nicht(): void
    {
        $a = $this->mandant('A');
        $b = $this->mandant('B');
        $fremd = $this->export($b);

        /** @var SignedDownloadUrlFactory $urls */
        $urls = app(SignedDownloadUrlFactory::class);

        $url = $urls->forRoute('portal.datenschutz.export.signiert', [
            'export' => (string) $fremd->getKey(),
        ]);

        $antwort = $this->actingAs($a['user'])->get($url);

        self::assertContains($antwort->getStatusCode(), [403, 404]);
    }

    public function test_signierter_link_wird_ueber_die_oberflaeche_erzeugt(): void
    {
        $a = $this->mandant('A');
        $export = $this->export($a);

        $antwort = $this->actingAs($a['user'])->post(route('portal.datenschutz.export.link', [
            'export' => $export->getKey(),
        ]));

        $antwort->assertRedirect(route('portal.datenschutz.show'));

        $status = session('status');
        self::assertIsString($status);
        self::assertStringContainsString('signature=', $status);
    }

    public function test_fehlende_artefaktdatei_fuehrt_zu_404(): void
    {
        $a = $this->mandant('A');
        $export = $this->export($a);

        Storage::disk('local')
            ->delete((string) $export->getAttribute('storage_path'));

        $this->actingAs($a['user'])->get(route('portal.datenschutz.export.download', [
            'export' => $export->getKey(),
        ]))->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $mandant
     */
    private function export(array $mandant): GeneratedDocument
    {
        /** @var CreateDataExport $use */
        $use = app(CreateDataExport::class);

        $nutzer = $mandant['user'];
        $organisation = $mandant['organization'];

        self::assertInstanceOf(User::class, $nutzer);
        self::assertInstanceOf(Organization::class, $organisation);

        return $use($nutzer, $organisation)->document;
    }

    private function exportAnzahl(): int
    {
        return GeneratedDocument::query()
            ->where('kind', GeneratedDocumentKind::DSGVO_EXPORT->value)
            ->count();
    }
}
