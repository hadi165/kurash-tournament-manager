<?php

namespace App\Models;

use Database\Factories\AgeCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A division — cadet, junior, senior — and the contest length that goes with
 * it.
 *
 * @property int|null $bout_seconds
 */
class AgeCategory extends Model
{
    /** @use HasFactory<AgeCategoryFactory> */
    use HasFactory;

    protected $fillable = ['championship_id', 'name', 'bout_seconds', 'sort_order'];

    /**
     * How long a contest in this division runs, or null to use the configured
     * default for the weight class's gender.
     *
     * Set on the championship's own screen, because cadets, juniors and seniors
     * do not fight for the same time and that is not something an environment
     * variable keyed on gender can say.
     */
    public function boutSecondsLabel(): string
    {
        if ($this->bout_seconds === null) {
            return __('Default');
        }

        return sprintf('%d:%02d', intdiv($this->bout_seconds, 60), $this->bout_seconds % 60);
    }

    /** @return BelongsTo<Championship, $this> */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    /** @return HasMany<WeightCategory, $this> */
    public function weightCategories(): HasMany
    {
        return $this->hasMany(WeightCategory::class)->orderBy('sort_order');
    }

    /** @return HasMany<Athlete, $this> */
    public function athletes(): HasMany
    {
        return $this->hasMany(Athlete::class);
    }
}
