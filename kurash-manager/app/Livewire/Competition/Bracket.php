<?php

namespace App\Livewire\Competition;

use App\Jobs\PushBoutToScoreboard;
use App\Models\Athlete;
use App\Models\Bout;
use App\Models\BoutEvent;
use App\Models\Court;
use App\Models\WeightCategory;
use App\Services\BoutAdvancer;
use App\Services\BracketHasResultsException;
use App\Services\DrawEligibility;
use App\Services\DrawEligibilityException;
use App\Services\DrawFormatException;
use App\Services\DrawGenerator;
use App\Services\DrawIsProtectedException;
use App\Services\MedalTable;
use App\Services\RoundRobinGenerator;
use App\Services\RoundRobinStandings;
use App\Services\TournamentFormatPolicy;
use App\Support\BracketSeeding;
use App\Support\TournamentFormat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Draw, bracket and results for one weight class — the three screens the old
 * system spread across draw-select-weight, draw-reveal, bracket-view and
 * fight-order, connected by CSV files on disk. Here they are one screen backed
 * by one table.
 */
class Bracket extends Component
{
    public WeightCategory $weightCategory;

    /**
     * Athlete id => draw number. Seeded as strings, but the browser owns this
     * array once the page is live and an emptied number input hydrates back as
     * null, so both are handled in saveDraws().
     *
     * @var array<int, string|null>
     */
    public array $draws = [];

    /**
     * The format the administrator has chosen for a draw not yet generated.
     *
     * A string because it is bound to a select, and the browser owns it once
     * the page is live. It is never trusted: DrawGenerator checks it against
     * the rule on the server every time, so a hand-edited option cannot draw a
     * round robin of sixteen.
     */
    public string $format = '';

    /**
     * Why this class is being run against the IKA rule.
     *
     * Required before a small field can be drawn as a knockout, and stored on
     * the class with the administrator's name against it.
     */
    public string $overrideReason = '';

    /** Set when the chosen format departs from the rule and needs confirming. */
    public bool $confirmingOverride = false;

    public bool $confirmingRegenerate = false;

    public bool $confirmingDelete = false;

    /** Set when a draw that somebody may already be presenting would be replaced. */
    public bool $confirmingReplacePublished = false;

    /**
     * Bout id => the running-order number typed into its box.
     *
     * Strings, because that is what a text input hydrates back as, and an
     * emptied one comes back as null — both are handled where they are saved.
     *
     * @var array<int, string|null>
     */
    public array $fightNumbers = [];

    /**
     * The largest number the column will hold.
     *
     * `fight_number` is an unsigned smallint. A championship never approaches
     * this — a thousand-bout weekend is a very large one — but the box has to
     * refuse what the column cannot store rather than truncate it.
     */
    public const MAX_FIGHT_NUMBER = 65535;

    public function mount(WeightCategory $weightCategory): void
    {
        $this->weightCategory = $weightCategory->load('ageCategory.championship');
        $this->syncDraws();
        $this->syncFightNumbers();
        $this->syncFormat();
    }

    /**
     * Put the selector where the rule says it should start.
     *
     * This is a choice about the *next* draw, not a label for the current one
     * — what the class was drawn as is shown by the table itself, further down
     * the screen. So it starts at what drawing now would produce: the stored
     * preference if an administrator has expressed one and the field still
     * allows it, and otherwise the IKA default, which for a small field is the
     * round robin.
     *
     * Seeding it from the existing draw instead would mean a class drawn as a
     * small knockout asked for a fresh override reason merely to be looked at,
     * and would quietly re-propose a departure from the rule as the default.
     */
    private function syncFormat(): void
    {
        // Compliant resolution, so a stored small-field knockout preference
        // never arrives pre-selected with its warning unarmed: an override is
        // chosen and signed afresh each time, through updatedFormat().
        //
        // `??` reads through a null with isset() semantics, so no nullsafe
        // arrow is needed — and PHPStan flags one that is not.
        $this->format = app(TournamentFormatPolicy::class)
            ->resolveCompliantFor($this->weightCategory)->value ?? '';
    }

