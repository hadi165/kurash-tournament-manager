<?php

namespace App\Models;

use App\Services\TournamentFormatPolicy;
use App\Services\WeightValidator;
use App\Support\TournamentFormat;
use App\Support\WeightRange;
use Carbon\CarbonImmutable;
use Database\Factories\WeightCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The cast columns are declared here because static analysis reads the
 * migration, where they are timestamps, and cannot see that casts() turns them
 * into date objects.
 *
 * @property CarbonImmutable|null $draw_generated_at
 * @property CarbonImmutable|null $draw_published_at
 * @property CarbonImmutable|null $draw_locked_at
 * @property int|null $draw_athlete_count
 * @property int|null $draw_bucket_size
 * @property int|null $draw_bye_count
 * @property int $draw_version
 * @property string|null $draw_format_preference
 * @property string|null $draw_format
 * @property string|null $draw_format_override_reason
 * @property int|null $draw_format_override_by
 * @property CarbonImmutable|null $draw_format_override_at
 * @property int|null $draw_placement_athlete_id
 * @property int|null $draw_placement_by
 * @property CarbonImmutable|null $draw_placement_at
 */
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
            'draw_format_override_at' => 'datetime',
            'draw_placement_at' => 'datetime',
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

    /**
     * Has this class been drawn?
     *
     * Not "does it have bouts". A class of one athlete is drawn by an
     * administrative placement and has no contests at all — asking the bouts
     * table would call it undrawn, and every screen that gates on this would
     * offer to draw it again.
     *
     * The stored format is what makes the difference: it is written in the
     * same transaction as whatever the draw produced, contests or not.
     */
    public function hasDraw(): bool
    {
        if ($this->draw_format !== null) {
            return true;
        }

        // Drawn before formats existed. The backfill stamps these, but a row
        // written by an older release mid-upgrade would not be, and a draw
        // that exists must not read as missing for the length of a deploy.
        return $this->bouts()->exists();
    }

    /**
     * What this class was drawn as, or null if it has not been drawn.
     *
     * The snapshot, never today's athlete count — an operator presenting a
     * published table must see the table that was published.
     */
    public function drawFormat(): ?TournamentFormat
    {
        $format = TournamentFormat::tryFromValue($this->draw_format);

        if ($format !== null) {
            return $format;
        }

        // Same reasoning as hasDraw(): contests that predate the column are a
        // knockout bracket, because that is the only thing that generated them.
        return $this->bouts()->exists() ? TournamentFormat::Knockout : null;
    }

    /** What drawing this class right now would produce. */
    public function resolvedFormat(): ?TournamentFormat
    {
        return app(TournamentFormatPolicy::class)->resolveFor($this);
    }

    /** Was this class drawn as a round robin? */
    public function isRoundRobin(): bool
    {
        return $this->drawFormat() === TournamentFormat::RoundRobin;
    }

    /** Was it settled by placing a single unopposed athlete? */
    public function isPlacement(): bool
    {
        return $this->drawFormat() === TournamentFormat::Placement;
    }

    /**
     * Was the format chosen against the IKA rule for this field?
     *
     * Read off the recorded override rather than recomputed, so a class that
     * has since grown past five athletes still says that its knockout was an
     * override when it was made.
     */
    public function formatWasOverridden(): bool
    {
        return $this->draw_format_override_at !== null;
    }

    /** @return BelongsTo<Athlete, $this> */
    public function placedAthlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class, 'draw_placement_athlete_id');
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
     * Does a measured weight fall inside this category?
     *
     * Delegated rather than answered here. The rule needs the classes either
     * side of this one to know where the band starts, which is a question about
     * the division and not about this row — and the previous answer, which used
     * only this row, accepted a 500-gram window below the ceiling instead of a
     * weight class.
     */
    public function admits(float $kg, float $tolerance = WeightValidator::TOLERANCE_KG): bool
    {
        return app(WeightValidator::class)->rangeFor($this, $tolerance)->admits($kg);
    }

    /** The band this class accepts, tolerance included. */
    public function weightRange(float $tolerance = WeightValidator::TOLERANCE_KG): WeightRange
    {
        return app(WeightValidator::class)->rangeFor($this, $tolerance);
    }
}
