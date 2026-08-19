<?php

namespace App\Services\Scoreboard;

use App\Contracts\ScoreboardDriver;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\Court;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to real scoreboard hardware over HTTP.
 *
 * EVERYTHING vendor-specific lives in this file — endpoint paths, payload
 * field names, the authentication header. When the manufacturer's
 * documentation arrives, this is the only class that should need editing.
 *
 * Open questions, unchanged from the original connector's TODOs:
 *   - Is authentication a bearer token, an API key header, or nothing on a
 *     trusted LAN?
 *   - What are the real endpoint paths and field names?
 *   - Kurash jackets are traditionally green and blue. Confirm which side the
 *     vendor calls which before a final is shown the wrong way round.
 */
class HttpScoreboardDriver implements ScoreboardDriver
{
    public function __construct(private readonly int $timeoutSeconds = 5) {}

    public function pushBout(Bout $bout, Court $court): ScoreboardResponse
    {
        if (blank($court->scoreboard_base_url)) {
            return ScoreboardResponse::failure("Court {$court->number} has no scoreboard URL configured.");
        }

        return $this->send(
            'POST',
            $court,
            '/match',
            [
                'fight_number' => $bout->fight_number,
                'play_code' => $bout->play_code,
                'weight_category' => $bout->weightCategory?->label,
                // Blue and green are the Kurash jacket colours; side A is blue
                // here until the vendor confirms their convention.
                'athlete_blue' => $this->describe($bout->athleteA),
                'athlete_green' => $this->describe($bout->athleteB),
            ],
        );
    }

    public function clearCourt(Court $court): ScoreboardResponse
    {
        if (blank($court->scoreboard_base_url)) {
            return ScoreboardResponse::failure("Court {$court->number} has no scoreboard URL configured.");
        }

        return $this->send('POST', $court, '/clear', []);
    }

    /** @return array{name: string, noc: string|null, ika_id: string}|null */
    private function describe(?Athlete $athlete): ?array
    {
        if ($athlete === null) {
            return null;
        }

        return [
            'name' => $athlete->fullname,
            'noc' => $athlete->noc_code,
            'ika_id' => $athlete->ika_id,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $method, Court $court, string $path, array $payload): ScoreboardResponse
    {
        $url = rtrim((string) $court->scoreboard_base_url, '/').$path;

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->acceptJson()
                ->when(
                    filled($court->scoreboard_api_key),
                    fn ($request) => $request->withToken($court->scoreboard_api_key)
                )
                ->send($method, $url, ['json' => $payload]);
        } catch (Throwable $e) {
            // A scoreboard that is unplugged, asleep or on the wrong subnet
            // must not take down the request that triggered the push.
            Log::warning('Scoreboard unreachable', [
                'court' => $court->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return ScoreboardResponse::failure($e->getMessage());
        }

        if ($response->failed()) {
            Log::warning('Scoreboard rejected a push', [
                'court' => $court->id,
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ScoreboardResponse::failure($response->body(), $response->status());
        }

        return ScoreboardResponse::ok($response->status(), $response->json() ?? []);
    }
}
