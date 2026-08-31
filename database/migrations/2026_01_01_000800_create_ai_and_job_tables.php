<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|==============================================================================
| DATENSCHUTZ: KI-Aufrufe, Promptversionen und Teiljobs
|==============================================================================
|
| 1. "ai_calls" speichert KEINE rohen Prompts, KEINE rohen Antworten, keine
|    Base64-Dateiinhalte und keine Provider-Datei-IDs. Persistiert werden nur
|    technische Nachweismetadaten: Provider, Modell, Zweck, Promptversion,
|    Request-ID, Tokenzahlen, Kosten, Dauer, Status und Fehlercode. Nach der
|    JSON-Schema-Validierung wird die rohe Modellantwort verworfen.
|
| 2. "processing_jobs" enthaelt eine bewusst datensparsame Nutzlast. In payload
|    duerfen ausschliesslich Referenz-IDs und technische Parameter stehen,
|    niemals Dateiinhalte, OCR-Texte, Prompts oder personenbezogene Klartexte.
|    Queue-Payloads sind aus Backups auszuschliessen.
|
| 3. Lease, Heartbeat, Versuche und exponentieller Backoff erlauben den Betrieb
|    ohne dauerhaften Worker (IONOS Profil A). Jobs sind idempotent und
|    wiederanlaufbar, ein endgueltiger Fehler fuehrt in den Status DEAD_LETTER.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('purpose', 48)
                ->comment('PHP-Enum App\Enums\AiCallPurpose');
            $table->string('version', 32);
            $table->string('hash', 64)->comment('SHA-256 des Prompttextes');
            $table->boolean('is_active')->default(false);
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('deactivated_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['purpose', 'version']);
            $table->index(['purpose', 'is_active']);
        });

        Schema::create('ai_calls', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('organization_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('ai_prompt_version_id')->nullable()
                ->constrained('ai_prompt_versions')->nullOnDelete();

            $table->string('provider', 48)->comment('PHP-Enum App\Enums\AiProvider');
            $table->string('model', 120)->comment('Konkrete Modell-ID, immer protokolliert');
            $table->string('purpose', 48)->comment('PHP-Enum App\Enums\AiCallPurpose');
            $table->string('request_id', 190)->nullable()
                ->comment('Technische Request-ID des Providers, kein Inhalt');

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cached_tokens')->nullable();
            $table->unsignedSmallInteger('file_count')->default(0);
            $table->bigInteger('cost_cent')->nullable()
                ->comment('Kosten in Cent, kaufmaennisch gerundet');
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('status', 48)->comment('PHP-Enum App\Enums\AiCallStatus');
            $table->boolean('schema_valid')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('error_code', 120)->nullable();
            $table->string('error_message', 500)->nullable()
                ->comment('Nur technische Fehlermeldung, keine Roh-Antwort');

            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['billing_run_id', 'purpose']);
            $table->index(['provider', 'status']);
            $table->index('document_id');
        });

        Schema::create('processing_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('organization_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->string('job_type', 120);
            $table->string('status', 48)->default('BEREIT')
                ->comment('PHP-Enum App\Enums\ProcessingJobStatus');
            $table->unsignedTinyInteger('priority')->default(100);

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->string('lease_owner', 120)->nullable();
            $table->dateTime('leased_until')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('available_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();

            $table->json('payload')->nullable()
                ->comment('Nur Referenz-IDs und technische Parameter, keine Inhalte');
            $table->string('error_code', 120)->nullable();
            $table->string('last_error', 500)->nullable();

            $table->timestamps();

            $table->index(['status', 'available_at', 'priority']);
            $table->index(['billing_run_id', 'status']);
            $table->index('leased_until');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_jobs');
        Schema::dropIfExists('ai_calls');
        Schema::dropIfExists('ai_prompt_versions');
    }
};
