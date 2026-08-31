<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Mietverhaeltnisse, Personen, Belegung und Leerstand
|------------------------------------------------------------------------------
|
| Zeitraeume werden als DATE gespeichert und in der Domainschicht taggenau
| einschliesslich Start- und Endtag ausgewertet. Ueberschneidungen und Luecken
| werden von der Regel-Engine geprueft, nicht durch CHECK-Constraints, weil
| solche Pruefungen Subqueries benoetigen und auf MariaDB 10.11 nicht zulaessig
| beziehungsweise nicht portabel sind.
|
| Leerstandskosten bleiben beim Eigentuemer. Ein Leerstandszeitraum ersetzt kein
| Mietverhaeltnis, sondern dokumentiert die Zeit ohne Mieter.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancies', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 48)->default('WOHNRAUM')
                ->comment('PHP-Enum App\Enums\TenancyKind, GEWERBE blockiert die Finalisierung');
            $table->string('status', 48)->default('AKTIV')
                ->comment('PHP-Enum App\Enums\TenancyStatus');

            $table->string('tenant_display_name')
                ->comment('Anzeigename im Anschriftfeld der Mieterabrechnung');

            // Zustellanschrift. Bei ausgezogenem Mieter ist sie zwingend erforderlich,
            // die Regel-Engine erzeugt sonst einen Blocker.
            $table->string('delivery_address_line')->nullable();
            $table->string('delivery_address_extra')->nullable();
            $table->string('delivery_postal_code', 16)->nullable();
            $table->string('delivery_city', 120)->nullable();
            $table->string('delivery_country', 2)->default('DE');

            $table->date('starts_on');
            $table->date('ends_on')->nullable()->comment('Leer bedeutet laufendes Mietverhaeltnis');

            // Vertragliche Vorauszahlungen als Sollwerte. Der tatsaechliche Abzug in der
            // Abrechnung erfolgt ueber die Tabelle prepayments.
            $table->bigInteger('monthly_operating_prepayment_cent')->nullable();
            $table->bigInteger('monthly_heating_prepayment_cent')->nullable();
            $table->boolean('heating_prepayment_separate')->default(false);

            // Vertragsgrundlagen. Null bedeutet ausdruecklich unbekannt und erzeugt eine
            // Pruefaufgabe, es wird niemals eine Vereinbarung unterstellt.
            $table->boolean('operating_costs_apportionment_agreed')->nullable();
            $table->boolean('other_operating_costs_agreed')->nullable()
                ->comment('Grundlage fuer die Kategorie sonstige Betriebskosten');

            $table->string('contract_data_source', 48)->nullable()
                ->comment('PHP-Enum App\Enums\ValueSource');
            $table->ulid('contract_document_id')->nullable()
                ->comment('Referenz auf documents, ohne Fremdschluessel wegen Migrationsreihenfolge');

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['unit_id', 'starts_on']);
            $table->index(['unit_id', 'ends_on']);
            $table->index('contract_document_id');
            $table->index('deleted_at');
        });

        Schema::create('tenancy_persons', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tenancy_id')->constrained()->cascadeOnDelete();

            $table->string('salutation', 40)->nullable();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120);
            $table->string('email')->nullable()
                ->comment('Nur soweit fuer die Korrespondenz erforderlich');
            $table->boolean('is_primary_contact')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->timestamps();

            $table->index(['tenancy_id', 'is_primary_contact']);
        });

        Schema::create('occupancy_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tenancy_id')->constrained()->cascadeOnDelete();

            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('person_count')
                ->comment('Grundlage fuer Personen und Personentage');
            $table->string('source', 48)->default('MANUELL')
                ->comment('PHP-Enum App\Enums\ValueSource');

            $table->timestamps();

            $table->index(['tenancy_id', 'starts_on']);
        });

        Schema::create('vacancy_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->constrained()->cascadeOnDelete();

            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 190)->nullable();

            $table->timestamps();

            $table->index(['unit_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_periods');
        Schema::dropIfExists('occupancy_periods');
        Schema::dropIfExists('tenancy_persons');
        Schema::dropIfExists('tenancies');
    }
};