    /**
     * Choosing knockout for a small field arms the confirmation.
     *
     * The warning and the reason box appear here rather than on submission, so
     * an administrator sees what they are departing from before they have
     * typed anything — not after they have pressed Generate.
     */
    public function updatedFormat(string $value): void
    {
        $chosen = TournamentFormat::tryFromValue($value);
        $athletes = $this->weightCategory->drawnAthletes()->count();

        $this->confirmingOverride = $chosen !== null
            && app(TournamentFormatPolicy::class)->isOverride($athletes, $chosen);

        if (! $this->confirmingOverride) {
            $this->overrideReason = '';
        }
    }

    private function syncFightNumbers(): void
    {
        $this->fightNumbers = $this->weightCategory->bouts()
            ->get()
            ->mapWithKeys(fn (Bout $bout) => [$bout->id => (string) ($bout->fight_number ?? '')])
            ->all();
    }

    private function syncDraws(): void
    {
        $this->draws = $this->athletes()
            ->mapWithKeys(fn (Athlete $a) => [$a->id => (string) ($a->draw_number ?? '')])
            ->all();
    }

    /**
     * The class, in the order a register is read.
     *
     * By accreditation number rather than by name or by draw number: this is
     * the list somebody works down with a card in their hand, and it is the
     * same order the printed list comes out in — Athlete::entryOrder() is the
     * only comparator either uses.
     *
     * @return Collection<int, Athlete>
     */
    private function athletes(): Collection
    {
        return $this->weightCategory->athletes()
            ->get()
            ->sortBy(fn (Athlete $athlete) => $athlete->entryOrder())
            ->values();
    }

    public function saveDraws(): void
    {
        Gate::authorize('manage-competition');

        $seen = [];

        foreach ($this->draws as $athleteId => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            if (! ctype_digit((string) $value) || (int) $value < 1) {
                session()->flash('error', __('Draw numbers must be whole numbers of 1 or more.'));

                return;
            }

            $number = (int) $value;

            if (isset($seen[$number])) {
                session()->flash('error', __('Draw number :n is used twice. Each athlete needs their own.', ['n' => $number]));

                return;
            }

            $seen[$number] = (int) $athleteId;
        }

        /*
         | The ids come from the browser, so they are not believed.
         |
         | $draws is a public Livewire property keyed by athlete id: its keys
         | are whatever was posted, not whatever was rendered. Loading them
         | through this category's own relation is what makes an id from
         | another class — or from another championship — resolve to nothing
         | rather than to somebody else's athlete being given a number here.
         */
        $candidates = $this->weightCategory->athletes()
            ->whereIn('id', array_values($seen))
            ->get()
            ->keyBy('id');

        foreach ($seen as $number => $athleteId) {
            if (! $candidates->has($athleteId)) {
                session()->flash('error', __('That draw number was submitted for somebody who is not in this weight class. Nothing was saved.'));

                return;
            }
        }

        // The rule, before anything is written. Named, because an official has
        // to go and find the person — and refused as a whole, so a screenful
        // of numbers is never half-saved around the one that was wrong.
        $ineligible = $candidates->reject(fn (Athlete $athlete) => $athlete->passedWeighIn())->values();

        if ($ineligible->isNotEmpty()) {
            session()->flash('error', app(DrawEligibility::class)->refusal(
                $ineligible,
                __('No draw numbers were saved.'),
            ));

            return;
        }

        // Cleared first, then written, and both inside one transaction. Draw
        // numbers are unique per category, so writing them one at a time in
        // place fails the moment two athletes swap: the first update tries to
        // take a number the second is still holding.
        //
        // Written by query rather than through the loaded models for the same
        // reason drawAtRandom() does: an Eloquent model only persists *dirty*
        // attributes, and these were loaded before the clear, so an athlete
        // keeping the number they already had would look unchanged and be left
        // at NULL.
        $saved = DB::transaction(function () use ($seen) {
            WeightCategory::whereKey($this->weightCategory->id)->lockForUpdate()->firstOrFail();

            // Asked again under the lock, against the same ids: the check
            // above ran before the lock was held, and a weigh-in recorded in
            // between would otherwise slip a failure into the draw.
            $stillEligible = $this->weightCategory->athletes()
                ->whereIn('id', array_values($seen))
                ->passedWeighIn()
                ->pluck('id')
                ->all();

            if (count($stillEligible) !== count($seen)) {
                return false;
            }

            $this->weightCategory->athletes()->update(['draw_number' => null, 'draw_number_source' => null]);

            foreach ($seen as $number => $athleteId) {
                Athlete::whereKey($athleteId)->update([
                    'draw_number' => $number,
                    'draw_number_source' => 'manual',
                ]);
            }

            return true;
        });

        if ($saved === false) {
            session()->flash('error', __('Somebody in this list stopped being eligible while it was being saved. Nothing was saved — check the weigh-in and try again.'));

            return;
        }

        $this->syncDraws();

        // Changing the numbers does not move anybody in a bracket that already
        // exists — the draw is what the bracket was built from, not something
        // it reads live. Saying so beats leaving an official to wonder why the
        // tree on screen did not move.
        session()->flash('status', $this->weightCategory->bouts()->exists()
            ? __('Draw numbers saved. Redraw the bracket for the new order to take effect.')
            : __('Draw numbers saved.'));
    }

