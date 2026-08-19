<?php

namespace App\Services\Scoreboard;

use App\Contracts\ScoreboardDriver;
use App\Models\Bout;
use App\Models\Court;

/**
 * For running the competition with no scoreboards attached — a small event,
 * or a rehearsal. Accepts everything and does nothing, so no calling code
 * needs to special-case the absence of hardware.
 */
class NullScoreboardDriver implements ScoreboardDriver
{
    public function pushBout(Bout $bout, Court $court): ScoreboardResponse
    {
        return ScoreboardResponse::ok(204);
    }

    public function clearCourt(Court $court): ScoreboardResponse
    {
        return ScoreboardResponse::ok(204);
    }
}
