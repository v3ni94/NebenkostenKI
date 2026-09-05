<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Kostenpositionen, Verteilerschluessel, Vorauszahlungen und Heizkosten
|------------------------------------------------------------------------------
|
| Dokumentierte Genauigkeiten:
|   Geldbetraege  BIGINT in Cent, Spaltenname endet auf _cent
|   Zaehler       DECIMAL(20,6)  Einzelwert eines Verteilerschluessels
|   Nenner        DECIMAL(20,6)  Summe aller Zaehler, nie null oder negativ
|   Verbrauch     DECIMAL(14,4)
|   Prozent       DECIMAL(7,4)
|
| Nicht umlagefaehige und pruefpflichtige Positionen sind standardmaessig aus der
| Mieterumlage ausgeschlossen. Eine Aenderung erfordert eine Begruendung in
| apportionment_override_reason, wird in manual_overrides versioniert und ist
| ausdruecklich keine juristische Freigabe.
|
| Hausgeldvorauszahlungen, Abrechnungsspitze, Ruecklagenzufuehrung, Verwalter-,
| Bank- und Rechtskosten sowie Instandhaltung duerfen nicht als Mietnebenkosten
| uebernommen werden. Die Bewertung liegt in cost_categories und wird je Position
| in apportionment_status gespiegelt.
|
| In der Abrechnung werden ausschliesslich tatsaechlich geleistete
| Vorauszahlungen abgezogen. Sollwerte dienen der Plausibilisierung. Die Annahme
| Ist gleich Soll ist nur mit assumed_equal_to_target und Bestaetigung zulaessig.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cost_category_id')->nullable()
                ->constrained('cost_categories')->nullOnDelete();
            $table->foreignUlid('document_id')->nullable()
                ->comment('Quellendokument, Original bereits geloescht')
                ->constrained('documents')->nullOnDelete();

            $table->string('description', 190);
            $table->string('supplier_name', 190)->nullable();
            $table->string('invoice_number', 120)->nullable();

            $table->bigInteger('amount_cent');
            $table->bigInteger('net_amount_cent')->nullable();
            $table->bigInteger('vat_amount_cent')->nullable();
            $table->decimal('vat_rate_percent', 7, 4)->nullable();

            $table->date('document_date')->nullable()->comment('Beleg- oder Bescheiddatum');
            $table->date('service_period_start')->nullable();
            $table->date('service_period_end')->nullable();

            $table->string('source', 48)->default('KI_EXTRAKTION')
                ->comment('PHP-Enum App\Enums\CostItemSource');
            $table->string('status', 48)->default('VORGESCHLAGEN')
                ->comment('PHP-Enum App\Enums\CostItemStatus');
            $table->string('apportionment_status', 48)->default('PRUEFPFLICHTIG')
                ->comment('PHP-Enum App\Enums\ApportionmentStatus');
            $table->boolean('excluded_from_apportionment')->default(false);
            $table->string('apportionment_override_reason', 500)->nullable()
                ->comment('Pflichtangabe bei Abweichung vom Kategoriestandard');

            $table->string('allocation_key_type', 48)->nullable()
                ->comment('PHP-Enum App\Enums\AllocationKeyType, überschreibt den Kategoriestandard');

            // Direktzuordnung an eine Einheit oder ein Mietverhaeltnis.
            $table->foreignUlid('direct_unit_id')->nullable()
                ->constrained('units')->nullOnDelete();
            $table->foreignUlid('direct_tenancy_id')->nullable()
                ->constrained('tenancies')->nullOnDelete();

            $table->bigInteger('labor_share_cent')->nullable()
                ->comment('Nur nachgewiesener Lohnanteil, niemals Materialkosten');
            $table->string('paragraph_35a_type', 48)->default('NONE')
                ->comment('PHP-Enum App\Enums\Paragraph35aType');

            $table->boolean('is_heating_cost')->default(false);
            $table->boolean('is_warm_water_cost')->default(false);

            $table->foreignUlid('duplicate_of_cost_item_id')->nullable()
                ->constrained('cost_items')->nullOnDelete();
            $table->decimal('duplicate_confidence', 7, 4)->nullable();

            $table->decimal('confidence', 7, 4)->nullable();
            $table->unsignedSmallInteger('source_page')->nullable();
            $table->bigInteger('prior_year_amount_cent')->nullable()
                ->comment('Nur Vergleichswert aus dem Vorjahr, niemals neue Kosten');

            $table->foreignUlid('confirmed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['billing_run_id', 'status']);
            $table->index(['billing_run_id', 'cost_category_id']);
            $table->index(['billing_run_id', 'apportionment_status']);
            $table->index(['organization_id', 'document_date']);
            $table->index('document_id');
            $table->index('direct_unit_id');
        });

        Schema::create('allocation_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('cost_category_id')->nullable()
                ->constrained('cost_categories')->nullOnDelete();
            $table->foreignUlid('cost_item_id')->nullable()
                ->comment('Gesetzt, wenn der Schluessel nur fuer eine Position gilt')
                ->constrained('cost_items')->cascadeOnDelete();

            $table->string('key_type', 48)
                ->comment('PHP-Enum App\Enums\AllocationKeyType');
            $table->string('source', 48)
                ->comment('PHP-Enum App\Enums\AllocationKeySource, DEFAULT erzeugt einen Warnhinweis');
            $table->decimal('denominator', 20, 6)->nullable()
                ->comment('Gesamtnenner. Null oder negativ ist ein Blocker der Regel-Engine');
            $table->string('measurement_unit', 20)->nullable();
            $table->string('label', 190)->nullable()
                ->comment('Schluesseltext fuer die Mieterabrechnung');

            $table->foreignUlid('confirmed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['billing_run_id', 'key_type']);
            $table->index(['billing_run_id', 'cost_category_id']);
            $table->index('cost_item_id');
            $table->index('organization_id');
        });

        Schema::create('allocation_key_values', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('allocation_key_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('tenancy_id')->nullable()->constrained()->cascadeOnDelete();

            $table->decimal('numerator', 20, 6)
                ->comment('Individueller Zaehler der Einheit beziehungsweise des Mietverhaeltnisses');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('source', 48)->default('MANUELL')
                ->comment('PHP-Enum App\Enums\ValueSource');

            $table->timestamps();

            $table->index(['allocation_key_id', 'unit_id']);
            $table->index('tenancy_id');
            $table->index('organization_id');
        });

        Schema::create('prepayments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tenancy_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->nullable()
                ->constrained('documents')->nullOnDelete();

            $table->string('kind', 48)->default('BETRIEBSKOSTEN')
                ->comment('PHP-Enum App\Enums\PrepaymentKind');
            $table->date('period_start');
            $table->date('period_end');

            $table->bigInteger('target_cent')->default(0)
                ->comment('Sollsumme fuer den Zeitraum, dient der Plausibilisierung');
            $table->bigInteger('actual_cent')->nullable()
                ->comment('Tatsaechlich geleistete Vorauszahlung, nur diese wird abgezogen');

            $table->string('source', 48)->default('MANUELL')
                ->comment('PHP-Enum App\Enums\ValueSource');
            $table->boolean('assumed_equal_to_target')->default(false)
                ->comment('Annahme Ist gleich Soll, nur mit sichtbarer Bestaetigung zulaessig');
            $table->foreignUlid('confirmed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->unique(
                ['billing_run_id', 'tenancy_id', 'kind', 'period_start'],
                'prepayments_period_unique'
            );
            $table->index(['tenancy_id', 'kind']);
            $table->index('organization_id');
        });

        Schema::create('heating_statements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->nullable()
                ->constrained('documents')->nullOnDelete();

            $table->string('provider_name', 190)->nullable()
                ->comment('Abrechnungsunternehmen, soweit erkannt');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('supply_case', 48)->default('EXTERN_ABGERECHNET')
                ->comment('PHP-Enum App\Enums\HeatingSupplyCase');

            $table->bigInteger('total_cost_cent')->nullable();
            $table->bigInteger('basic_cost_cent')->nullable();
            $table->bigInteger('consumption_cost_cent')->nullable();
            $table->bigInteger('heating_cost_cent')->nullable();
            $table->bigInteger('warm_water_cost_cent')->nullable();
            $table->bigInteger('operating_current_cent')->nullable();
            $table->bigInteger('co2_cost_cent')->nullable();
            $table->decimal('basic_cost_share_percent', 7, 4)->nullable()
                ->comment('Grundkostenanteil nach HeizkostenV');

            $table->string('co2_share_status', 48)->default('UNBEKANNT')
                ->comment('PHP-Enum App\Enums\Co2ShareStatus, UNBEKANNT erzeugt eine Pruefaufgabe');

            $table->bigInteger('checksum_lines_total_cent')->nullable()
                ->comment('Summe der Einzelbetraege je Einheit');
            $table->bigInteger('checksum_difference_cent')->nullable();
            $table->boolean('checksum_ok')->nullable();

            $table->timestamps();

            $table->index(['billing_run_id', 'supply_case']);
            $table->index('organization_id');
            $table->index('document_id');
        });

        Schema::create('heating_statement_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('heating_statement_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('tenancy_id')->nullable()->constrained()->nullOnDelete();

            $table->string('unit_label', 120)->nullable()
                ->comment('Bezeichnung aus der externen Abrechnung, falls keine Zuordnung moeglich');

            $table->bigInteger('share_total_cent')->nullable();
            $table->bigInteger('share_basic_cent')->nullable();
            $table->bigInteger('share_consumption_cent')->nullable();
            $table->bigInteger('share_heating_cent')->nullable();
            $table->bigInteger('share_warm_water_cent')->nullable();
            $table->bigInteger('share_co2_cent')->nullable();

            $table->decimal('consumption', 14, 4)->nullable();
            $table->string('consumption_unit', 20)->nullable();
            $table->date('usage_period_start')->nullable();
            $table->date('usage_period_end')->nullable();

            $table->decimal('confidence', 7, 4)->nullable();
            $table->unsignedSmallInteger('source_page')->nullable();

            $table->timestamps();

            $table->index(['heating_statement_id', 'unit_id'], 'heating_lines_statement_unit_index');
            $table->index('tenancy_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heating_statement_lines');
        Schema::dropIfExists('heating_statements');
        Schema::dropIfExists('prepayments');
        Schema::dropIfExists('allocation_key_values');
        Schema::dropIfExists('allocation_keys');
        Schema::dropIfExists('cost_items');
    }
};
