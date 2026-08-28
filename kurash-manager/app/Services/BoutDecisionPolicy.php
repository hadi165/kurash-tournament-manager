<?php

namespace App\Services;

use App\Models\Bout;
use App\Support\BoutDecision;
use App\Support\ScoreTally;

/**
 * Who wins a contest, and under which edition of the rules.
 *
 * Split out of ScoreTally because deciding a competition is not a property of a
 * score column. A tally can say what an athlete holds; only a policy can say
 * which of two athletes the federation's rules prefer, cite the clause, and
 * name the edition it applied — and a result that cannot cite its clause cannot
 * survive a protest.
 *
 * The order is not written here. It is read from
 * config('kurash.bout_decision.versions.<edition>.order'), so a later edition
 * can reorder the tie-break without touching this class, and a championship
 * fought under an earlier one keeps being read under that one.
 *
 * ── What changed, and why ────────────────────────────────────────────────────
 *
 * Score origin has been in the wrong place twice. It was once the SECOND step,
 * above count, where it decided contests before the count rule was reached;
 * then it was removed altogether, which let a later automatic score defeat an
 * earlier technique-earned one. The federation has settled it: origin ranks,
 * below count and above recency.
 *
 * The warning rule has also been both ways. It compares the LATEST warning and
 * the athlete holding it loses. A reading of "cautioned first wins" shipped
 * briefly; it agrees with this one whenever each athlete holds a single warning
 * and disagrees from the second onward, which is how it passed a whole suite.
 *
 * ── Where it refuses ─────────────────────────────────────────────────────────
 *
 * A combination the published page does not resolve returns "referee decision
 * required" with the ambiguity named, rather than falling through to side,
 * draw order or anything else this project would have had to invent. The
 * ambiguities are listed in config and reproduced in the verdict.
 */
class BoutDecisionPolicy
{
    /**
     * The edition this bout is judged under.
     *
     * Pinned on the championship, exactly as age_policy_version is, so a
     * competition keeps its rules when a later edition ships. An unknown or
     * absent pin falls back to the configured edition rather than to the
     * newest — silently promoting an old competition to new rules is the thing
     * this versioning exists to prevent.
     */
    public function versionFor(?Bout $bout): int
    {
        $pinned = $bout?->ageCategory?->championship?->decision_policy_version;

        if ($pinned !== null && $this->editionExists((int) $pinned)) {
            return (int) $pinned;
        }

        return (int) config('kurash.bout_decision.fallback_version', 2022);
    }

    /**
     * Decide a contest that has reached time.
     *
     * Terminal outcomes are checked first and are not part of the tie-break: a
     * khalol, the configured yonbosh accumulation, a girrom or the configured
     * madichal count end a contest whatever else is on the board.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     */
    public function decide(Bout $bout, array $tally): BoutDecision
    {
        $version = $this->versionFor($bout);

        if ($terminal = $this->terminalOutcome($tally, $version)) {
            return $terminal;
        }

        foreach ($this->order($version) as $step) {
            $decision = $this->applyStep((string) ($step['step'] ?? ''), $tally, $version, $step);

            if ($decision !== null) {
                return $decision;
            }
        }

        return BoutDecision::refereeRequired($version, $this->ambiguities($version));
    }

    /*
     |--------------------------------------------------------------------------
     | The steps
     |--------------------------------------------------------------------------
     |
     | Each returns a decision when it separates the two athletes, null when it
     | does not, and a referee-required decision when the fact it looks at
     | differs in a way the published rules do not cover.
     */

    /**
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     * @param  array<string, mixed>  $step
     */
    private function applyStep(string $name, array $tally, int $version, array $step): ?BoutDecision
    {
        return match ($name) {
            'higher_appraisal' => $this->higherAppraisal($tally, $version, $step),
            'more_chala' => $this->moreChala($tally, $version, $step),
            'technique_origin' => $this->techniqueOrigin($tally, $version, $step),
            'last_appraisal' => $this->lastAppraisal($tally, $version, $step),
            'latest_warning' => $this->latestWarning($tally, $version, $step),
            // An edition naming a step this class does not implement must not
            // be silently skipped: the contest would be decided by a rule set
            // nobody has written, which is exactly what this refactor removed.
            default => BoutDecision::refereeRequired($version, [
                'unimplemented_step' => "The configured edition names a decision step '{$name}' that this software does not implement.",
            ]),
        };
    }

