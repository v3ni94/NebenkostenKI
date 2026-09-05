<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\TenancyPersonFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Strukturierte Mieterperson. Kontaktdaten nur soweit erforderlich.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $tenancy_id
 * @property string|null $salutation
 * @property string|null $first_name
 * @property string $last_name
 * @property string|null $email
 * @property bool $is_primary_contact
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read Tenancy $tenancy
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
