<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Bout;
use App\Models\Championship;
use App\Models\Court;
use App\Models\WeightCategory;
use App\Support\ChampionshipStatus;
use App\Support\Gender;
use App\Support\MatState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Everything the dashboard needs to know about one championship.
 *
 * Read-only, and deliberately not the venue screens' code. Those are cached
 * standalone documents with their own ETags and meta refresh, and embedding or
 * copying them would tie the operator's home page to a five-minute cache and a
 * layout built for a projector. What is shared is the question each screen
 * asks, not the HTML either of them renders — so the live-bout definition lives
 * here once and DisplayController reads it too.
 *
 * Every method takes the championship rather than holding one, so a caller
 * polling a single panel pays for that panel and nothing else.
 */
final class DashboardSnapshot
{
    /** How many contests "Coming up" shows before deferring to the fight order. */
    public const COMING_UP = 5;

    /** How many NOCs the medal snapshot shows before deferring to the medal table. */
    public const TOP_NOCS = 3;

    /** @var array<int, Collection<int, WeightCategory>> */
    private array $categoryCache = [];

    public function __construct(private readonly MedalTable $medals) {}

    /*
     |--------------------------------------------------------------------------
     | Where the competition has got to
     |--------------------------------------------------------------------------
     |
     | Derived from the bouts first and the calendar second, because the bouts
     | are the only record that cannot be wrong. A championship whose dates say
     | it finished yesterday but which still has an undecided semi-final is not
     | finished, and the operator needs the screen to say so.
     */

    public function status(Championship $championship): ChampionshipStatus
    {
        if ($this->liveBoutQuery($championship)->exists()) {
            return ChampionshipStatus::Live;
        }

        $progress = $this->boutProgress($championship);

        if ($progress['total'] > 0 && $progress['decided'] === $progress['total']) {
            return ChampionshipStatus::Completed;
        }

        if ($progress['decided'] > 0) {
            return ChampionshipStatus::InProgress;
        }

        // Only now does the calendar get a say: nothing has been fought, so
        // "not started yet" and "starting later" are the same fact and the
        // date is the only thing that separates them.
        if ($championship->starts_on !== null && $championship->starts_on->isFuture()) {
            return ChampionshipStatus::Upcoming;
        }

        return ChampionshipStatus::Setup;
    }

    /*
     |--------------------------------------------------------------------------
     | What is on a mat right now
     |--------------------------------------------------------------------------
     |
     | A contest is live when it has been sent to a mat and started — status
     | on_court — not merely when it holds a court_id. Assigning a bout to a mat
     | and starting it are two actions, and the gap between them is where a
     | scheduled contest was being announced as live on the venue screens.
     |
     | The winner check stays as well. Status and result are written in the same
     | transaction, but a screen that says a decided contest is still being
     | fought is the one mistake a hall notices immediately.
     */

    /** @return HasMany<Bout, Championship> */
    public function liveBoutQuery(Championship $championship): HasMany
    {
        return $championship->bouts()
            ->where('status', Bout::STATUS_ON_COURT)
            ->whereNull('winner_athlete_id')
            ->whereNotNull('court_id')
            // A bout left on a mat that has since been taken out of service is
            // not on any screen in the hall, so it is not live on this one.
            ->whereHas('court', fn (Builder $q) => $q->where('is_active', true));
    }

    /**
     * Live contests keyed by the mat they are on.
     *
     * @return Collection<int, Bout>
     */
    public function liveBouts(Championship $championship): Collection
    {
        return $this->liveBoutQuery($championship)
            ->with(['athleteA', 'athleteB', 'weightCategory', 'court'])
            ->get()
            ->keyBy('court_id');
    }

    /**
     * One entry per active mat, occupied or not.
     *
     * Every active mat appears, because "Mat 3 is free" is the answer to a
     * question an operator asks constantly and an absent row does not give it.
     *
     * @return Collection<int, MatState>
     */
    public function mats(Championship $championship): Collection
    {
        $live = $this->liveBouts($championship);

        return $championship->courts()
            ->where('is_active', true)
            ->orderBy('number')
            ->get()
            ->map(fn (Court $court) => new MatState($court, $live->get($court->id)));
    }

    /*
     |--------------------------------------------------------------------------
     | What is coming next
     |--------------------------------------------------------------------------
     */

    /**
     * The next few contests that could be called, in running order.
     *
     * Not the whole fight order — that screen exists and is better at it. This
     * answers "what do I call next", which is a question about the top of the
     * list only.
     *
     * @return Collection<int, Bout>
     */
    public function comingUp(Championship $championship, int $limit = self::COMING_UP): Collection
    {
        return $championship->bouts()
            ->readyToFight()
            ->where('is_bye', false)
            // Already on a mat, so it is in the panel above this one.
            ->whereNull('court_id')
            // Without a number there is no "next": the running order has not
            // been built, and the dashboard says that instead of guessing.
            ->whereNotNull('fight_number')
            ->with(['athleteA', 'athleteB', 'weightCategory'])
            ->orderBy('fight_number')
            ->limit($limit)
            ->get();
    }

