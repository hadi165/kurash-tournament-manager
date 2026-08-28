<?php

namespace App\Support;

/**
 * Why a contest was decided the way it was, in a form that can be filed.
 *
 * A winner on its own is not a result. A protest an hour later asks which rule
 * separated the two athletes, under which edition of the rules, and off which
 * call — and a bare `win_type` string cannot answer any of it. Everything the
 * policy used to reach its answer travels with the answer.
 *
 * `side` being null is a real verdict and not a failure: it means the rules the
 * championship is being fought under do not separate these two athletes, and a
 * referee must. Nothing in this object invents a winner to avoid saying so.
 */
final readonly class BoutDecision
{
    public function __construct(
        /** 'a', 'b', or null when the rules do not decide it. */
        public ?string $side,
        /**
         * The step that decided it — `higher_appraisal`, `more_chala`,
         * `last_appraisal`, `first_caution`, a terminal call such as `khalol`,
         * or `referee_decision`. This is what `win_type` is set from.
         */
        public string $basis,
        /** Which edition of the rules was applied. */
        public int $policyVersion,
        /** The clause that step quotes, or the reason no clause covered it. */
        public ?string $clause = null,
        /**
         * The bout event sequence number the deciding fact sits at — the last
         * appraisal, or the first caution. Null where the step is a count
         * rather than a moment.
         */
        public ?int $sequence = null,
        /** Does a human have to settle this? */
        public bool $requiresRefereeDecision = false,
        /**
         * What the rules could not separate, when they could not. Keyed by the
         * config `ambiguities` entry so the official is shown the same wording
         * the federation would be asked about.
         *
         * @var array<string, string>
         */
        public array $unresolved = [],
        /** True when the deciding step is this project's reading, not a quotation. */
        public bool $inferred = false,
        /*
         | Who settled it, when the rules would not.
         |
         | Recorded on the verdict rather than left to the event log because the
         | frozen snapshot has to be readable on its own: a protest asks who
         | gave the contest and on what grounds, and an answer that requires
         | joining two tables is an answer somebody will get wrong.
         */
        public ?int $decidedByUserId = null,
        public ?string $decidedByName = null,
        public ?string $reason = null,
        public ?string $decidedAt = null,
    ) {}

    /**
     * The contest is settled, and the RULES settled it.
     *
     * A referee's ruling has a winner and needs no further decision, so it
     * would otherwise read as automatic — and a result sheet that cannot tell
     * "the rules decided this" from "an official decided this" is exactly the
     * distinction a protest turns on. The presence of a deciding official is
     * what separates them.
     */
    public function isAutomatic(): bool
    {
        return $this->side !== null
            && ! $this->requiresRefereeDecision
            && $this->decidedByUserId === null;
    }

    /**
     * The record filed against the completed bout.
     *
     * Deliberately flat and self-describing: it is read back years later by
     * something that will not have this class, so every key says what it is
     * rather than relying on position.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'policy_version' => $this->policyVersion,
            'basis' => $this->basis,
            'clause' => $this->clause,
            'sequence' => $this->sequence,
            'automatic' => $this->isAutomatic(),
            'requires_referee_decision' => $this->requiresRefereeDecision,
            'inferred' => $this->inferred,
            'unresolved' => $this->unresolved,
            'decided_by_user_id' => $this->decidedByUserId,
            'decided_by' => $this->decidedByName,
            'reason' => $this->reason,
            'decided_at' => $this->decidedAt,
        ];
    }

    /**
     * A human settled it.
     *
     * Carries the facts the rules could not separate, so the record says what
     * the official was actually asked to decide rather than only that they
     * decided something. `basis` stays `referee_decision`: the contest was not
     * won by a clause, and a result sheet must not imply one.
     *
     * @param  array<string, string>  $unresolved
     */
    public static function refereeDecided(
        string $side,
        int $version,
        array $unresolved,
        ?int $userId,
        ?string $userName,
        ?string $reason = null,
        string $basis = 'referee_decision',
    ): self {
        return new self(
            side: $side,
            basis: $basis,
            policyVersion: $version,
            clause: 'Decided by an authorised official; no published clause separated the athletes.',
            requiresRefereeDecision: false,
            unresolved: $unresolved,
            decidedByUserId: $userId,
            decidedByName: $userName,
            reason: $reason,
            decidedAt: now()->toIso8601String(),
        );
    }

    /**
     * No automatic winner, and here is what the rules left open.
     *
     * @param  array<string, string>  $unresolved
     */
    public static function refereeRequired(int $version, array $unresolved, ?string $clause = null): self
    {
        return new self(
            side: null,
            basis: 'referee_decision',
            policyVersion: $version,
            clause: $clause ?? 'No published clause separates these athletes.',
            requiresRefereeDecision: true,
            unresolved: $unresolved,
        );
    }
}
