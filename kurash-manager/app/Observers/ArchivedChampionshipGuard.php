<?php

namespace App\Observers;

use App\Models\Championship;
use App\Models\WeightCategory;
use App\Services\ChampionshipArchivedException;
use Illuminate\Database\Eloquent\Model;

/**
 * Refuses any write to a championship that has been archived.
 *
 * An observer rather than a check inside each screen. There are more than fifty
 * places in the application that change competition data — registration,
 * weigh-ins, draws, brackets, the scoreboard webhook, the fight-order
 * scheduler, the mat screens — and a guard that has to be remembered at each of
 * them is a guard that will eventually be forgotten at one. The failure mode is
 * an archived result quietly changing, which is exactly what an archive exists
 * to prevent.
 *
 * The championship row itself is guarded separately, because archiving and
 * reopening are writes to it and must stay possible.
 */
class ArchivedChampionshipGuard
{
    public function saving(Model $model): void
    {
        $this->assertOpen($model);
    }

    public function deleting(Model $model): void
    {
        $this->assertOpen($model);
    }

    private function assertOpen(Model $model): void
    {
        $championship = $this->championshipFor($model);

        if ($championship?->isArchived()) {
            throw new ChampionshipArchivedException(
                __('":title" is archived. Reopen it before changing anything in it.', [
                    'title' => $championship->title,
                ])
            );
        }
    }

    /**
     * A weight class reaches its championship through its age category; every
     * other guarded model carries the key directly.
     */
    private function championshipFor(Model $model): ?Championship
    {
        if ($model instanceof WeightCategory) {
            return $model->ageCategory?->championship;
        }

        $id = $model->getAttribute('championship_id');

        return is_numeric($id) ? Championship::query()->find((int) $id) : null;
    }
}
