<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Bout;
use App\Services\BoutAdvancer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Result callback from a court's scoreboard.
 *
 * Point the vendor's "match complete" webhook at POST /webhooks/scoreboard.
 * Authentication is a shared secret header — see VerifyScoreboardSecret —
 * because the caller is a device on the mat, not a signed-in user.
 *
 * The payload shape below is a best guess and will need adjusting once the
 * manufacturer documents theirs. Nothing else in the application depends on
 * it: this controller translates whatever arrives into a BoutAdvancer call.
 */
class ScoreboardResultController extends Controller
{
    public function __invoke(Request $request, BoutAdvancer $advancer): JsonResponse
    {
        try {
            $payload = $request->validate([
                'play_code' => ['required', 'string', 'max:32'],
                'winner_side' => ['required', 'in:a,b'],
                'score_a' => ['nullable', 'numeric', 'min:0', 'max:999'],
                'score_b' => ['nullable', 'numeric', 'min:0', 'max:999'],
                'win_type' => ['nullable', 'string', 'max:32'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid payload.',
                'errors' => $e->errors(),
            ], 422);
        }

        $bout = Bout::where('play_code', $payload['play_code'])->first();

        // A redraw issues fresh play codes, so a result from a discarded
        // bracket lands here and is refused rather than applied to whatever
        // now occupies that slot.
        if ($bout === null) {
            Log::warning('Scoreboard result for an unknown play code', [
                'play_code' => $payload['play_code'],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'No bout matches that play code. It may belong to a bracket that has since been redrawn.',
            ], 404);
        }

        $winnerId = $payload['winner_side'] === 'a' ? $bout->athlete_a_id : $bout->athlete_b_id;

        if ($winnerId === null) {
            return response()->json([
                'status' => 'error',
                'message' => "Side {$payload['winner_side']} of bout {$bout->play_code} has no athlete yet.",
            ], 409);
        }

        try {
            // recordResult is idempotent on an identical repeat, so a vendor
            // that retries cannot advance the same athlete twice.
            $advancer->recordResult(
                bout: $bout,
                winnerAthleteId: $winnerId,
                scores: [
                    'score_a' => $payload['score_a'] ?? null,
                    'score_b' => $payload['score_b'] ?? null,
                ],
                winType: $payload['win_type'] ?? 'khalol',
                user: null,
                source: 'scoreboard',
            );
        } catch (Throwable $e) {
            Log::error('Failed to apply a scoreboard result', [
                'play_code' => $bout->play_code,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        $bout->refresh();

        return response()->json([
            'status' => 'success',
            'play_code' => $bout->play_code,
            'winner_athlete_id' => $bout->winner_athlete_id,
            'advanced_to' => $bout->nextBout?->play_code,
        ]);
    }
}