    /**
     * Assign draw numbers at random to everyone who passed the scale.
     *
     * Everyone who *passed*, which is narrower than everyone who did not fail:
     * an athlete nobody has weighed yet is pending, and the IKA rule keeps
     * pending off the mat exactly as firmly as it keeps a failure off it. This
     * read "!= fail" and so handed numbers to athletes who had never stood on
     * the scale.
     */
    public function drawAtRandom(): void
    {
        Gate::authorize('manage-competition');

        if ($this->weightCategory->eligibleAthletes()->doesntExist()) {
            $this->drawFailed(__('Nobody in this class has passed the weigh-in.'));

            return;
        }

        /*
         | Chosen and written inside one transaction, under the category's own
         | row lock.
         |
         | The weigh-in desk and the draw screen are two people at two
         | computers. Selecting outside the transaction would read a field that
         | somebody is still changing — an athlete passed a moment after the
         | shuffle would be left out of a draw that says it included everybody
         | eligible, and one who failed a moment after it would be given a
         | number the rules forbid. So the field is re-read after the lock is
         | held, and it is the re-read one that is drawn.
         */
        $drawn = DB::transaction(function () {
            WeightCategory::whereKey($this->weightCategory->id)->lockForUpdate()->firstOrFail();

            $eligibleIds = $this->weightCategory->eligibleAthletes()->pluck('id')->shuffle();

            // Cleared for everybody, not only for the eligible: this is where a
            // number held by somebody who has since failed the scale is taken
            // back, and leaving it would let a fresh random draw still contain
            // them.
            $this->weightCategory->athletes()->update(['draw_number' => null, 'draw_number_source' => null]);

            // Write by query rather than through loaded models. An Eloquent
            // model only persists *dirty* attributes, and these were loaded
            // before the clear above — so any athlete drawn the same number
            // they already had would look unchanged and silently keep NULL.
            foreach ($eligibleIds->values() as $index => $id) {
                Athlete::whereKey($id)->update([
                    'draw_number' => $index + 1,
                    'draw_number_source' => 'random',
                ]);
            }

            return $eligibleIds->count();
        });

        if ($drawn === 0) {
            $this->drawFailed(__('Nobody in this class has passed the weigh-in.'));
            $this->syncDraws();

            return;
        }

        // The ceremony board reads this stamp to pace its reveal. The draw
        // itself is already committed above — what is paced is the telling of
        // it, never the drawing, so a hall watching position 4 appear is
        // watching a result that has been final for ten seconds.
        Cache::put(
            DrawCeremony::paceKey($this->weightCategory->id, (int) $this->weightCategory->draw_version),
            ['at' => (int) now()->timestamp, 'per' => 3],
            now()->addHour(),
        );

        $this->syncDraws();
        session()->flash('status', __('Drew :count athlete(s) at random.', ['count' => $drawn]));

        $this->dispatch('draw-completed', mode: 'positions');
    }