    /**
     * The more valuable appraisal wins.
     *
     * Also the clause that puts a score above a caution: an athlete holding any
     * appraisal has a top priority above zero, and one holding only cautions
     * has zero, so "an appraisal takes precedence over a caution" needs no step
     * of its own.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     * @param  array<string, mixed>  $step
     */
    private function higherAppraisal(array $tally, int $version, array $step): ?BoutDecision
    {
        if ($tally['a']->topPriority() === $tally['b']->topPriority()) {
            return null;
        }

        $side = $tally['a']->topPriority() > $tally['b']->topPriority() ? 'a' : 'b';

        return $this->won($side, 'higher_appraisal', $version, $step, $tally[$side]->lastScoreAt);
    }

    /**
     * More Chala wins, where Chala is what both athletes are holding.
     *
     * Scoped to Chala on purpose. "More Chala wins" is the only count rule the
     * published page states. A difference in Yonbosh counts at an equal top
     * value is unreachable in the shipped configuration — two Yonbosh are a
     * Khalol, so a contest that gets here has at most one each — and where an
     * edition makes it reachable the page does not say, so it asks for a
     * referee rather than guessing.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     * @param  array<string, mixed>  $step
     */
    private function moreChala(array $tally, int $version, array $step): ?BoutDecision
    {
        foreach ([KurashScore::KHALOL, KurashScore::YONBOSH] as $call) {
            if ($tally['a']->count($call) !== $tally['b']->count($call)) {
                return BoutDecision::refereeRequired($version, [
                    'score_counts_other_than_chala' => $this->ambiguities($version)['score_counts_other_than_chala']
                        ?? "The athletes hold different numbers of {$call} at an equal top appraisal, and only \"more Chala\" is published.",
                ]);
            }
        }

        if ($tally['a']->chala === $tally['b']->chala) {
            return null;
        }

        $side = $tally['a']->chala > $tally['b']->chala ? 'a' : 'b';

        return $this->won($side, 'more_chala', $version, $step, $tally[$side]->lastScoreAt);
    }

    /**
     * Equal appraisals are separated by whichever was awarded last.
     *
     * Read off the bout event sequence, never the clock: several calls can fall
     * inside one displayed second and a timestamp cannot order them.
     *
     * Origin is not consulted. Two athletes each holding one Chala are
     * separated by which Chala came second, whether it was thrown for or
     * conceded through the opponent's Tanbeh.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     * @param  array<string, mixed>  $step
     */
    private function lastAppraisal(array $tally, int $version, array $step): ?BoutDecision
    {
        if ($tally['a']->lastScoreAt === $tally['b']->lastScoreAt) {
            return null;
        }

        $side = $tally['a']->lastScoreAt > $tally['b']->lastScoreAt ? 'a' : 'b';

        return $this->won($side, 'last_appraisal', $version, $step, $tally[$side]->lastScoreAt);
    }

    /**
     * A technique-earned appraisal outranks an automatic one of equal value.
     *
     * The federation's ruling, and it settles a question the published page
     * does not address. An appraisal the opponent's penalty handed over is
     * worth less than the same appraisal thrown for — so a later automatic
     * score can never defeat an earlier technique-earned one of equal value.
     *
     * Placed BELOW count and ABOVE recency. An earlier version of this software
     * ranked origin second, above count, which let it decide contests before
     * the count rule was reached; and the version before this one removed it
     * altogether, which let a later automatic score beat an earlier thrown one.
     * Both were wrong in opposite directions.
     *
     * Compared at the shared top appraisal only. Two athletes on one CHALA each
     * are separated by which was thrown for; how they came by a YONBOSH neither
     * of them holds is not a question.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     * @param  array<string, mixed>  $step
     */
    private function techniqueOrigin(array $tally, int $version, array $step): ?BoutDecision
    {
        $top = $tally['a']->topScore();

        // Equal top priority is the precondition for reaching this step, so
        // either side's top score names the same call. Nobody scoring at all
        // leaves nothing to compare.
        if ($top === null) {
            return null;
        }

        if ($tally['a']->earned($top) === $tally['b']->earned($top)) {
            return null;
        }

        $side = $tally['a']->earned($top) > $tally['b']->earned($top) ? 'a' : 'b';

        return $this->won($side, 'technique_origin', $version, $step, $tally[$side]->lastScoreAt);
    }

