<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Leistungsrechnung der Hausverwaltung Mueller GmbH an den Nutzer.
 *
 * Die Rechnungsnummer ist lueckenlos und wird atomar in einer Transaktion mit
 * Zeilensperre vergeben. Eine festgeschriebene Rechnung wird niemals
 * ueberschrieben, ein Storno erfolgt ueber eine Stornorechnung.
 *
 * Rechnungen sind steuerrechtlich aufzubewahren und werden bei einer
 * Kontoloeschung vom Konto entkoppelt, nicht mitgeloescht.
 *
 * @property string $id
 * @property string $number
 * @property string|null $organization_id
 * @property string|null $user_id
 * @property string|null $billing_run_id
 * @property string|null $payment_id
 * @property string|null $cancels_invoice_id
 * @property string $customer_name
 * @property string|null $customer_address_line
 * @property string|null $customer_address_extra
 * @property string|null $customer_postal_code
 * @property string|null $customer_city
 * @property string $customer_country
 * @property string|null $customer_vat_id
 * @property Carbon $issued_on
 * @property Carbon $service_date
 * @property int $net_cent
 * @property int $tax_cent
 * @property int $gross_cent
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string $tax_rate_percent
 * @property string $currency
 * @property InvoiceStatus $status
 * @property string|null $payment_method
 * @property string|null $payment_reference
 * @property string|null $pdf_sha256
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun|null $billingRun
 * @property-read Invoice|null $cancelsInvoice
 * @property-read Collection<int, GeneratedDocument> $generatedDocuments
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read Organization|null $organization
 * @property-read Payment|null $payment
 * @property-read User|null $user
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'service_date' => 'date',
            'net_cent' => 'integer',
            'tax_cent' => 'integer',
            'gross_cent' => 'integer',
            'tax_rate_percent' => 'decimal:4',
            'status' => InvoiceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<BillingRun, $this>
     */
    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function cancelsInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cancels_invoice_id');
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return HasMany<GeneratedDocument, $this>
     */
    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeIssued(Builder $query): void
    {
        $query->where('status', '!=', InvoiceStatus::ENTWURF->value);
    }
}
