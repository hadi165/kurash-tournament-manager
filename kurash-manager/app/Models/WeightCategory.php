<?php

namespace App\Models;

use Database\Factories\WeightCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeightCategory extends Model
{
    /** @use HasFactory<WeightCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'age_category_id', 'label', 'min_kg', 'max_kg', 'gender', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_kg' => 'decimal:2',
            'max_kg' => 'decimal:2',
            'draw_generated_at' => 'datetime',
            'draw_published_at' => 'datetime',
            'draw_locked_at' => 'datetime',
        ];
    }

    /*
     |--------------------------------------------------------------------------
     | The draw, as it was drawn
     |--------------------------------------------------------------------------
     |
     | These read the figures recorded when the bracket was generated, never
     | today's registration list. An operator looking at a published draw must
     | see what was published, and a late registration must not quietly change
     | the shape of a table somebody is presenting from.
     */

    public function hasDraw(): bool
    {
        return $this->bouts()->exists();
    }

    public function isDrawPublished(): bool
    {
        return $this->draw_published_at !== null;
    }

    public function isDrawLocked(): bool
    {
        return $this->draw_locked_at !== null;
    }

    /**
     * Has the entry list moved since the draw was generated?
     *
     * Informational only, and only ever shown to somebody who could act on it:
     * a published draw stays exactly as published until an admin regenerates
     * it on purpose.
     */
    public function drawIsStale(): bool
    {
        return $this->draw_athlete_count !== null
            && $this->draw_athlete_count !== $this->drawnAthletes()->count();
    }

    /** @return BelongsTo<AgeCategory, $this> */
    public function ageCategory(): BelongsTo
    {
        return $this->belongsTo(AgeCategory::class);
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

    /**
     * Athletes who may be drawn: they have a draw number and passed the scale.
     *
     * @return HasMany<Athlete, $this>
     */
    public function drawnAthletes(): HasMany
    {
        return $this->athletes()
            ->whereNotNull('draw_number')
            ->orderBy('draw_number');
    }

    /** "Male", "Female" or "Open" — the spoken form of the stored enum. */
    public function genderLabel(): string
    {
        return match ($this->gender) {
            'M' => 'Male',
            'F' => 'Female',
            default => 'Open',
        };
    }

    /**
     * "Male -91" — how the federation names a class on paper, and the filename
     * the planning specification requires for weigh-in and draw exports.
     */
    public function exportName(): string
    {
        return $this->genderLabel().' '.$this->label;
    }

    /**
     * Does a measured weight fall inside this category, allowing the standard
     * 0.5kg tolerance below an upper bound?
     */
    public function admits(float $kg, float $tolerance = 0.5): bool
    {
        if ($this->min_kg !== null && $kg < (float) $this->min_kg) {
            return false;
        }

        if ($this->max_kg !== null && $kg > (float) $this->max_kg) {
            return false;
        }

        if ($this->max_kg !== null && $kg < (float) $this->max_kg - $tolerance) {
            return false;
        }

        return true;
    }
}
