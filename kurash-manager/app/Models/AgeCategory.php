<?php

namespace App\Models;

use App\Support\Gender;
use Database\Factories\AgeCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A division: one of the championship's competitions paired with one of its age
 * groups — "Men Senior", "Women Cadet" — and the contest length that goes with
 * it.
 *
 * The pair is what is stored. `name` is derived from it and kept in the row
 * because every sheet, export and screen prints it, but nothing decides
 * anything by reading it.
 *
 * @property int|null $bout_seconds
 * @property string $gender
 * @property string|null $age_group
 * @property string $name
 */
class AgeCategory extends Model
{
    /** @use HasFactory<AgeCategoryFactory> */
    use HasFactory;

    protected $fillable = ['championship_id', 'gender', 'age_group', 'name', 'bout_seconds', 'sort_order'];

    /**
     * The name follows the pair rather than being typed alongside it, so the
     * two can never disagree — which, when a division was free text, is exactly
     * what they did.
     */
    protected static function booted(): void
    {
        static::saving(function (self $ageCategory): void {
            if ($ageCategory->age_group !== null) {
                $ageCategory->name = self::composeName($ageCategory->gender, $ageCategory->age_group);
            }
        });
    }

    /** "Men Senior" — the gender in front of the age group, as sheets print it. */
    public static function composeName(?string $gender, ?string $ageGroup): string
    {
        return trim(Gender::label($gender).' '.trim((string) $ageGroup));
    }

    /** Is this division one the championship actually declared? */
    public function isConfigured(): bool
    {
        return $this->championship->allowsDivision($this->gender, $this->age_group);
    }

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
