<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Application\Privacy\CreateDataExport;
use App\Enums\GeneratedDocumentKind;
use App\Enums\OrganizationRole;
use App\Models\AuditLog;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Providers\AppServiceProvider;
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

    public function test_ein_mitglied_desselben_mandanten_sieht_und_laedt_den_export_nicht(): void
    {
        $a = $this->mandant('A');

        /** @var User $mitglied */
        $mitglied = User::factory()->create(['email' => 'mitglied@example.test']);

        OrganizationUser::query()->create([
            'organization_id' => $a['organization']->getKey(),
            'user_id' => $mitglied->getKey(),
            'role' => OrganizationRole::MEMBER,
            'joined_at' => now(),
        ]);

        $export = $this->export($a);

        // Der Export enthaelt Kontodaten von A und Daten aller Mandanten von A.
        // Ein anderes Mitglied des geteilten Mandanten darf ihn nicht sehen.
        $seite = $this->actingAs($mitglied)->get(route('portal.datenschutz.show'));
        $seite->assertOk();
        $seite->assertDontSee(route('portal.datenschutz.export.download', ['export' => $export->getKey()]), false);
        $seite->assertSee('Es steht derzeit kein Datenexport bereit.');

        $this->actingAs($mitglied)->get(route('portal.datenschutz.export.download', [
            'export' => $export->getKey(),
        ]))->assertNotFound();

        $this->actingAs($mitglied)->post(route('portal.datenschutz.export.link', [
            'export' => $export->getKey(),
        ]))->assertNotFound();

        /** @var SignedDownloadUrlFactory $urls */
        $urls = app(SignedDownloadUrlFactory::class);

        $this->actingAs($mitglied)->get($urls->forRoute('portal.datenschutz.export.signiert', [
            'export' => (string) $export->getKey(),
        ]))->assertNotFound();

        // Der Antragsteller selbst behaelt den Zugriff.
        $this->actingAs($a['user'])->get(route('portal.datenschutz.export.download', [
            'export' => $export->getKey(),
        ]))->assertOk();
    }

    public function test_die_anforderung_ist_je_nutzer_und_tag_begrenzt(): void
    {
        $a = $this->mandant('A');

        for ($i = 0; $i < AppServiceProvider::DATENEXPORTE_JE_TAG; $i++) {
            $this->actingAs($a['user'])
                ->post(route('portal.datenschutz.export'))
                ->assertRedirect(route('portal.datenschutz.show'));
        }

        $antwort = $this->actingAs($a['user'])->post(route('portal.datenschutz.export'));

        // Kein 429 mit Fehlerseite, sondern ein Hinweis auf der Datenschutzseite.
        $antwort->assertRedirect(route('portal.datenschutz.show'));
        self::assertStringContainsString('bereits', (string) session('status'));
        self::assertStringContainsString('Datenexporte angefordert', (string) session('status'));

        self::assertSame(
            AppServiceProvider::DATENEXPORTE_JE_TAG,
            AuditLog::query()->where('action', 'privacy.export.created')->count()
        );

        // Ein anderer Nutzer ist von der Grenze nicht betroffen.
        $b = $this->mandant('B');
        $this->actingAs($b['user'])->post(route('portal.datenschutz.export'));

        self::assertSame(
            AppServiceProvider::DATENEXPORTE_JE_TAG + 1,
            AuditLog::query()->where('action', 'privacy.export.created')->count()
        );
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
