<?php

namespace App\Contracts;

use App\Models\Bout;
use App\Models\Court;
use App\Services\Scoreboard\ScoreboardResponse;

/**
 * The single seam between this system and a court's scoreboard hardware.
 *
 * The original ScoreboardConnector had the right instinct — one class that
 * knows the transport, with everything else calling three methods and not
 * caring. This keeps that shape but makes it substitutable, so the whole
 * result path can be exercised in tests with no hardware on the desk.
 *
 * The vendor's API is still undocumented. Everything vendor-specific lives in
 * HttpScoreboardDriver; nothing else in the application should need editing
 * when the real specification arrives.
 */
interface ScoreboardDriver
{
    /**
     * Send the athletes, weight class and fight number to a court's display so
     * it is showing the right bout before the contest starts.
     */
    public function pushBout(Bout $bout, Court $court): ScoreboardResponse;

    /**
     * Blank a court between bouts.
     */
    public function clearCourt(Court $court): ScoreboardResponse;
}