    /** Has a running order been built at all? Separates "none waiting" from "none numbered". */
    public function hasRunningOrder(Championship $championship): bool
    {
        return $championship->bouts()->whereNotNull('fight_number')->exists();
    }

    /*
     |--------------------------------------------------------------------------
     | How far the competition has got
     |--------------------------------------------------------------------------
     |
     | Athletes and bouts are counted separately and never added together. A
     | single funnel reading "136 → 121 → 80" where the last number is contests
     | invites the reader to subtract two quantities that do not share a unit.
     */

    /**
     * Registered → passed the scale → in a generated draw.
     *
     * @return array{registered: int, passed: int, drawn: int}
     */
    public function athleteWorkflow(Championship $championship): array
    {
        $drawn = $this->categories($championship)
            ->filter(fn (WeightCategory $c) => $c->hasDraw())
            ->sum(function (WeightCategory $c) {
                // The count recorded when the draw was generated, which is what
                // that draw actually contains. Falling back to today's field
                // would make this number move when somebody registers late,
                // reporting a change to a draw that has not been regenerated.
                //
                // The fallback covers draws made before the column existed;
                // there the live field is the only figure there has ever been.
                return $c->draw_athlete_count ?? $c->drawn_athletes_count;
            });

        return [
            'registered' => $championship->athletes()->count(),
            'passed' => $championship->athletes()->passedWeighIn()->count(),
            'drawn' => (int) $drawn,
        ];
    }

    /**
     * Contests decided out of contests to fight.
     *
     * Byes are excluded from both halves. A bye is a walkover recorded as a row
     * so the bracket links up; counting it as a contest decided would report
     * progress nobody fought for.
     *
     * @return array{decided: int, total: int, percent: int}
     */
    public function boutProgress(Championship $championship): array
    {
        $bouts = $championship->bouts()->where('is_bye', false);

        $total = (clone $bouts)->count();
        $decided = (clone $bouts)->whereNotNull('winner_athlete_id')->count();

        return [
            'decided' => $decided,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($decided / $total * 100) : 0,
        ];
    }

    /*
     |--------------------------------------------------------------------------
     | The medal snapshot
     |--------------------------------------------------------------------------
     */

    /**
     * The leading NOCs and how much of the championship has been decided.
     *
     * MedalTable owns every rule about what a podium is; this asks it once and
     * takes the top of the answer. Deciding here which NOC leads would be a
     * second implementation of a rule that has already been got wrong once.
     *
     * @return array{
     *     leaders: Collection<int, array{noc_code:string, gold:int, silver:int, bronze:int, total:int}>,
     *     decided: int,
     *     total: int
     * }
     */
    public function medalSnapshot(Championship $championship): array
    {
        $summary = $this->medals->summary($championship->id);

        return [
            'leaders' => $summary['standings']->take(self::TOP_NOCS),
            'decided' => $summary['decided'],
            'total' => $summary['total'],
        ];
    }

    /*
     |--------------------------------------------------------------------------
     | Headline figures
     |--------------------------------------------------------------------------
     */

    /** @return array{athletes: int, classes: int, bouts: int, mats: int} */
    public function counts(Championship $championship): array
    {
        return [
            'athletes' => $championship->athletes()->count(),
            'classes' => $this->categories($championship)->count(),
            'bouts' => $championship->bouts()->where('is_bye', false)->count(),
            'mats' => $championship->courts()->where('is_active', true)->count(),
        ];
    }

    /*
     |--------------------------------------------------------------------------
     | What is blocking the competition
     |--------------------------------------------------------------------------
     |
     | In the order an event hits them, and stopping at the first that makes the
     | rest meaningless: a championship with no weight classes has nothing to
     | say about draws.
     |
     | A contest on a mat is not in here. Normal live activity was being
     | reported as something requiring attention, which trained operators to
     | ignore the panel that also carries the real blockers.
     |
     | Every item states the problem to anybody who can read the screen. Only
     | the link is gated: an action offered to somebody who would be refused is
     | worse than no link at all.
     */

