<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Application\Privacy\CreateDataExport;
use App\Application\Privacy\Dto\DataExportResult;
use App\Enums\GeneratedDocumentKind;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Nachweise zum DSGVO-Datenexport (Masterprompt 19).
 *
 * Verbindlich sind drei Aussagen: Der Export enthaelt alle eigenen Entitaeten,
 * er enthaelt keine Originaldateien, und er enthaelt keine Daten anderer
 * Mandanten.
 */
final class DataExportTest extends PrivacyTestCase
{
    public function test_export_enthaelt_alle_eigenen_entitaeten(): void
    {
        $a = $this->mandant('A');

        $ergebnis = $this->exportiere($a['user'], $a['organization']);
        $eintraege = $this->zipEintraege((string) $ergebnis->document->getAttribute('storage_path'));

        foreach ([
            'daten/konto.json',
            'daten/organisationen.json',
            'daten/mitgliedschaften.json',
            'daten/objekte.json',
            'daten/einheiten.json',
            'daten/mietverhaeltnisse.json',
            'daten/abrechnungslaeufe.json',
            'daten/quellenverzeichnis.json',
            'daten/ausgelesene_felder.json',
            'daten/rechnungen.json',
            'daten/erzeugte_dokumente.json',
            'daten/revisionsprotokoll.json',
            'LIESMICH.txt',
        ] as $erwartet) {
            self::assertArrayHasKey($erwartet, $eintraege, 'Im Export fehlt '.$erwartet);
        }

        self::assertStringContainsString('Objekt A', $eintraege['daten/objekte.json']);
        self::assertStringContainsString(
            (string) $a['field']->getAttribute('schema_key'),
            $eintraege['daten/ausgelesene_felder.json']
        );
        self::assertStringContainsString(
            (string) $a['invoice']->getAttribute('number'),
            $eintraege['daten/rechnungen.json']
        );
    }

    public function test_export_enthaelt_die_lesbare_uebersicht_mit_datenschutzauskunft(): void
    {
        $a = $this->mandant('A');

        $ergebnis = $this->exportiere($a['user'], $a['organization']);
        $eintraege = $this->zipEintraege((string) $ergebnis->document->getAttribute('storage_path'));

        $uebersicht = $eintraege['LIESMICH.txt'];

        self::assertStringContainsString('Was dauerhaft gespeichert wird', $uebersicht);
        self::assertStringContainsString('Was nicht dauerhaft gespeichert wird', $uebersicht);
        self::assertStringContainsString('Bitte bewahren Sie Ihre Originalrechnungen', $uebersicht);
        self::assertStringContainsString('objekte', $uebersicht);
    }

    public function test_export_enthaelt_die_erzeugten_pdfs_und_die_hvm_rechnung(): void
    {
        $a = $this->mandant('A');

        $ergebnis = $this->exportiere($a['user'], $a['organization']);
        $eintraege = $this->zipEintraege((string) $ergebnis->document->getAttribute('storage_path'));

        $abrechnungen = array_filter(
            array_keys($eintraege),
            static fn (string $name): bool => str_starts_with($name, 'abrechnungen/')
        );
        $rechnungen = array_filter(
            array_keys($eintraege),
            static fn (string $name): bool => str_starts_with($name, 'rechnungen-hvm/')
        );

        self::assertCount(1, $abrechnungen);
        self::assertCount(1, $rechnungen);

        foreach (array_merge($abrechnungen, $rechnungen) as $name) {
            self::assertStringStartsWith('%PDF-', $eintraege[$name]);
        }
    }

    public function test_export_enthaelt_keine_originaldateien(): void
    {
        $a = $this->mandant('A');
        $this->originaldateiAblegen();

        $ergebnis = $this->exportiere($a['user'], $a['organization']);
        $pfad = (string) $ergebnis->document->getAttribute('storage_path');

        $roh = Storage::disk('local')->get($pfad);
        self::assertIsString($roh);
        self::assertStringNotContainsString(self::ORIGINAL_MARKER, $roh);

        $eintraege = $this->zipEintraege($pfad);

        foreach ($eintraege as $name => $inhalt) {
            self::assertStringNotContainsString(self::ORIGINAL_MARKER, $inhalt);
            self::assertStringNotContainsString('temporary-uploads', $name);
            self::assertStringNotContainsString('quarantaene', $name);

            // Binaerdateien im Export sind ausschliesslich erzeugte PDFs.
            if (! str_ends_with($name, '.json') && ! str_ends_with($name, '.txt')) {
                self::assertStringStartsWith('%PDF-', $inhalt);
            }
        }
    }

    public function test_export_enthaelt_keinen_speicherschluessel_des_kurzzeitbereichs(): void
    {
        $a = $this->mandant('A');

        $ergebnis = $this->exportiere($a['user'], $a['organization']);
        $eintraege = $this->zipEintraege((string) $ergebnis->document->getAttribute('storage_path'));

        self::assertArrayNotHasKey('daten/kurzzeituploads.json', $eintraege);
        self::assertStringNotContainsString('storage_key', $eintraege['daten/quellenverzeichnis.json']);
    }

    public function test_export_enthaelt_keine_fremddaten(): void
    {
        $a = $this->mandant('A');
        $b = $this->mandant('B');

        $ergebnis = $this->exportiere($a['user'], $a['organization']);
        $pfad = (string) $ergebnis->document->getAttribute('storage_path');

        $roh = Storage::disk('local')->get($pfad);
        self::assertIsString($roh);

        foreach ([
            (string) $b['organization']->getKey(),
            (string) $b['property']->getKey(),
            (string) $b['user']->getAttribute('email'),
            (string) $b['invoice']->getAttribute('number'),
            (string) $b['field']->getAttribute('schema_key'),
        ] as $fremd) {
            self::assertNotSame('', $fremd);
            self::assertStringNotContainsString($fremd, $roh, 'Der Export enthaelt Fremddaten: '.$fremd);
        }
    }

