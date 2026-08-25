<?php

namespace App\Http\Controllers;

use App\Models\Championship;
use App\Models\WeightCategory;
use App\Services\MedalTable;
use App\Services\RoundRobinStandings;
use App\Support\DisplayCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Read-only venue screens.
 *
 * Separate from the Livewire operator app on purpose. The operator screens
 * have a dozen users and may cost a round trip each; these have as many
 * viewers as the hall holds, so they are rendered once per change and served
 * from cache to everyone else.
 *
 * The pages refresh themselves with a meta refresh rather than polling
 * JavaScript: a venue display is a browser left open on a screen nobody
 * touches, and it needs to recover on its own from a dropped network.
 */
class DisplayController extends Controller
{
    public function __construct(private readonly MedalTable $medals) {}

    /** What is happening right now, mat by mat. */
    public function mats(Request $request, Championship $championship): Response
    {
        return $this->render($request, 'mats', $championship, function () use ($championship) {
            $championship->load('courts');

            $live = $championship->bouts()
                ->whereNotNull('court_id')
                ->whereNull('winner_athlete_id')
                ->with(['athleteA', 'athleteB', 'weightCategory', 'court'])
                ->get()
                ->keyBy('court_id');

            $next = $championship->bouts()
                ->readyToFight()
                ->whereNull('court_id')
                ->whereNotNull('fight_number')
                ->with(['athleteA', 'athleteB', 'weightCategory'])
                ->orderBy('fight_number')
                ->limit(8)
                ->get();

            return view('display.mats', compact('championship', 'live', 'next'))->render();
        });
    }

    public function fightOrder(Request $request, Championship $championship): Response
    {
        return $this->render($request, 'fight-order', $championship, function () use ($championship) {
            $bouts = $championship->bouts()
                ->whereNotNull('fight_number')
                ->with(['athleteA', 'athleteB', 'winner', 'weightCategory', 'court'])
                ->orderBy('fight_number')
                ->get();

            $rounds = $championship->bouts()
                ->selectRaw('weight_category_id, MAX(round) AS total')
                ->groupBy('weight_category_id')
                ->pluck('total', 'weight_category_id');

            return view('display.fight-order', compact('championship', 'bouts', 'rounds'))->render();
        });
    }

    public function medals(Request $request, Championship $championship): Response
    {
        return $this->render($request, 'medals', $championship, function () use ($championship) {
            $standings = $this->medals->standings($championship->id);

            $podiums = WeightCategory::query()
                ->whereHas('ageCategory', fn ($q) => $q->where('championship_id', $championship->id))
                ->with('ageCategory')
                ->get()
                ->map(fn (WeightCategory $c) => ['category' => $c, 'podium' => $this->medals->forCategory($c)])
                ->filter(fn (array $row) => $row['podium']['decided'])
                ->values();

            return view('display.medals', compact('championship', 'standings', 'podiums'))->render();
        });
    }

    public function bracket(Request $request, WeightCategory $weightCategory): Response
    {
        $weightCategory->load('ageCategory.championship');
        $championship = $weightCategory->ageCategory->championship;

        return $this->render(
            $request,
            "bracket:{$weightCategory->id}",
            $championship,
            function () use ($weightCategory, $championship) {
                $bouts = $weightCategory->bouts()
                    ->with(['athleteA', 'athleteB', 'winner'])
                    ->orderBy('round')
                    ->orderBy('position_in_round')
                    ->get();

                $totalRounds = (int) ($bouts->max('round') ?? 0);
                $rounds = $bouts->groupBy('round');

                /*
                 | Dispatched on what the class was drawn as.
                 |
                 | A round robin rendered through the bracket view would draw a
                 | tree whose branches nobody walks: every athlete would appear
                 | in several unconnected boxes and the hall would read it as a
                 | draw that had gone wrong. It gets a fixture list and a table,
                 | which is what a round robin is.
                 */
                if ($weightCategory->isRoundRobin()) {
                    $standings = app(RoundRobinStandings::class)->forCategory($weightCategory);

                    return view('display.round-robin', compact(
                        'weightCategory', 'championship', 'rounds', 'standings'
                    ))->render();
                }

                if ($weightCategory->isPlacement()) {
                    return view('display.placement', compact('weightCategory', 'championship'))->render();
                }

                return view('display.bracket', compact('weightCategory', 'championship', 'rounds', 'totalRounds'))->render();
            }
        );
    }

    /**
     * Render through the cache, and answer an unchanged request with a 304.
     *
     * The ETag is the cache key, which already contains the championship's
     * version — so a display that has been refreshing every ten seconds all
     * afternoon transfers nothing at all until something actually changes.
     *
     * @param  \Closure(): string  $callback
     */
    private function render(Request $request, string $name, Championship $championship, \Closure $callback): Response
    {
        $key = DisplayCache::key($name, $championship->id);
        $etag = '"'.md5($key).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304)->setEtag($etag, weak: false);
        }

        $html = DisplayCache::remember($name, $championship->id, $callback);

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->setEtag($etag, weak: false);
    }
}