    /**
     * @return list<array{
     *     key: string, text: string, route: ?string,
     *     params: array<string, mixed>, label: ?string
     * }>
     */
    public function attention(Championship $championship): array
    {
        $categories = $this->categories($championship);
        $manages = Gate::allows('manage-competition');

        if ($categories->isEmpty()) {
            return [$this->item(
                'no-categories',
                __('No weight classes have been set up.'),
                'championships.show',
                ['championship' => $championship],
                __('Set up categories'),
                $manages,
            )];
        }

        $registered = $championship->athletes()->count();

        if ($registered === 0) {
            $competitions = $championship->configuredGenders();

            return [$this->item(
                'no-athletes',
                __('Nobody is registered yet.'),
                'athletes.index',
                ['championship' => $championship, 'competition' => $competitions[0] ?? Gender::MEN],
                __('Register athletes'),
                $manages,
            )];
        }

        $items = [];

        /*
         | Grouped by competition rather than totalled, because the weigh-in is
         | a screen per competition and one figure cannot link to the right one.
         |
         | The competition is the age category's, not the athlete's own gender:
         | `athletes.gender` is M or F, while a division may be run open, and
         | it is the division the weigh-in screen is scoped by.
         */
        $awaiting = $championship->athletes()
            ->join('age_categories', 'athletes.age_category_id', '=', 'age_categories.id')
            ->where('athletes.weighin_status', Athlete::WEIGHIN_PENDING)
            ->groupBy('age_categories.gender')
            ->selectRaw('age_categories.gender AS competition, COUNT(*) AS total')
            ->pluck('total', 'competition');

        // Iterated over the known competitions rather than over the result, so
        // the rows come out in a fixed order however the database returns them.
        foreach (Gender::ALL as $competition) {
            $count = (int) ($awaiting[$competition] ?? 0);

            if ($count === 0) {
                continue;
            }

            // The weigh-in screen 404s on a competition the championship does
            // not run. The count is still stated — those athletes exist and
            // somebody has to deal with them — but it is not offered as a door
            // that would slam.
            $reachable = in_array($competition, $championship->configuredGenders(), true);

            $items[] = $this->item(
                "weigh-in-{$competition}",
                trans_choice(
                    '{1}:count :competition athlete has not been weighed in.|[2,*]:count :competition athletes have not been weighed in.',
                    $count,
                    ['count' => $count, 'competition' => __(Gender::label($competition))],
                ),
                'weighin.index',
                ['championship' => $championship, 'competition' => $competition],
                __('Open the weigh-in'),
                $manages && $reachable,
            );
        }

        /*
         | Asked of the stored draw, never of the bouts table. A class settled by
         | placing one unopposed athlete is drawn and has no contests at all;
         | counting bouts calls it undrawn and offers to draw it again, every
         | time the dashboard loads, for the rest of the competition.
         */
        $undrawn = $categories->filter(
            fn (WeightCategory $c) => $c->eligible_athletes_count > 0 && ! $c->hasDraw()
        );

        if ($undrawn->isNotEmpty()) {
            $first = $undrawn->first();

            $items[] = $this->item(
                'undrawn',
                trans_choice(
                    '{1}:count weight class has athletes but no draw.|[2,*]:count weight classes have athletes but no draw.',
                    $undrawn->count(),
                    ['count' => $undrawn->count()],
                ),
                'bracket.show',
                ['weightCategory' => $first],
                __('Draw :label kg', ['label' => $first->label]),
                $manages,
            );
        }

        $unpublished = $categories->filter(
            fn (WeightCategory $c) => $c->hasDraw() && ! $c->isDrawPublished()
        );

        if ($unpublished->isNotEmpty()) {
            $first = $unpublished->first();

            $items[] = $this->item(
                'unpublished',
                trans_choice(
                    '{1}:count draw has been generated but not published.|[2,*]:count draws have been generated but not published.',
                    $unpublished->count(),
                    ['count' => $unpublished->count()],
                ),
                'bracket.show',
                ['weightCategory' => $first],
                __('Publish :label kg', ['label' => $first->label]),
                $manages,
            );
        }

        $unscheduled = $championship->bouts()
            ->where('is_bye', false)
            ->whereNull('fight_number')
            ->count();

        if ($unscheduled > 0) {
            $items[] = $this->item(
                'unscheduled',
                trans_choice(
                    '{1}:count contest has no fight number.|[2,*]:count contests have no fight number.',
                    $unscheduled,
                    ['count' => $unscheduled],
                ),
                'fight-order.index',
                ['championship' => $championship],
                __('Build the running order'),
                $manages,
            );
        }

        if ($championship->courts()->where('is_active', true)->count() === 0) {
            $items[] = $this->item(
                'no-mats',
                __('No mats are active, so nothing can be sent to a scoreboard.'),
                'courts.index',
                ['championship' => $championship],
                __('Add a mat'),
                $manages,
            );
        }

        return $items;
    }

    /*
     |--------------------------------------------------------------------------
     | Internals
     |--------------------------------------------------------------------------
     */

    /**
     * One item, with its link removed for anybody who could not carry it out.
     *
     * @param  array<string, mixed>  $params
     * @return array{key: string, text: string, route: ?string, params: array<string, mixed>, label: ?string}
     */
    private function item(
        string $key,
        string $text,
        ?string $route,
        array $params,
        ?string $label,
        bool $authorized,
    ): array {
        return [
            'key' => $key,
            'text' => $text,
            'route' => $authorized ? $route : null,
            'params' => $authorized ? $params : [],
            'label' => $authorized ? $label : null,
        ];
    }

    /**
     * The championship's weight classes, with every count the page needs.
     *
     * Loaded once and held for the request. Six of the questions above are
     * asked of the same rows, and each of them walking the table again is how a
     * dashboard becomes the slowest screen in the application.
     *
     * @return Collection<int, WeightCategory>
     */
    private function categories(Championship $championship): Collection
    {
        return $this->categoryCache[$championship->id] ??= WeightCategory::query()
            ->whereHas('ageCategory', fn (Builder $q) => $q->where('championship_id', $championship->id))
            ->withCount(['athletes', 'bouts', 'eligibleAthletes', 'drawnAthletes'])
            ->orderBy('sort_order')
            ->get();
    }
}
