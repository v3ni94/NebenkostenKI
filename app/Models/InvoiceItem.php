<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Rechnungsposition. Netto, Umsatzsteuer und Brutto werden getrennt ausgewiesen.
 *
 * @property string $id
 * @property string $invoice_id
 * @property int $position
 * @property string $description
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string $quantity
 * @property int $unit_price_net_cent
 * @property int $net_cent
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string $tax_rate_percent
 * @property int $tax_cent
 * @property int $gross_cent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice $invoice
 */
class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
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
            'position' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price_net_cent' => 'integer',
            'net_cent' => 'integer',
            'tax_rate_percent' => 'decimal:4',
            'tax_cent' => 'integer',
            'gross_cent' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
