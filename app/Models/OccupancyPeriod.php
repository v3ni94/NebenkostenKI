<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ValueSource;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Belegungszeitraum mit Personenanzahl.
 *
 * Grundlage der Schluessel PERSONEN und PERSONENTAGE. Personentage ergeben sich
 * aus Personenanzahl multipliziert mit den Gueltigkeitstagen.
 */
class OccupancyPeriod extends Model
{
    /** @use HasFactory<\Database\Factories\OccupancyPeriodFactory> */
    use HasFactory;

    use BelongsToOrganization, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'person_count' => 'integer',
            'source' => ValueSource::class,
        ];
    }

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Tenancy, $this>
     */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }
}
