<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Mandant, Rollen und Rechtsnachweise
|------------------------------------------------------------------------------
|
| Die Organisation ist der Mandant. Jeder Nutzer erhaelt bei der Registrierung
| mindestens eine eigene Organisation, damit alle Kundendaten ueber genau eine
| Mandantenspalte "organization_id" scopebar sind. Die Autorisierung erfolgt
| zusaetzlich ueber Policies mit Object-Level-Check, niemals allein ueber eine
| erratbare URL-ID.
|
| Adminrollen liegen in einer eigenen Tabelle und sind vollstaendig von den
| Kundenrollen getrennt.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('name');
            $table->string('type', 48)->default('PRIVATPERSON')
                ->comment('PHP-Enum App\Enums\OrganizationType');
            $table->string('legal_form', 120)->nullable();

            // Rechnungsanschrift des Kunden fuer die HVM-Leistungsrechnung.
            $table->string('billing_name')->nullable();
            $table->string('billing_address_line')->nullable();
            $table->string('billing_address_extra')->nullable();
            $table->string('billing_postal_code', 16)->nullable();
            $table->string('billing_city', 120)->nullable();
            $table->string('billing_country', 2)->default('DE');

            $table->string('vat_id', 32)->nullable()
                ->comment('USt-IdNr. Keine Steuernummer, nur soweit erforderlich');
            $table->string('contact_email')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('deleted_at');
        });

        Schema::create('organization_user', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('role', 48)->default('MEMBER')
                ->comment('PHP-Enum App\Enums\OrganizationRole');
            $table->dateTime('invited_at')->nullable();
            $table->dateTime('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });

        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('role', 48)
                ->comment('PHP-Enum App\Enums\AdminRole, getrennt von Kundenrollen');
            $table->foreignUlid('granted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('granted_at');
            $table->dateTime('revoked_at')->nullable();
            $table->string('reason', 500)->nullable()
                ->comment('Begruendung fuer Supportzugriff, siehe audit_logs');
            $table->timestamps();

            $table->unique(['user_id', 'role']);
            $table->index(['role', 'revoked_at']);
        });

        Schema::create('legal_acceptances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();

            // Bezug auf einen Abrechnungslauf wird nachtraeglich als Index gefuehrt,
            // die Fremdschluesselbeziehung liegt fachlich auf billing_runs. Um eine
            // zirkulaere Migrationsreihenfolge zu vermeiden, bleibt hier nur die ULID.
            $table->ulid('billing_run_id')->nullable();

            $table->string('purpose', 48)
                ->comment('PHP-Enum App\Enums\LegalDocumentPurpose');
            $table->string('document_version', 32)
                ->comment('Versionierte Textfassung, damit alte Zustimmungen belegbar bleiben');
            $table->string('document_hash', 64)->nullable()
                ->comment('SHA-256 der akzeptierten Textfassung');
            $table->dateTime('accepted_at');

            $table->string('ip_truncated', 45)->nullable()
                ->comment('Datensparsam gekuerzte IP, keine vollstaendige Adresse');
            $table->string('user_agent_hash', 64)->nullable()
                ->comment('Gehashter User-Agent, kein Klartext');

            $table->timestamps();

            $table->index(['user_id', 'purpose']);
            $table->index('billing_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('admin_roles');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
