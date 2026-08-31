<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Leistungsrechnung der Hausverwaltung Mueller GmbH an den Nutzer.
 *
 * Die Rechnungsnummer ist lueckenlos und wird atomar in einer Transaktion mit
 * Zeilensperre vergeben. Eine festgeschriebene Rechnung wird niemals
 * ueberschrieben, ein Storno erfolgt ueber eine Stornorechnung.
 *
 * Rechnungen sind steuerrechtlich aufzubewahren und werden bei einer
 * Kontoloeschung vom Konto entkoppelt, nicht mitgeloescht.
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