    public function test_export_enthaelt_keine_zugangsdaten(): void
    {
        $a = $this->mandant('A');

        $ergebnis = $this->exportiere($a['user'], $a['organization']);
        $eintraege = $this->zipEintraege((string) $ergebnis->document->getAttribute('storage_path'));

        $konto = $eintraege['daten/konto.json'];

        self::assertStringNotContainsString('password', $konto);
        self::assertStringNotContainsString('remember_token', $konto);
        self::assertStringNotContainsString('two_factor_secret', $konto);
        self::assertStringContainsString((string) $a['user']->getAttribute('email'), $konto);
    }

    public function test_export_enthaelt_keine_wiederherstellungscodes_des_zweitfaktors(): void
    {
        // Die Codes sind gehasht, bleiben aber ein Zweitfaktor-Geheimnis und
        // haben in einer Datei, die das Konto nach draussen weitergibt, nichts
        // zu suchen.
        $a = $this->mandant('A');

        $a['user']->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['hash-eins', 'hash-zwei'],
        ])->save();

        $ergebnis = $this->exportiere($a['user'], $a['organization']);
        $eintraege = $this->zipEintraege((string) $ergebnis->document->getAttribute('storage_path'));

        $konto = $eintraege['daten/konto.json'];

        self::assertStringNotContainsString('two_factor_recovery_codes', $konto);
        self::assertStringNotContainsString('hash-eins', $konto);
        self::assertStringNotContainsString('hash-zwei', $konto);

        // Die Tatsache, dass ein Zweitfaktor aktiv ist, darf der Nutzer
        // erfahren; nur das Geheimnis selbst nicht.
        self::assertStringContainsString('two_factor_confirmed_at', $konto);
    }

    public function test_export_wird_als_artefakt_gefuehrt_und_protokolliert(): void
    {
        $a = $this->mandant('A');

        $ergebnis = $this->exportiere($a['user'], $a['organization']);

        self::assertSame(
            GeneratedDocumentKind::DSGVO_EXPORT,
            $ergebnis->document->getAttribute('kind')
        );
        self::assertTrue(Storage::disk('local')->exists((string) $ergebnis->document->getAttribute('storage_path')));
        self::assertGreaterThan(0, $ergebnis->byteSize);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'privacy.export.created',
            'actor_user_id' => $a['user']->getKey(),
            'organization_id' => $a['organization']->getKey(),
        ]);
    }

    public function test_export_nimmt_frueheren_export_nicht_erneut_auf(): void
    {
        $a = $this->mandant('A');

        $erster = $this->exportiere($a['user'], $a['organization']);
        $zweiter = $this->exportiere($a['user'], $a['organization']);

        $eintraege = $this->zipEintraege((string) $zweiter->document->getAttribute('storage_path'));

        foreach (array_keys($eintraege) as $name) {
            self::assertStringNotContainsString('datenexporte/', $name);
            self::assertFalse(str_ends_with($name, '.zip'), 'Der Export enthält ein ZIP: '.$name);
        }

        self::assertNotSame(
            (string) $erster->document->getAttribute('storage_path'),
            (string) $zweiter->document->getAttribute('storage_path'),
        );
    }

    public function test_ein_neuer_export_ersetzt_den_frueheren_export_desselben_nutzers(): void
    {
        $a = $this->mandant('A');

        $erster = $this->exportiere($a['user'], $a['organization']);
        $ersterPfad = (string) $erster->document->getAttribute('storage_path');

        self::assertTrue(Storage::disk('local')->exists($ersterPfad));

        $zweiter = $this->exportiere($a['user'], $a['organization']);

        // Ein Export ist eine Vollkopie aller PDFs. Je Nutzer bleibt nur der
        // juengste bestehen, damit Wiederholungen den Speicher nicht fuellen.
        self::assertFalse(Storage::disk('local')->exists($ersterPfad));
        $this->assertDatabaseMissing('generated_documents', ['id' => $erster->document->getKey()]);
        $this->assertDatabaseHas('generated_documents', ['id' => $zweiter->document->getKey()]);

        self::assertSame(
            1,
            GeneratedDocument::query()
                ->where('kind', GeneratedDocumentKind::DSGVO_EXPORT->value)
                ->count()
        );
    }

    public function test_export_ist_an_den_anfordernden_nutzer_gebunden(): void
    {
        $a = $this->mandant('A');

        $ergebnis = $this->exportiere($a['user'], $a['organization']);

        self::assertSame(
            (string) $a['user']->getKey(),
            $ergebnis->document->getAttribute('requested_by_user_id')
        );
    }

    public function test_der_export_eines_anderen_nutzers_bleibt_bestehen(): void
    {
        $a = $this->mandant('A');
        $b = $this->mandant('B');

        $fremd = $this->exportiere($b['user'], $b['organization']);
        $this->exportiere($a['user'], $a['organization']);

        $this->assertDatabaseHas('generated_documents', ['id' => $fremd->document->getKey()]);
        self::assertTrue(Storage::disk('local')->exists((string) $fremd->document->getAttribute('storage_path')));
    }

    private function exportiere(User $nutzer, Organization $organisation): DataExportResult
    {
        /** @var CreateDataExport $use */
        $use = app(CreateDataExport::class);

        return $use($nutzer, $organisation);
    }
}
