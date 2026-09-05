<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiCallPurpose;
use Database\Factories\AiPromptVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Versionierter Prompt. Kein Modell- oder Promptwechsel ohne Protokollierung.
 *
 * @property string $id
 * @property AiCallPurpose $purpose
 * @property string $version
 * @property string $hash
 * @property bool $is_active
 * @property Carbon|null $activated_at
 * @property Carbon|null $deactivated_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AiCall> $aiCalls
 */
class AiPromptVersion extends Model
{
    /** @use HasFactory<AiPromptVersionFactory> */
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
            'purpose' => AiCallPurpose::class,
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AiCall, $this>
     */
    public function aiCalls(): HasMany
    {
        return $this->hasMany(AiCall::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
