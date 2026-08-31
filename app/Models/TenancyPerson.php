<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\TenancyPersonFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Strukturierte Mieterperson. Kontaktdaten nur soweit erforderlich.
 */
class TenancyPerson extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<TenancyPersonFactory> */
    use HasFactory;

    /**
     * Laravel wuerde zu tenancy_people pluralisieren. Die Tabelle heisst aber
     * tenancy_persons wie im verbindlichen Datenmodell festgelegt.
     */
    protected $table = 'tenancy_persons';

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
            'is_primary_contact' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Tenancy, $this>
     */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }
}
