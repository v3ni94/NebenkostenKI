<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Mieterabrechnungen, Rechenzeilen, Pruefergebnisse und Nutzerkorrekturen
|------------------------------------------------------------------------------
|
| Dokumentierte Genauigkeiten:
|   Geldbetraege  BIGINT in Cent
|   Zaehler       DECIMAL(20,6)
|   Nenner        DECIMAL(20,6)
|   Zeitfaktor    DECIMAL(12,8)  Nutzungstage geteilt durch Zeitraumtage
|
| Die interne Berechnung erfolgt in der Domainschicht mit hoher Dezimalpraezision.
| Gerundet wird erst am definierten Ende einer Kostenzeile. Rundungsdifferenzen
| werden deterministisch nach dem Largest-Remainder-Verfahren verteilt und je
| Zeile in rounding_adjustment_cent nachgewiesen. Die Summe der Einzelanteile
| muss exakt der zu verteilenden Summe entsprechen.
|
| balance_cent ist positiv bei Nachzahlung des Mieters und negativ bei Guthaben.
| Diese Vorzeichenkonvention ist verbindlich fuer Domainschicht und PDF.
|
| Eine finalisierte Mieterabrechnung wird niemals ueberschrieben. Korrekturen
| erzeugen eine neue Version, die alte behaelt den Status ERSETZT.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_statements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tenancy_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('calculation_snapshot_id')->nullable()
                ->constrained('calculation_snapshots')->nullOnDelete();
            $table->foreignUlid('replaced_by_statement_id')->nullable()
                ->constrained('unit_statements')->nullOnDelete();

            $table->unsignedSmallInteger('sequence_number')
                ->comment('Laufende Nummer im Lauf. Preisgrundlage ist die erzeugte Abrechnung');
            $table->unsignedInteger('version_number')->default(1);

            $table->date('usage_period_start');
            $table->date('usage_period_end');
            $table->unsignedSmallInteger('days_used')
                ->comment('Kalendertage taggenau, Start- und Endtag eingeschlossen');
            $table->unsignedSmallInteger('period_days')
                ->comment('Tage des Abrechnungszeitraums, Schaltjahr beruecksichtigt');

            $table->bigInteger('total_apportionable_cent')->default(0);
            $table->bigInteger('total_heating_cent')->default(0);
            $table->bigInteger('total_excluded_cent')->default(0)
                ->comment('Nicht umlagefaehige Kosten, verbleiben beim Eigentuemer');
            $table->bigInteger('prepayment_target_cent')->default(0);
            $table->bigInteger('prepayment_actual_cent')->default(0);
            $table->bigInteger('balance_cent')->default(0)
                ->comment('Positiv Nachzahlung, negativ Guthaben');
            $table->bigInteger('rounding_adjustment_total_cent')->default(0);

            $table->bigInteger('paragraph_35a_household_cent')->nullable();
            $table->bigInteger('paragraph_35a_craftsman_cent')->nullable();

            $table->string('result_kind', 48)->default('AUSGEGLICHEN')
                ->comment('PHP-Enum App\Enums\StatementResultKind');
            $table->string('status', 48)->default('BERECHNET')
                ->comment('PHP-Enum App\Enums\UnitStatementStatus');

            $table->timestamps();

            $table->unique(
                ['billing_run_id', 'tenancy_id', 'version_number'],
                'unit_statements_version_unique'
            );
            $table->index(['billing_run_id', 'status']);
            $table->index(['organization_id', 'status']);
            $table->index('tenancy_id');
            $table->index('calculation_snapshot_id');
        });

        Schema::create('unit_statement_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_statement_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cost_category_id')->nullable()
                ->constrained('cost_categories')->nullOnDelete();

            $table->string('category_label', 190)
                ->comment('Anzeigetext der Kostenart im PDF');
            $table->string('betrkv_reference', 190)->nullable();

            $table->bigInteger('total_cost_cent')
                ->comment('Gesamtkosten der Kostenart im Abrechnungszeitraum');
            $table->string('allocation_key_type', 48)
                ->comment('PHP-Enum App\Enums\AllocationKeyType');
            $table->string('allocation_key_label', 190)
                ->comment('Schluesseltext, zum Beispiel Wohnfläche in Quadratmetern');

            $table->decimal('numerator', 20, 6)->nullable();
            $table->decimal('denominator', 20, 6)->nullable();
            $table->decimal('time_factor', 12, 8)->default(1)
                ->comment('Nutzungstage geteilt durch Tage des Abrechnungszeitraums');

            $table->bigInteger('share_cent')
                ->comment('Mieteranteil nach Rundung einschliesslich Ausgleich');
            $table->bigInteger('rounding_adjustment_cent')->default(0);

            $table->boolean('is_heating_line')->default(false);
            $table->bigInteger('paragraph_35a_labor_cent')->nullable();
            $table->string('paragraph_35a_type', 48)->default('NONE')
                ->comment('PHP-Enum App\Enums\Paragraph35aType');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['unit_statement_id', 'sort_order'], 'statement_lines_order_index');
            $table->index('cost_category_id');
            $table->index('organization_id');
        });

        Schema::create('validation_issues', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->constrained()->cascadeOnDelete();

            $table->string('rule_code', 80)->comment('Stabiler Regelcode, zum Beispiel BK-ANP-014');
            $table->string('rule_version', 32);
            $table->string('severity', 48)
                ->comment('PHP-Enum App\Enums\ValidationSeverity');
            $table->string('status', 48)->default('OFFEN')
                ->comment('PHP-Enum App\Enums\ValidationIssueStatus');
            $table->boolean('blocks_finalization')->default(false);

            // Betroffene Entitaet als polymorphe Referenz ohne Fremdschluessel, weil die
            // Regel-Engine ueber viele Tabellen hinweg prueft.
            $table->string('entity_type', 120)->nullable();
            $table->ulid('entity_id')->nullable();

            $table->string('title', 190);
            $table->text('description');
            $table->string('legal_reference', 190)->nullable()
                ->comment('Fachliche oder rechtliche Referenz, nur wenn gesichert');
            $table->text('resolution')->nullable();

            $table->timestamp('detected_at');
            $table->foreignUlid('resolved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['billing_run_id', 'severity']);
            $table->index(['billing_run_id', 'status']);
            $table->index(['rule_code', 'rule_version']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('organization_id');
        });

        Schema::create('manual_overrides', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('subject_type', 120);
            $table->ulid('subject_id');
            $table->string('field', 120);

            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('reason', 500)->nullable()
                ->comment('Pflichtangabe bei Aenderung der Umlagebewertung');
            $table->timestamp('occurred_at');

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['billing_run_id', 'occurred_at']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_overrides');
        Schema::dropIfExists('validation_issues');
        Schema::dropIfExists('unit_statement_lines');
        Schema::dropIfExists('unit_statements');
    }
};
