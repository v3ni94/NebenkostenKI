<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReminderWindow;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ReminderPreferenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Erinnerungseinstellung, global oder je Objekt.
 *
 * Eine Zeile mit property_id null gilt global fuer den Nutzer. Die
 * Anwendungsschicht stellt sicher, dass je Nutzer hoechstens eine globale Zeile
 * existiert, weil ein Teilindex nicht portabel waere.
 */
class ReminderPreference extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<ReminderPreferenceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['unsubscribe_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'q1_enabled' => 'boolean',
            'q2_enabled' => 'boolean',
            'q3_enabled' => 'boolean',
            'december_enabled' => 'boolean',
            'deactivated_at' => 'datetime',
            'reactivated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function isWindowEnabled(ReminderWindow $window): bool
    {
        if ($this->getAttribute('is_active') !== true) {
            return false;
        }

        return (bool) $this->getAttribute(match ($window) {
            ReminderWindow::Q1 => 'q1_enabled',
            ReminderWindow::Q2 => 'q2_enabled',
            ReminderWindow::Q3 => 'q3_enabled',
            ReminderWindow::DEZEMBER => 'december_enabled',
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWithoutProperty(Builder $query): void
    {
        $query->whereNull('property_id');
    }
}
