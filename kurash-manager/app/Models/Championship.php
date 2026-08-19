<?php

namespace App\Models;

use Database\Factories\ChampionshipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The date columns are declared here because static analysis reads the
 * migration, where they are `date` columns, and cannot see that casts() turns
 * them into Carbon instances.
 *
 * @property string $title
 * @property string|null $location
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $archived_at
 */
class Championship extends Model
{
    /** @use HasFactory<ChampionshipFactory> */
    use HasFactory;

    protected $fillable = ['title', 'location', 'starts_on', 'ends_on', 'archived_at', 'archived_by'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'archived_at' => 'datetime'];
    }

    /** @return HasMany<AgeCategory, $this> */
    public function ageCategories(): HasMany
    {
        return $this->hasMany(AgeCategory::class)->orderBy('sort_order');
    }

    /** @return HasMany<Athlete, $this> */
    public function athletes(): HasMany
    {
        return $this->hasMany(Athlete::class);
    }

    /** @return HasMany<Bout, $this> */
    public function bouts(): HasMany
    {
        return $this->hasMany(Bout::class);
    }

    /** @return HasMany<Court, $this> */
    public function courts(): HasMany
    {
        return $this->hasMany(Court::class)->orderBy('number');
    }

    /** @return HasMany<ChampionshipEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ChampionshipEvent::class)->orderByDesc('created_at');
    }

    /** @return BelongsTo<User, $this> */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Close the championship. Everything under it becomes read-only — see
     * ArchivedChampionshipGuard, which is what actually enforces it.
     */
    public function archive(?User $user = null): void
    {
        if ($this->isArchived()) {
            return;
        }

        $this->forceFill(['archived_at' => now(), 'archived_by' => $user?->id])->save();

        $this->events()->create([
            'user_id' => $user?->id,
            'action' => 'archived',
            'note' => __('Closed to further changes.'),
        ]);
    }

    /**
     * Reopen it for editing.
     *
     * Recorded rather than prevented: a mistake found after the ceremony has to
     * be fixable, but nobody should be able to change an archived result
     * without leaving a trace that they did.
     */
    public function reopen(?User $user = null, ?string $reason = null): void
    {
        if (! $this->isArchived()) {
            return;
        }

        $this->forceFill(['archived_at' => null, 'archived_by' => null])->save();

        $this->events()->create([
            'user_id' => $user?->id,
            'action' => 'reopened',
            'note' => $reason,
        ]);
    }

    /** @param  Builder<self>  $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