    public function generate(bool $discardResults = false, bool $replacePublished = false): void
    {
        Gate::authorize('manage-competition');

        $chosen = TournamentFormat::tryFromValue($this->format);
        $athletes = $this->weightCategory->drawnAthletes()->count();

        /*
         | The narrower permission, and only where it is actually needed.
         |
         | Drawing the format the rule gives is an ordinary competition
         | decision that any supervisor may take. Departing from the rule is
         | not, so the admin-only gate is asked here — and asked before
         | anything is written, so a refusal leaves the class untouched.
         */
        if ($chosen !== null && app(TournamentFormatPolicy::class)->isOverride($athletes, $chosen)) {
            Gate::authorize('draw.override_format');
        }

        try {
            $result = app(DrawGenerator::class)->generate(
                category: $this->weightCategory,
                format: $chosen,
                discardResults: $discardResults,
                replacePublished: $replacePublished,
                overrideReason: $this->overrideReason,
                user: auth()->user(),
            );
        } catch (DrawEligibilityException|DrawFormatException $e) {
            // Nothing to confirm in either: a request the rules do not allow,
            // an override with nobody's name on it, or somebody in the field
            // who has not passed the scale. All three are corrected rather
            // than confirmed.
            $this->drawFailed($e->getMessage());

            return;
        } catch (DrawIsProtectedException $e) {
            // A published draw is one other people have been told to work
            // from, so replacing it is a decision, not a click.
            $this->confirmingReplacePublished = ! $this->weightCategory->isDrawLocked();
            $this->drawFailed($e->getMessage());

            return;
        } catch (BracketHasResultsException $e) {
            $this->confirmingRegenerate = true;
            $this->drawFailed($e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->drawFailed($e->getMessage());

            return;
        }

        $this->confirmingRegenerate = false;
        $this->confirmingReplacePublished = false;
        $this->confirmingOverride = false;

        $this->syncDraws();
        $this->syncFightNumbers();
        $this->syncFormat();

        // The ceremony overlay is a bracket's; a round robin reveals pairings
        // rather than seats walking down a tree, and says which it is.
        $this->dispatch('draw-completed', mode: $result['format'] === TournamentFormat::Knockout ? 'bracket' : 'pairings');

        session()->flash('status', match ($result['format']) {
            TournamentFormat::RoundRobin => __('Round robin drawn: :bouts contests across :rounds rounds.', [
                'bouts' => $result['bouts'],
                'rounds' => $result['rounds'],
            ]),
            TournamentFormat::Placement => __('One athlete in this class. Place them to settle it.'),
            TournamentFormat::Knockout => __('Bracket drawn: :bouts bouts across :rounds rounds, :byes bye(s).', [
                'bouts' => $result['bouts'],
                'rounds' => $result['rounds'],
                'byes' => $result['byes'],
            ]),
        });
    }

    /**
     * Award a class of one to the athlete standing in it.
     *
     * Its own action, and its own decision. Nothing about being registered
     * unopposed makes somebody a champion, so this is a button an
     * administrator presses and their name goes on the record.
     */
    public function placeSoleAthlete(): void
    {
        Gate::authorize('manage-competition');

        try {
            $athlete = app(DrawGenerator::class)->placeSoleAthlete($this->weightCategory, auth()->user());
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->weightCategory->refresh();

        session()->flash('status', __('Placed :name first in this class.', ['name' => $athlete->fullname]));
    }

    /**
     * Tell the ceremony overlay the draw did not run.
     *
     * Flashed as well as dispatched: the overlay is decoration and the flash
     * is the record, so the message survives on the screen after the overlay
     * is dismissed.
     */
    private function drawFailed(string $message): void
    {
        session()->flash('error', $message);

        $this->dispatch('draw-failed', message: $message);
    }

    /**
     * Approve the drawn table for everybody else to see.
     *
     * Publication is what separates a table being worked on from one an
     * operator may present, so it is its own permission and its own decision —
     * generating a bracket deliberately does not publish it.
     */
    public function publishDraw(): void
    {
        Gate::authorize('draw.publish');

        if (! $this->weightCategory->hasDraw()) {
            session()->flash('error', __('There is no draw to publish yet.'));

            return;
        }

        /*
         | The last gate, and the one that matters most.
         |
         | Publication is what turns a table being worked on into one the hall
         | is told to work from, and it can be minutes or hours after the draw
         | was generated — a whole weigh-in session can have run in between.
         | So the field is checked again here, against the contests themselves
         | rather than against the draw numbers: taking somebody's number away
         | does not take them out of a bracket that was built around them, and
         | it is the bracket that is about to be published.
         |
         | Checked and published under one lock, so a status changing between
         | the two cannot be published by the writer that read before it.
         */
        $refused = DB::transaction(function (): ?string {
            $locked = WeightCategory::whereKey($this->weightCategory->id)->lockForUpdate()->firstOrFail();

            $ineligible = app(DrawEligibility::class)->ineligibleInGeneratedDraw($locked);

            if ($ineligible->isNotEmpty()) {
                return app(DrawEligibility::class)->refusal(
                    $ineligible,
                    __('This draw cannot be published: the weigh-in has changed since it was generated.'),
                );
            }

            $locked->forceFill(['draw_published_at' => now()])->save();

            return null;
        });

        if ($refused !== null) {
            session()->flash('error', $refused);

            return;
        }

        $this->weightCategory->refresh();

        // Building the official table and presenting it are two different
        // moments. A random draw leaves a pace stamp for the venue board, but
        // publication may happen minutes later; carrying that timestamp into
        // the operator screen would make the presentation finish before the
        // presenter had opened it. Publication therefore starts a fresh,
        // waiting presentation. The operator begins the reveal explicitly.
        Cache::forget(DrawCeremony::paceKey($this->weightCategory->id, (int) $this->weightCategory->draw_version));

        session()->flash('status', __('Draw published. Operators can present it now.'));
    }

    public function withdrawDraw(): void
    {
        Gate::authorize('draw.publish');

        $this->weightCategory->forceFill(['draw_published_at' => null])->save();

        session()->flash('status', __('Draw withdrawn. It is no longer available to operators.'));
    }

    /** Locked means not even an admin redraws it without unlocking first. */
    public function toggleDrawLock(): void
    {
        Gate::authorize('draw.publish');

        $locked = $this->weightCategory->isDrawLocked();

        $this->weightCategory->forceFill(['draw_locked_at' => $locked ? null : now()])->save();

        session()->flash('status', $locked ? __('Draw unlocked.') : __('Draw locked.'));
    }

    /**
     * Throw the drawn bracket away.
     *
     * Registration refuses to remove an athlete once their class has been
     * drawn, because deleting one out of a bracket leaves a tree with a hole
     * in it. That is the right refusal, but it needs a way out: this is it.
     * Draw numbers are deliberately kept, so the usual sequence — delete,
     * correct the entry list, redraw — costs nobody the draw they already
     * made.
     *
     * Deleted through the models rather than by one query, so the archived
     * championship guard fires: a closed competition's results are not
     * something a button should be able to erase.
     */
    public function deleteBracket(bool $discardResults = false): void
    {
        Gate::authorize('manage-competition');

        // hasDraw(), not bouts()->exists(): a placement draw has no contests
        // at all, and it too must be deletable — undoing a placement is done
        // the same way as undoing any other draw.
        if (! $this->weightCategory->hasDraw()) {
            session()->flash('error', __('There is no draw to delete.'));

            return;
        }

        // A contest being scored right now would vanish from under the mat
        // screen mid-bout, so that one is refused outright rather than
        // confirmed.
        if ($this->weightCategory->bouts()->where('status', Bout::STATUS_ON_COURT)->exists()) {
            session()->flash('error', __('A contest from this class is on a mat. Take it off the mat before deleting the bracket.'));

            return;
        }

        $decided = $this->weightCategory->bouts()
            ->whereNotNull('winner_athlete_id')
            ->where('is_bye', false)
            ->count();

        if ($decided > 0 && ! $discardResults) {
            $this->confirmingDelete = true;

            session()->flash('error', trans_choice(
                '{1}:count contest has been decided in this class. Deleting the bracket erases it.'
                .'|[2,*]:count contests have been decided in this class. Deleting the bracket erases them.',
                $decided,
                ['count' => $decided],
            ));

            return;
        }

        try {
            DB::transaction(function () {
                $this->weightCategory->bouts()->get()->each->delete();

                // The metadata described rows that no longer exist, and a
                // deleted draw is certainly not a published one.
                //
                // The lock goes with it. It says "do not draw this class
                // again", and it outlived the draw it was protecting — which
                // left the class refusing to be redrawn over a bracket that
                // was no longer there.
                $this->weightCategory->forceFill([
                    'draw_generated_at' => null,
                    'draw_athlete_count' => null,
                    'draw_bucket_size' => null,
                    'draw_bye_count' => null,
                    'draw_published_at' => null,
                    'draw_locked_at' => null,
                    // The format is the record that a draw exists — hasDraw()
                    // reads it — so a deleted draw must surrender it, or the
                    // class would still publish, present and export a draw
                    // that is no longer there. The override signature and the
                    // placement go with it: both signed for the draw being
                    // deleted, not for whatever is drawn next. Only the
                    // preference survives, because it describes the next draw
                    // rather than this one.
                    'draw_format' => null,
                    'draw_format_override_reason' => null,
                    'draw_format_override_by' => null,
                    'draw_format_override_at' => null,
                    'draw_placement_athlete_id' => null,
                    'draw_placement_by' => null,
                    'draw_placement_at' => null,
                ])->save();
            });
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->confirmingDelete = false;
        $this->confirmingRegenerate = false;

        session()->flash('status', __('Bracket deleted. The draw numbers are kept, so this class can be drawn again.'));
    }

    /**
     * Put a bout on a mat and tell that mat's display about it.
     *
     * The push is queued: a scoreboard on a flaky venue network can take the
     * whole timeout to fail, and the official pressing this should not wait
     * for it, nor should the assignment fail because a display is unplugged.
     */
    public function sendToMat(int $boutId, int $courtId): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->weightCategory->bouts()->findOrFail($boutId);

        if (! $bout->isReadyToFight()) {
            session()->flash('error', __('That bout is not ready — both athletes must be known.'));

            return;
        }

        $court = Court::where('championship_id', $this->weightCategory->ageCategory->championship_id)
            ->where('is_active', true)
            ->find($courtId);

        if ($court === null) {
            session()->flash('error', __('That mat is not available in this championship.'));

            return;
        }

        $bout->update(['court_id' => $court->id, 'status' => Bout::STATUS_ON_COURT]);

        PushBoutToScoreboard::dispatch($bout->id);

        session()->flash('status', __('Sent to :mat.', ['mat' => $court->label()]));
    }

    public function recordResult(int $boutId, string $side): void
    {
        Gate::authorize('manage-competition');

        $bout = $this->weightCategory->bouts()->findOrFail($boutId);
        $winnerId = $side === 'a' ? $bout->athlete_a_id : $bout->athlete_b_id;

        if ($winnerId === null) {
            session()->flash('error', __('That side has no athlete yet.'));

            return;
        }

        try {
            app(BoutAdvancer::class)->recordResult(
                bout: $bout,
                winnerAthleteId: $winnerId,
                winType: 'khalol',
                user: auth()->user(),
                source: 'operator',
            );
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', __('Result recorded.'));
    }

    /**
     * Give a contest its place in the running order, by hand.
     *
     * The other way of numbering a championship is FightOrderScheduler, which
     * clears every number and lays a whole running order out at once. This is
     * the other trade: one contest, one number, and nothing else on the sheet
     * moves. Neither calls the other, and nothing on this screen — opening it,
     * recording a result, advancing a winner — numbers anything by itself.
     *
     * Numbers are unique within the championship, which is the scope the
     * schedule is read in: "bout 14" is called across a whole session, not
     * within one weight class. The column carries no unique index, because the
     * scheduler renumbers wholesale and a partial write would trip it, so the
     * guarantee is this check taken under a row lock inside a transaction.
     */
    public function setFightNumber(int $boutId): void
    {
        Gate::authorize('manage-competition');

        $championship = $this->weightCategory->ageCategory->championship;

        // A finished championship is a record, not a schedule.
        abort_if($championship->isArchived(), 403);

        $bout = $this->weightCategory->bouts()->findOrFail($boutId);

        // A walkover is not a contest, so there is nothing to call.
        if ($bout->is_bye) {
            session()->flash('error', __('A bye has no contest to number.'));
            $this->syncFightNumbers();

            return;
        }

        $typed = trim((string) ($this->fightNumbers[$boutId] ?? ''));

        // Cleared on purpose, which is a decision somebody is allowed to make.
        if ($typed === '') {
            $this->writeFightNumber($bout, null);

            return;
        }

        // ctype_digit and not is_numeric: "3.5", "1e3" and "-2" are all
        // numeric, and none of them is a place in a running order.
        if (! ctype_digit($typed) || (int) $typed < 1 || (int) $typed > self::MAX_FIGHT_NUMBER) {
            session()->flash('error', __('A fight number is a whole number from 1 to :max.', ['max' => self::MAX_FIGHT_NUMBER]));
            $this->syncFightNumbers();

            return;
        }

        $number = (int) $typed;

        if ($number === $bout->fight_number) {
            return;
        }

        try {
            DB::transaction(function () use ($bout, $championship, $number) {
                $taken = Bout::where('championship_id', $championship->id)
                    ->where('fight_number', $number)
                    ->whereKeyNot($bout->id)
                    ->lockForUpdate()
                    ->first();

                if ($taken !== null) {
                    throw new RuntimeException(__('Fight number :n is already given to another contest.', ['n' => $number]));
                }

                $this->writeFightNumber($bout, $number);
            });
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
            $this->syncFightNumbers();

            return;
        }

        session()->flash('status', __('Fight number saved.'));
    }

    /**
     * Write the number and leave a row saying who changed it.
     *
     * The bout's own history is where an administrative change belongs: the
     * same place a result correction is recorded, so one reading of a contest
     * shows everything that was done to it.
     */
    private function writeFightNumber(Bout $bout, ?int $number): void
    {
        $before = $bout->fight_number;

        if ($before === $number) {
            return;
        }

        $bout->update(['fight_number' => $number]);

        BoutEvent::createInSequence([
            'bout_id' => $bout->id,
            'user_id' => auth()->id(),
            'action' => 'fight_number_set',
            'source' => 'operator',
            'before' => ['fight_number' => $before],
            'after' => ['fight_number' => $number],
        ]);

        $this->syncFightNumbers();
    }

    public function render(): View
    {
        $bouts = $this->weightCategory->bouts()
            ->with(['athleteA', 'athleteB', 'winner'])
            ->orderBy('round')
            ->orderBy('position_in_round')
            ->get();

        // Counted once and passed down: drawSummary, projectedSize and the
        // format choices must all describe the same field, and one count per
        // render is also five fewer queries on the busiest admin screen.
        $drawnCount = $this->weightCategory->drawnAthletes()->count();

        return view('livewire.competition.bracket', [
            'athletes' => $this->athletes(),
            'bouts' => $bouts,
            'rounds' => $bouts->groupBy('round'),
            'totalRounds' => (int) ($bouts->max('round') ?? 0),
            'podium' => app(MedalTable::class)->forCategory($this->weightCategory),
            'drawnCount' => $drawnCount,
            'courts' => Court::where('championship_id', $this->weightCategory->ageCategory->championship_id)
                ->where('is_active', true)
                ->orderBy('number')
                ->get(),
            'projectedSize' => $this->projectedSize($drawnCount),
            // Read from the same count the choices are offered from, so the
            // summary and the selector can never disagree about the field.
            'drawSummary' => $this->drawSummary($drawnCount),

            // What this class was drawn as, and what drawing it now would
            // produce. Two questions, never conflated: the first is what the
            // screen is showing, the second is what the button would do —
            // and the second is the compliant resolution, because "what the
            // button would do" is a draw nobody has signed an override for.
            'drawnFormat' => $this->weightCategory->drawFormat(),
            'resolvedFormat' => app(TournamentFormatPolicy::class)
                ->resolveCompliantFor($this->weightCategory, $drawnCount),
            'formatChoices' => app(TournamentFormatPolicy::class)->availableFor($drawnCount),
            'mayOverride' => Gate::allows('draw.override_format'),

            /*
             | Athletes holding a number the rules do not admit.
             |
             | Empty in anything drawn since eligibility was enforced. Where it
             | is not — a championship imported from the legacy database, or a
             | row changed outside the application — the screen says so rather
             | than quietly dropping them from the counts, because a class that
             | silently lost an entrant between two page loads is a worse
             | failure than one that explains what is wrong with it.
             */
            'ineligibleNumbered' => $this->weightCategory->ineligibleNumberedAthletes()->get(),

            // The same question asked of the contests rather than of the draw
            // numbers, which is what publication is refused on.
            'ineligibleInDraw' => $this->weightCategory->hasDraw()
                ? app(DrawEligibility::class)->ineligibleInGeneratedDraw($this->weightCategory)
                : collect(),
            'standings' => $this->weightCategory->isRoundRobin()
                ? app(RoundRobinStandings::class)->forCategory($this->weightCategory)
                : null,
        ]);
    }

    /**
     * What drawing now would produce.
     *
     * @return array{athletes:int, size:int, byes:int, firstRound:int}
     */
    private function drawSummary(int $athletes): array
    {
        $format = app(TournamentFormatPolicy::class)->resolveCompliantFor($this->weightCategory, $athletes);

        // A round robin has no bracket to round up to and nobody sits out, so
        // the figures a bracket summary quotes are the wrong ones entirely:
        // what an administrator wants to know before drawing one is how many
        // contests it will cost the session.
        if ($format === TournamentFormat::RoundRobin) {
            return [
                'athletes' => $athletes,
                'size' => 0,
                'byes' => 0,
                'contests' => RoundRobinGenerator::contestsFor($athletes),
                'rounds' => RoundRobinGenerator::roundsFor($athletes),
                'firstRound' => 0,
            ];
        }

        $size = $athletes >= 2 ? BracketSeeding::size($athletes) : 0;
        $byes = max(0, $size - $athletes);

        return [
            'athletes' => $athletes,
            'size' => $size,
            'byes' => $byes,
            'contests' => max(0, $size - 1),
            'rounds' => BracketSeeding::totalRounds($size),
            // Every first-round pair that is not a walkover.
            'firstRound' => $size > 0 ? max(0, intdiv($size, 2) - $byes) : 0,
        ];
    }

    /**
     * The bracket size the header quotes, or null where there is no bracket.
     *
     * Null for a round robin and for a class of one: neither has a bucket to
     * round up to, and "bracket of 4" over a round robin of four would be a
     * caption describing a different competition.
     */
    private function projectedSize(int $drawn): ?int
    {
        $format = $this->weightCategory->drawFormat()
            ?? app(TournamentFormatPolicy::class)->resolveCompliantFor($this->weightCategory, $drawn);

        if ($format !== TournamentFormat::Knockout || $drawn < 2) {
            return null;
        }

        return BracketSeeding::size($drawn);
    }
}
