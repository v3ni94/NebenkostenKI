<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\DeletionStatus;
use App\Models\Document;
use App\Models\SourceDeletionEvent;
use App\Models\TemporaryUpload;

/**
 * Datenschutzmonitor (Masterprompt 19, 20).
 *
 * Geprueft wird, dass ueberfaellige und fehlgeschlagene Loeschungen sichtbar
 * sind und dass keine Dateinamen, Storage-Keys oder Provider-Datei-IDs in die
 * Oberflaeche gelangen.
 */
final class DatenschutzmonitorTest extends AdminTestCase
{
    public function test_ueberfaellige_temporaere_uploads_werden_angezeigt(): void
    {
        /** @var Document $dokument */
        $dokument = Document::factory()->create();

        TemporaryUpload::factory()->create([
            'document_id' => $dokument->getKey(),
            'organization_id' => $dokument->getAttribute('organization_id'),
            'expires_at' => now()->subMinutes(45),
            'deletion_attempts' => 2,
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/datenschutz');

        $antwort->assertOk();
        $antwort->assertSee('Überfällige temporäre Uploads');
        $antwort->assertSee('45 Minuten');
        $antwort->assertSee((string) $dokument->getKey());
    }

    public function test_fehlgeschlagene_loeschungen_erscheinen_als_kritischer_alarm_mit_fehlercode(): void
    {
        /** @var Document $dokument */
        $dokument = Document::factory()->create([
            'deletion_status' => DeletionStatus::FEHLGESCHLAGEN,
        ]);

        SourceDeletionEvent::query()->create([
            'document_id' => $dokument->getKey(),
            'local_deletion_status' => DeletionStatus::FEHLGESCHLAGEN,
            'provider_deletion_status' => DeletionStatus::NICHT_ERFORDERLICH,
            'occurred_at' => now()->subHours(3),
            'attempt' => 3,
            'error_code' => 'STORAGE_UNAVAILABLE',
            'error_message' => 'Temporaerer Bereich nicht erreichbar',
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/datenschutz');

        $antwort->assertOk();
        $antwort->assertSee('Kritischer Datenschutzalarm');
        $antwort->assertSee('STORAGE_UNAVAILABLE');
        $antwort->assertSee('Löschung erneut anstoßen');
    }

    public function test_der_monitor_zeigt_keinen_storage_key_und_keine_provider_datei_id(): void
    {
        /** @var Document $dokument */
        $dokument = Document::factory()->create([
            'deletion_status' => DeletionStatus::UEBERFAELLIG,
        ]);

        TemporaryUpload::factory()->create([
            'document_id' => $dokument->getKey(),
            'organization_id' => $dokument->getAttribute('organization_id'),
            'storage_key' => 'quarantaene/geheimer-testschluessel-0001',
            'expires_at' => now()->subMinutes(10),
            'provider' => 'OPENAI',
            'provider_file_id' => 'file-testkennung-0002',
            'provider_deletion_status' => DeletionStatus::OFFEN,
            'last_error' => 'RuntimeException: /var/pfad/zur/datei nicht loeschbar',
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/datenschutz');

        $antwort->assertOk();
        $antwort->assertDontSee('quarantaene/geheimer-testschluessel-0001');
        $antwort->assertDontSee('file-testkennung-0002');
        $antwort->assertDontSee('/var/pfad/zur/datei');
    }

    public function test_offene_providerloeschungen_werden_gezaehlt(): void
    {
        /** @var Document $dokument */
        $dokument = Document::factory()->create();

        TemporaryUpload::factory()->create([
            'document_id' => $dokument->getKey(),
            'organization_id' => $dokument->getAttribute('organization_id'),
            'provider' => 'ANTHROPIC',
            'provider_file_id' => 'file-testkennung-0003',
            'provider_deletion_status' => DeletionStatus::OFFEN,
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/datenschutz');

        $antwort->assertOk();
        $antwort->assertSee('Offene Providerlöschungen');
        $antwort->assertSee('ANTHROPIC');
    }

    public function test_die_wiederholung_der_loeschung_ist_ausloesbar_und_wird_protokolliert(): void
    {
        /** @var Document $dokument */
        $dokument = Document::factory()->create([
            'deletion_status' => DeletionStatus::FEHLGESCHLAGEN,
        ]);

        $antwort = $this->actingAs($this->interneKennung())
            ->post('/admin/datenschutz/loeschungen/wiederholen');

        $antwort->assertRedirect('/admin/datenschutz');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.deletion.retried',
        ]);

        self::assertNotNull(Document::query()->find($dokument->getKey()));
    }

    public function test_ohne_alarm_bleibt_der_monitor_ruhig(): void
    {
        $antwort = $this->actingAs($this->interneKennung())->get('/admin/datenschutz');

        $antwort->assertOk();
        $antwort->assertDontSee('Kritischer Datenschutzalarm');
    }
}
