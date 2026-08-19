<?php

namespace App\Jobs;

use App\Contracts\ScoreboardDriver;
use App\Models\Bout;
use App\Models\BoutEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends a bout to its court's display.
 *
 * Queued on purpose. A scoreboard on a flaky venue network can take the full
 * timeout to fail, and an official pressing "send to mat" should not wait for
 * that — nor should the request fail because a display is unplugged. Retries
 * are spaced to cover a device being briefly rebooted.
 */
class PushBoutToScoreboard implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> seconds between attempts */
    public array $backoff = [5, 15, 60];

    public function __construct(public readonly int $boutId) {}

    public function handle(ScoreboardDriver $scoreboard): void
    {
        $bout = Bout::with(['athleteA', 'athleteB', 'weightCategory', 'court'])->find($this->boutId);

        if ($bout === null) {
            return; // the bracket was redrawn under us; nothing to send
        }

        if ($bout->court === null) {
            Log::info('Skipping scoreboard push: bout has no court', ['bout' => $bout->play_code]);

            return;
        }

        if (! $bout->court->is_active) {
            return;
        }

        $response = $scoreboard->pushBout($bout, $bout->court);

        if ($response->failed()) {
            // Let the queue retry. On the final attempt the exception surfaces
            // in failed_jobs rather than disappearing.
            throw new \RuntimeException(
                "Scoreboard push failed for {$bout->play_code}: {$response->message}"
            );
        }

        $bout->forceFill(['scoreboard_synced_at' => now()])->save();

        BoutEvent::create([
            'bout_id' => $bout->id,
            'action' => 'pushed_to_scoreboard',
            'source' => 'system',
            'after' => ['court' => $bout->court->number] + $response->toLogContext(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Gave up pushing a bout to its scoreboard', [
            'bout_id' => $this->boutId,
            'error' => $e->getMessage(),
        ]);
    }
}
