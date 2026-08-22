<?php

namespace App\Models;

use App\Support\Gender;
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
 * @property array<int, mixed>|null $genders
 * @property array<int, mixed>|null $age_groups
 */
class Championship extends Model
{
    /** @use HasFactory<ChampionshipFactory> */
    use HasFactory;

    protected $fillable = ['title', 'location', 'starts_on', 'ends_on', 'genders', 'age_groups', 'archived_at', 'archived_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'archived_at' => 'datetime',
            'genders' => 'array',
            'age_groups' => 'array',
        ];
    }

    /*
     |--------------------------------------------------------------------------
     | What this championship runs
     |--------------------------------------------------------------------------
     |
     | The organizer states two lists when the championship is created: the
     | competitions (men, women) and the age groups (senior, junior, cadet).
     | Every division is one of the first paired with one of the second, and
     | nothing anywhere in the system may offer a pair that is not in here.
     */

    /**
     * Named apart from the `genders` column on purpose: a method with the same
     * name as an attribute is what Eloquent reaches for when the column has not
     * been selected, and it would find an array where it wanted a relation.
     *
     * @return list<string>
     */
    public function configuredGenders(): array
    {
        $genders = Gender::sanitise($this->genders ?? []);

        // A championship saved before it declared anything still has to be
        // usable, so it falls back to what one has always meant.
        return $genders === [] ? Gender::DEFAULT : $genders;
    }

    /**
     * The age groups a championship can be run for, in the order a federation
     * lists them. Offered as the checkboxes on the championship form; a
     * championship carrying something else keeps it, so nothing an organizer
     * already entered is quietly dropped.
     *
     * @var list<string>
     */
    public const AGE_GROUPS = ['Senior', 'Junior', 'Cadet', 'Veteran'];

    /** @return list<string> */
    public function configuredAgeGroups(): array
    {
        $groups = [];

        foreach ($this->age_groups ?? [] as $group) {
            $group = is_string($group) ? trim($group) : '';

            if ($group !== '' && ! in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups === [] ? ['Senior'] : $groups;
    }

    /**
     * Where a division sits in reading order: by competition first, then by
     * age group, both in the order this championship declared them.
     *
     * Derived rather than typed, so the first age group offered as a default
     * anywhere is the first one the organizer ticked.
     */
    public function divisionSortOrder(string $gender, string $ageGroup): int
    {
        $competition = array_search($gender, $this->configuredGenders(), true);
        $group = array_search($ageGroup, $this->configuredAgeGroups(), true);

        return ($competition === false ? 99 : $competition) * 100
            + ($group === false ? 99 : $group);
    }

    /** Every division this championship is allowed to have, in reading order. */
    public function allowsDivision(?string $gender, ?string $ageGroup): bool
    {
        return $gender !== null
            && $ageGroup !== null
            && in_array($gender, $this->configuredGenders(), true)
            && in_array($ageGroup, $this->configuredAgeGroups(), true);
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
