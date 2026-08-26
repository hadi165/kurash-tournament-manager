<?php

namespace App\Observers;

use App\Models\Athlete;
use App\Models\Court;
use App\Models\WeightCategory;
use App\Support\DisplayCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Invalidates the venue screens when something other than a bout changes them.
 *
 * BoutObserver covers results, and results are most of what a hall watches. But
 * a screen is not only its results: it names the mats, prints the athletes and
 * titles the weight classes, and every one of those is a column on a different
 * table. Renaming Mat 2, correcting a misspelt name or placing the single
 * athlete in a one-entry class all change what is on the wall, and none of them
 * writes a bout row.
 *
 * Before this, those edits waited out the five-minute TTL. That is the shape of
 * bug that gets reported as "the screen is wrong" twenty minutes later, when
 * whoever made the correction has moved on and nobody connects the two.
 *
 * Scoped to the columns that actually appear. A scoreboard API key changing is
 * a write to `courts` that no spectator can see, and re-rendering every screen
 * in the championship for it would make the version number churn for nothing.
 */
class DisplayContentObserver
{
    /**
     * Columns whose value reaches a venue screen, per model.
     *
     * @var array<class-string<Model>, list<string>>
     */
    private const WATCHED = [
        Court::class => ['name', 'number', 'is_active'],
        Athlete::class => ['fullname', 'noc_code', 'noc_name'],
        WeightCategory::class => [
            'label', 'gender', 'min_kg', 'max_kg', 'sort_order',
            // The podium of a one-athlete class is this column and nothing
            // else: no bout is ever written, so no bout event can invalidate it.
            'draw_placement_athlete_id',
            // What the class was drawn as decides which view renders it, and
            // publication decides whether the draw may be shown at all.
            'draw_format', 'draw_published_at',
        ],
    ];

    /**
     * A new row is new content by definition: there is no previous value to
     * compare against, and a mat that has just been added belongs on the mats
     * screen immediately.
     */
    public function created(Model $model): void
    {
        $this->bump($model);
    }

    /**
     * Deliberately not `saved`. wasRecentlyCreated stays true for the life of
     * the instance that created the row, so a create followed by an update on
     * the same object would bump twice — once for content nobody changed.
     */
    public function updated(Model $model): void
    {
        if ($this->changedSomethingVisible($model)) {
            $this->bump($model);
        }
    }

    public function deleted(Model $model): void
    {
        $this->bump($model);
    }

    /** Did this write touch a column the hall can see? */
    private function changedSomethingVisible(Model $model): bool
    {
        foreach (self::WATCHED[$model::class] ?? [] as $column) {
            if ($model->wasChanged($column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bump the championship this row belongs to.
     *
     * A weight class reaches its championship through its age category rather
     * than carrying the id itself, which is one extra read on an edit that
     * happens a handful of times per competition.
     */
    private function bump(Model $model): void
    {
        $championshipId = $model instanceof WeightCategory
            ? $model->ageCategory()->value('championship_id')
            : $model->getAttribute('championship_id');

        if ($championshipId !== null) {
            DisplayCache::bump((int) $championshipId);
        }
    }
}
