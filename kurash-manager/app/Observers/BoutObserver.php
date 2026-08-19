<?php

namespace App\Observers;

use App\Models\Bout;
use App\Support\DisplayCache;

/**
 * Keeps the display screens in step with the competition.
 *
 * Deliberately an observer rather than a call inside each service. Results
 * arrive from the operator screens, the scoreboard webhook, the bracket
 * generator and the fight-order scheduler; anything that writes a bout in
 * future would otherwise have to remember to invalidate, and the failure mode
 * of forgetting is a screen showing a result that has been overturned.
 */
class BoutObserver
{
    public function saved(Bout $bout): void
    {
        DisplayCache::bump($bout->championship_id);
    }

    public function deleted(Bout $bout): void
    {
        DisplayCache::bump($bout->championship_id);
    }
}