    /**
     * The athlete who received the most recent active warning loses.
     *
     * The LAST warning, not the first. The two readings agree whenever each
     * athlete holds exactly one warning and part company from the second
     * onward — which is why the first-caution reading this replaces survived a
     * whole test suite without being caught.
     *
     * Only live penalties count: a warning taken back was never received, and
     * ScoreTally folds none of them.
     *
     * Zero means no warning at all, and zero is the lowest sequence there is,
     * so an unwarned athlete beats a warned one without a special case.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     * @param  array<string, mixed>  $step
     */
    private function latestWarning(array $tally, int $version, array $step): ?BoutDecision
    {
        if ($tally['a']->lastPenaltyAt === $tally['b']->lastPenaltyAt) {
            return null;
        }

        // Reversed: the HIGHER sequence — the more recent warning — loses.
        $side = $tally['a']->lastPenaltyAt < $tally['b']->lastPenaltyAt ? 'a' : 'b';

        return $this->won($side, 'latest_warning', $version, $step, $tally[KurashScore::opposite($side)]->lastPenaltyAt);
    }

    /*
     |--------------------------------------------------------------------------
     | Terminal outcomes
     |--------------------------------------------------------------------------
     */

    /**
     * A contest already over on the board, before any tie-break.
     *
     * Penalties first: an athlete carrying girrom or the configured madichal
     * count loses even while ahead on scores, and the record must say so.
     *
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     */
    public function terminalDecision(?Bout $bout, array $tally): ?BoutDecision
    {
        return $this->terminalOutcome($tally, $this->versionFor($bout));
    }

    /**
     * @param  array{a: ScoreTally, b: ScoreTally}  $tally
     */
    private function terminalOutcome(array $tally, int $version): ?BoutDecision
    {
        foreach (['a', 'b'] as $side) {
            if ($tally[$side]->isDefeated()) {
                $winner = KurashScore::opposite($side);

                return new BoutDecision(
                    side: $winner,
                    basis: $tally[$side]->defeatType() ?? KurashScore::GIRROM,
                    policyVersion: $version,
                    clause: 'General Kurash Rules — terminal penalty',
                    sequence: $tally[$side]->lastPenaltyAt,
                );
            }
        }

        foreach (['a', 'b'] as $side) {
            if ($tally[$side]->isDecisive()) {
                return new BoutDecision(
                    side: $side,
                    // Two yonbosh make a khalol, but the record says how it was
                    // actually reached.
                    basis: $tally[$side]->khalol > 0 ? KurashScore::KHALOL : KurashScore::YONBOSH,
                    policyVersion: $version,
                    clause: 'General Kurash Rules — decisive appraisal',
                    sequence: $tally[$side]->lastScoreAt,
                );
            }
        }

        return null;
    }

    /*
     |--------------------------------------------------------------------------
     | Configuration
     |--------------------------------------------------------------------------
     */

    /** @param array<string, mixed> $step */
    private function won(string $side, string $basis, int $version, array $step, int $sequence): BoutDecision
    {
        return new BoutDecision(
            side: $side,
            basis: $basis,
            policyVersion: $version,
            clause: is_string($step['clause'] ?? null) ? $step['clause'] : null,
            sequence: $sequence > 0 ? $sequence : null,
            inferred: ! (bool) ($step['sourced'] ?? false),
        );
    }

    /** @return list<array<string, mixed>> */
    public function order(int $version): array
    {
        /** @var list<array<string, mixed>> $order */
        $order = (array) config("kurash.bout_decision.versions.{$version}.order", []);

        return $order;
    }

    /** @return array<string, string> */
    public function ambiguities(int $version): array
    {
        /** @var array<string, string> $ambiguities */
        $ambiguities = (array) config("kurash.bout_decision.versions.{$version}.ambiguities", []);

        return $ambiguities;
    }

    private function editionExists(int $version): bool
    {
        return config("kurash.bout_decision.versions.{$version}") !== null;
    }
}
