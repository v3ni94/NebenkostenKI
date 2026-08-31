<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| E-Mail, Erinnerungen und Revisionsprotokoll
|------------------------------------------------------------------------------
|
| 1. email_messages protokolliert Versandstatus und Fehler, niemals Passwoerter,
|    Tokens, Downloadlinks oder vertrauliche Inhalte. Finale Mieterabrechnungen
|    werden nicht unverschluesselt angehaengt, sondern ueber einen zeitlich
|    begrenzten kontogebundenen Downloadlink bereitgestellt.
|
| 2. reminder_events.deduplication_key ist eindeutig und verhindert Dubletten
|    innerhalb eines Erinnerungsfensters. Empfohlene Bildung durch die
|    Anwendungsschicht: user, property, Jahr, Fenster.
|
| 3. Bei reminder_preferences bildet die globale Einstellung eine Zeile mit
|    property_id null. MariaDB und SQLite lassen mehrere NULL-Werte in einem
|    Unique-Index zu, daher stellt die Anwendungsschicht sicher, dass je Nutzer
|    hoechstens eine globale Zeile existiert. Ein Teilindex waere nicht portabel.
|
| 4. audit_logs speichert nur gekuerzte IP-Adressen und gehashte User-Agents.
|    In metadata gehoeren ausschliesslich technische Kennzahlen und Referenz-IDs.
|
| 5. Die Spalte heisst reminder_window, weil WINDOW in MariaDB ein reserviertes
|    Wort ist.
|
| 6. Alle Zeitstempel liegen in UTC. Die fachlichen Erinnerungstermine des
|    Abschnitts 17 werden von der Anwendungsschicht in Europe/Berlin berechnet
|    und in UTC gespeichert.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('billing_run_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->string('template', 120);
            $table->string('recipient_email');
            $table->string('subject', 190);

            $table->string('status', 48)->default('WARTEND')
                ->comment('PHP-Enum App\Enums\EmailStatus');
            $table->string('message_id', 190)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->dateTime('queued_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->string('error_message', 500)->nullable()
                ->comment('Keine Passwoerter, Tokens oder vertraulichen Inhalte');

            $table->timestamps();

            $table->index(['status', 'queued_at']);
            $table->index(['user_id', 'template']);
            $table->index('billing_run_id');
        });

        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('email');
            $table->string('reason', 48)
                ->comment('PHP-Enum App\Enums\EmailSuppressionReason');
            $table->dateTime('suppressed_at');
            $table->string('source', 120)->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->unique('email');
            $table->index('reason');
        });

        Schema::create('reminder_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->nullable()
                ->comment('Leer bedeutet globale Einstellung des Nutzers')
                ->constrained()->cascadeOnDelete();

            $table->boolean('is_active')->default(true);
            $table->boolean('q1_enabled')->default(true);
            $table->boolean('q2_enabled')->default(true);
            $table->boolean('q3_enabled')->default(true);
            $table->boolean('december_enabled')->default(true);

            $table->string('unsubscribe_token', 64)
                ->comment('Sicherer Abmeldelink ohne Login, nur fuer Erinnerungen');
            $table->dateTime('deactivated_at')->nullable();
            $table->dateTime('reactivated_at')->nullable();

            $table->timestamps();

            $table->unique('unsubscribe_token');
            $table->unique(['user_id', 'property_id']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('reminder_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('property_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('email_message_id')->nullable()
                ->constrained('email_messages')->nullOnDelete();

            $table->unsignedSmallInteger('billing_year');
            $table->string('reminder_window', 48)
                ->comment('PHP-Enum App\Enums\ReminderWindow');
            $table->string('recipient_email');
            $table->string('deduplication_key', 190);

            $table->string('status', 48)->default('GEPLANT')
                ->comment('PHP-Enum App\Enums\ReminderStatus');
            $table->dateTime('scheduled_for');
            $table->dateTime('sent_at')->nullable();
            $table->string('suppressed_reason', 190)->nullable();

            $table->timestamps();

            $table->unique('deduplication_key');
            $table->index(['status', 'scheduled_for']);
            $table->index(['property_id', 'billing_year']);
            $table->index('user_id');
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('actor_admin_role', 48)->nullable()
                ->comment('PHP-Enum App\Enums\AdminRole, nur bei internem Zugriff');

            $table->string('action', 120);
            $table->string('subject_type', 120)->nullable();
            $table->ulid('subject_id')->nullable();

            $table->dateTime('occurred_at');
            $table->string('ip_truncated', 45)->nullable()
                ->comment('Datensparsam gekuerzte IP, keine vollstaendige Adresse');
            $table->string('user_agent_hash', 64)->nullable();
            $table->json('metadata')->nullable()
                ->comment('Nur technische Kennzahlen und Referenz-IDs, keine Secrets');
            $table->string('reason', 500)->nullable()
                ->comment('Pflichtangabe bei Supportzugriff');

            $table->timestamps();

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'occurred_at']);
            $table->index('actor_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('reminder_events');
        Schema::dropIfExists('reminder_preferences');
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('email_messages');
    }
};
