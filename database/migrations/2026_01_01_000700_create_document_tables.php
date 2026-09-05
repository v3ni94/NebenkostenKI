<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|==============================================================================
| DATENSCHUTZ: Dokumente und strukturierte Extraktionsdaten
|==============================================================================
|
| Diese Migration ist datenschutzkritisch. Die folgenden Regeln sind verbindlich
| und duerfen nicht durch spaetere Migrationen aufgeweicht werden.
|
| 1. Die Tabelle "documents" enthaelt KEINE dauerhafte Storage-Referenz auf die
|    Originaldatei und KEINEN Originaldateinamen. Verboten sind insbesondere
|    Spalten wie original_filename, filename, file_name, storage_path, path,
|    storage_key, file_path, disk, url, preview_path, thumbnail_path, ocr_text,
|    full_text, text_layer, exif oder content. Die Testsuite prueft die
|    Spaltenliste ausdruecklich gegen diese Verbotsliste.
|
| 2. Dauerhaft gespeichert werden ausschliesslich: neutrale Quellenbezeichnung
|    (zum Beispiel "Dokument 01 - Grundsteuerbescheid"), Dokumenttyp, MIME-Typ,
|    ursprüngliche Dateigroesse, schluesselgebundener HMAC-SHA-256-Fingerabdruck
|    zur Dublettenerkennung, Verarbeitungsstatus, Seitenzahl, original_deleted_at
|    und deletion_status.
|
| 3. Der temporaere Storage-Key lebt ausschliesslich in "temporary_uploads",
|    zusammen mit expires_at, deletion_attempts und dem letzten Fehler. Nach
|    erfolgreicher Loeschung wird der Datensatz entfernt oder auf einen
|    inhaltslosen Lösch-Tombstone reduziert (storage_key null, is_tombstone true).
|
| 4. "document_pages" speichert nur Seitennummer und Referenzen. Kein
|    vollstaendiger OCR-Text, kein vollstaendiger Text-Layer, kein dauerhafter
|    Vorschauschluessel und kein Seitenbild.
|
| 5. "extracted_fields" speichert Schema-Key, Wert als JSON, Seite, einen kurzen
|    Fundstellenausschnitt, Konfidenz und Status. Die Spalte source_excerpt ist
|    auf 500 Zeichen begrenzt. Zulaessig sind ausschliesslich minimale
|    Ausschnitte, die fuer das konkrete Feld erforderlich sind, niemals ganze
|    Absaetze, Seiten oder Tabellen.
|
| 6. "source_deletion_events" protokolliert Loeschungen datensparsam und ohne
|    jeden Dateiinhalt. Die Spalte document_id ist bewusst ohne Fremdschluessel
|    ausgefuehrt, damit der Loeschnachweis auch nach Entfernen des Dokuments
|    erhalten bleibt.
|
| 7. Fehlende Werte werden niemals geschaetzt. Sie bleiben null und erzeugen eine
|    Pruefaufgabe in validation_issues.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence_number')
                ->comment('Laufende Nummer im Abrechnungslauf, Grundlage der Quellenbezeichnung');
            $table->string('source_label', 190)
                ->comment('Neutrale Quellenbezeichnung, niemals der Originaldateiname');

            $table->string('document_type', 48)->default('SONSTIGES')
                ->comment('PHP-Enum App\Enums\DocumentType');
            $table->decimal('document_type_confidence', 7, 4)->nullable();
            $table->boolean('type_assigned_manually')->default(false);

            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('original_byte_size')->nullable()
                ->comment('Ursprüngliche Dateigroesse in Byte, nur als technische Kennzahl');
            $table->unsignedSmallInteger('page_count')->nullable();

            $table->string('fingerprint_hmac', 64)->nullable()
                ->comment('Schluesselgebundener HMAC-SHA-256, nur zur Dublettenerkennung');

            $table->string('processing_status', 48)->default('HOCHGELADEN')
                ->comment('PHP-Enum App\Enums\DocumentProcessingStatus');
            $table->dateTime('security_checked_at')->nullable();
            $table->string('malware_scanner_driver', 20)->nullable()
                ->comment('clamav, external oder disabled');
            $table->boolean('malware_scan_clean')->nullable();
            $table->dateTime('classified_at')->nullable();
            $table->dateTime('extracted_at')->nullable();

            // Loeschnachweis der Originaldatei.
            $table->dateTime('original_deleted_at')->nullable();
            $table->string('deletion_status', 48)->default('OFFEN')
                ->comment('PHP-Enum App\Enums\DeletionStatus');

            $table->foreignUlid('duplicate_of_document_id')->nullable()
                ->constrained('documents')->nullOnDelete();

            $table->string('failure_code', 120)->nullable();
            $table->string('failure_message', 500)->nullable()
                ->comment('Verstaendliche Meldung, keine Dateiinhalte und keine Rohantworten');

            $table->timestamps();

            $table->unique(['billing_run_id', 'sequence_number']);
            $table->index(['billing_run_id', 'processing_status']);
            $table->index(['billing_run_id', 'document_type']);
            $table->index(['organization_id', 'deletion_status']);
            $table->index('deletion_status', 'documents_deletion_status_monitor_index');
            $table->index('fingerprint_hmac');
        });

        Schema::create('temporary_uploads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();

            $table->string('storage_disk', 40)->default('temporary_uploads')
                ->comment('Verschluesselter Quarantaenebereich ausserhalb des Webroots, ohne Backup');
            $table->string('storage_key', 255)->nullable()
                ->comment('Zufaelliger temporaerer Schluessel, nach Loeschung auf null gesetzt');

            $table->unsignedBigInteger('byte_size')->nullable();
            $table->unsignedSmallInteger('total_chunks')->nullable();
            $table->unsignedSmallInteger('received_chunks')->default(0);
            $table->unsignedBigInteger('received_bytes')->default(0);

            $table->dateTime('first_chunk_at')->nullable()
                ->comment('Beginn der Kurzzeit-TTL nach Abschnitt 19');
            $table->dateTime('expires_at')
                ->comment('Spaetester Loeschzeitpunkt, standardmaessig maximal 120 Minuten');

            $table->unsignedSmallInteger('deletion_attempts')->default(0);
            $table->string('last_error', 500)->nullable()
                ->comment('Letzter Loeschfehler, ohne Dateiinhalt');
            $table->dateTime('deleted_at')->nullable();
            $table->boolean('is_tombstone')->default(false)
                ->comment('Inhaltsloser Lösch-Tombstone nach erfolgreicher Loeschung');

            // Temporaere Providerdatei. Die ID wird nach Abschluss der Verarbeitung
            // entfernt, der Loeschstatus bleibt als Nachweis erhalten.
            $table->string('provider', 48)->nullable()
                ->comment('PHP-Enum App\Enums\AiProvider');
            $table->string('provider_file_id', 190)->nullable();
            $table->dateTime('provider_file_deleted_at')->nullable();
            $table->string('provider_deletion_status', 48)->default('NICHT_ERFORDERLICH')
                ->comment('PHP-Enum App\Enums\DeletionStatus');

            $table->timestamps();

            $table->index('expires_at');
            $table->index(['is_tombstone', 'expires_at']);
            $table->index(['document_id', 'is_tombstone']);
            $table->index('organization_id');
        });

        Schema::create('document_pages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('page_number');
            $table->boolean('has_structured_findings')->default(false);
            $table->unsignedSmallInteger('extracted_field_count')->default(0);

            $table->timestamps();

            $table->unique(['document_id', 'page_number']);
            $table->index('organization_id');
        });

        Schema::create('extracted_fields', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_page_id')->nullable()
                ->constrained('document_pages')->nullOnDelete();

            $table->string('schema_key', 190)
                ->comment('Feldbezeichnung des versionierten JSON-Schemas');
            $table->string('schema_version', 32);
            $table->json('value')->nullable()
                ->comment('Normalisierter Wert. Geldbetraege in Cent, Datumswerte ISO-8601');
            $table->json('corrected_value')->nullable()
                ->comment('Nutzerkorrektur, versioniert zusaetzlich in manual_overrides');

            $table->unsignedSmallInteger('page_number')->nullable();
            $table->string('source_excerpt', 500)->nullable()
                ->comment('Nur minimale Fundstellenausschnitte zulaessig, kein OCR-Volltext');
            $table->decimal('confidence', 7, 4)->nullable()
                ->comment('0,0000 bis 1,0000. Unter 0,80 ist eine explizite Pruefung erforderlich');

            $table->string('status', 48)->default('AUTOMATISCH_ERKANNT')
                ->comment('PHP-Enum App\Enums\ExtractedFieldStatus');
            $table->ulid('ai_call_id')->nullable()
                ->comment('Referenz auf ai_calls, ohne Fremdschluessel wegen Migrationsreihenfolge');
            $table->foreignUlid('confirmed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'schema_key']);
            $table->index(['billing_run_id', 'status']);
            $table->index(['organization_id', 'status']);
            $table->index('ai_call_id');
        });

        Schema::create('document_relations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('from_document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUlid('to_document_id')->constrained('documents')->cascadeOnDelete();

            $table->string('relation_type', 48)
                ->comment('PHP-Enum App\Enums\DocumentRelationType');
            $table->decimal('confidence', 7, 4)->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            // Kurzer Indexname, weil MariaDB Bezeichner auf 64 Zeichen begrenzt.
            $table->unique(
                ['from_document_id', 'to_document_id', 'relation_type'],
                'document_relations_pair_unique'
            );
            $table->index(['billing_run_id', 'relation_type']);
        });

        Schema::create('source_deletion_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->nullable()
                ->comment('Ohne Fremdschluessel, damit der Nachweis erhalten bleibt');
            $table->ulid('document_id')->nullable()
                ->comment('Ohne Fremdschluessel, damit der Nachweis erhalten bleibt');
            $table->ulid('temporary_upload_id')->nullable();

            $table->string('local_deletion_status', 48)->default('OFFEN')
                ->comment('PHP-Enum App\Enums\DeletionStatus');
            $table->string('provider_deletion_status', 48)->default('NICHT_ERFORDERLICH')
                ->comment('PHP-Enum App\Enums\DeletionStatus');
            $table->string('provider', 48)->nullable()
                ->comment('PHP-Enum App\Enums\AiProvider');

            $table->dateTime('occurred_at');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('error_code', 120)->nullable();
            $table->string('error_message', 500)->nullable()
                ->comment('Nur Fehlertext, niemals Dateiinhalte oder Dateinamen');

            $table->timestamps();

            // Kurze Indexnamen, weil MariaDB Bezeichner auf 64 Zeichen begrenzt.
            $table->index('document_id');
            $table->index(['local_deletion_status', 'occurred_at'], 'deletion_events_local_index');
            $table->index(['provider_deletion_status', 'occurred_at'], 'deletion_events_provider_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_deletion_events');
        Schema::dropIfExists('document_relations');
        Schema::dropIfExists('extracted_fields');
        Schema::dropIfExists('document_pages');
        Schema::dropIfExists('temporary_uploads');
        Schema::dropIfExists('documents');
    }
};
