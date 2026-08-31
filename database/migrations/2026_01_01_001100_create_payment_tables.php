<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| Zahlungen, Webhooks und Leistungsrechnungen der Hausverwaltung Mueller GmbH
|------------------------------------------------------------------------------
|
| 1. Die Finalisierung wird ausschliesslich durch einen verifizierten Webhook
|    freigeschaltet. Der eindeutige Schluessel auf webhook_events.provider_event_id
|    sichert die idempotente Verarbeitung, payments.idempotency_key sichert den
|    idempotenten Checkout.
|
| 2. webhook_events speichert die Nutzlast entweder verschluesselt oder
|    datensparsam. Roh-Payloads mit personenbezogenen Daten gehoeren nicht in
|    Application Logs. Der Digest dient nur der Wiedererkennung.
|
| 3. Rechnungsnummern sind lueckenlos und werden atomar in einer Transaktion mit
|    Zeilensperre vergeben (Beispiel NK-2026-000001). Eine festgeschriebene
|    Rechnung wird niemals ueberschrieben, ein Storno erfolgt ueber eine
|    Stornorechnung mit Referenz in cancels_invoice_id.
|
| 4. Rechnungen sind steuerrechtlich aufzubewahren. Die Fremdschluessel auf
|    Nutzer, Organisation und Abrechnungslauf sind daher nullOnDelete, damit die
|    Rechnung bei einer Kontoloeschung entkoppelt und mit den erforderlichen
|    Rechnungsdaten erhalten bleibt.
|
| 5. Steuer- und Bankdaten des Betreibers stammen ausschliesslich aus ENV
|    beziehungsweise bestaetigter Adminkonfiguration und werden hier nicht
|    gespeichert.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('billing_run_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('provider', 48)->default('STRIPE')
                ->comment('PHP-Enum App\Enums\PaymentProvider');
            $table->string('checkout_session_id', 190)->nullable();
            $table->string('payment_intent_id', 190)->nullable();
            $table->string('idempotency_key', 190);

            $table->bigInteger('amount_cent');
            $table->string('currency', 3)->default('eur');
            $table->unsignedInteger('statement_count')->default(0);
            $table->bigInteger('unit_price_gross_cent')->nullable();
            $table->bigInteger('base_price_gross_cent')->nullable();

            $table->string('status', 48)->default('ERSTELLT')
                ->comment('PHP-Enum App\Enums\PaymentStatus');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->bigInteger('refunded_amount_cent')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('dispute_opened_at')->nullable();
            $table->string('failure_code', 120)->nullable();

            $table->timestamps();

            $table->unique('idempotency_key');
            $table->unique('checkout_session_id');
            $table->index(['billing_run_id', 'status']);
            $table->index(['organization_id', 'status']);
            $table->index('payment_intent_id');
        });

        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('provider', 48)->default('STRIPE')
                ->comment('PHP-Enum App\Enums\PaymentProvider');
            $table->string('provider_event_id', 190)
                ->comment('Eindeutige Event-ID des Providers, sichert Idempotenz');
            $table->string('event_type', 120);

            $table->string('signature_status', 48)
                ->comment('PHP-Enum App\Enums\WebhookSignatureStatus');
            $table->string('processing_status', 48)->default('EMPFANGEN')
                ->comment('PHP-Enum App\Enums\WebhookProcessingStatus');

            $table->foreignUlid('payment_id')->nullable()
                ->constrained('payments')->nullOnDelete();

            $table->string('payload_digest', 64)->nullable()
                ->comment('SHA-256 der Rohnutzlast, nur zur Wiedererkennung');
            $table->text('payload')->nullable()
                ->comment('Anwendungsseitig verschluesselt und datensparsam gekuerzt');

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 120)->nullable();
            $table->string('error_message', 500)->nullable();

            $table->timestamps();

            $table->unique('provider_event_id');
            $table->index(['processing_status', 'received_at']);
            $table->index('event_type');
            $table->index('payment_id');
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('number', 40)->comment('Lueckenlose Rechnungsnummer, Beispiel NK-2026-000001');

            $table->foreignUlid('organization_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('billing_run_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignUlid('payment_id')->nullable()
                ->constrained('payments')->nullOnDelete();
            $table->foreignUlid('cancels_invoice_id')->nullable()
                ->constrained('invoices')->nullOnDelete();

            // Rechnungsanschrift zum Zeitpunkt der Leistung, unveraenderlich.
            $table->string('customer_name');
            $table->string('customer_address_line')->nullable();
            $table->string('customer_address_extra')->nullable();
            $table->string('customer_postal_code', 16)->nullable();
            $table->string('customer_city', 120)->nullable();
            $table->string('customer_country', 2)->default('DE');
            $table->string('customer_vat_id', 32)->nullable();

            $table->date('issued_on');
            $table->date('service_date');

            $table->bigInteger('net_cent');
            $table->bigInteger('tax_cent');
            $table->bigInteger('gross_cent');
            $table->decimal('tax_rate_percent', 7, 4);
            $table->string('currency', 3)->default('eur');

            $table->string('status', 48)->default('ENTWURF')
                ->comment('PHP-Enum App\Enums\InvoiceStatus');
            $table->string('payment_method', 60)->nullable();
            $table->string('payment_reference', 190)->nullable()
                ->comment('Referenz des Zahlungsanbieters');
            $table->string('pdf_sha256', 64)->nullable();

            $table->timestamps();

            $table->unique('number');
            $table->index(['organization_id', 'status']);
            $table->index(['issued_on', 'status']);
            $table->index('billing_run_id');
            $table->index('payment_id');
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('position');
            $table->string('description', 190);
            $table->decimal('quantity', 12, 4)->default(1);
            $table->bigInteger('unit_price_net_cent');
            $table->bigInteger('net_cent');
            $table->decimal('tax_rate_percent', 7, 4);
            $table->bigInteger('tax_cent');
            $table->bigInteger('gross_cent');

            $table->timestamps();

            $table->unique(['invoice_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payments');
    }
};
