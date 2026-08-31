<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|==============================================================================
| DATENSCHUTZ: erzeugte Ergebnisartefakte
|==============================================================================
|
| Diese Tabelle ist die EINZIGE Stelle des Datenmodells mit einer dauerhaften
| Storage-Referenz. Sie gilt ausschliesslich fuer vom System erzeugte Artefakte:
| Vorschau-PDFs, Final-PDFs, ZIP-Pakete, HVM-Rechnungen und DSGVO-Exporte.
|
| Hochgeladene Originalbelege, Originalbilder, Seitenbilder und Office-Dateien
| duerfen hier niemals eingetragen werden. Sie liegen ausschliesslich temporaer
| in temporary_uploads und werden nach der Auswertung geloescht.
|
| Vorschau und Finalversion werden getrennt gespeichert. Die Vorschau traegt ein
| serverseitig eingebranntes Wasserzeichen auf jeder Seite. Der Zugriff erfolgt
| ausschliesslich ueber autorisierte Streaming-Routen oder kurzlebige signierte
| Links, niemals ueber einen oeffentlichen Pfad.
|
| Ein finalisiertes PDF wird niemals ueberschrieben. Eine Korrektur erzeugt ein
| neues Artefakt, das alte erhaelt den Status ERSETZT.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('billing_run_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_statement_id')->nullable()
                ->constrained('unit_statements')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->nullable()
                ->constrained('invoices')->nullOnDelete();
            $table->foreignUlid('calculation_snapshot_id')->nullable()
                ->constrained('calculation_snapshots')->nullOnDelete();
            $table->foreignUlid('replaced_by_document_id')->nullable()
                ->constrained('generated_documents')->nullOnDelete();

            $table->string('kind', 48)
                ->comment('PHP-Enum App\Enums\GeneratedDocumentKind');
            $table->string('variant', 48)
                ->comment('PHP-Enum App\Enums\GeneratedDocumentVariant');
            $table->string('status', 48)->default('AKTIV')
                ->comment('PHP-Enum App\Enums\GeneratedDocumentStatus');

            $table->string('storage_disk', 40)->default('sftp')
                ->comment('Nur erzeugte Artefakte, niemals Originalbelege');
            $table->string('storage_path', 500);
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->string('template_version', 32)->nullable();
            $table->timestamp('generated_at');

            $table->timestamps();

            $table->index(['billing_run_id', 'kind', 'variant'], 'generated_docs_run_kind_index');
            $table->index(['organization_id', 'status']);
            $table->index('unit_statement_id');
            $table->index('invoice_id');
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
