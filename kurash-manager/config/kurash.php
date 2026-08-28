<?php

return [
    /*
     | How long a contest runs, in seconds, by the weight class's gender.
     |
     | The fallback only. Contest length is set per age category on the
     | championship's own screen — cadets, juniors and seniors do not fight for
     | the same time, and that distinction cannot be expressed by gender. These
     | are what a category that has not been given a length of its own uses.
     |
     | The clock itself belongs to the mat, not to this application — the
     | operator runs it and the software records what it was told. These are
     | what the mat screen counts down from, and what a recorded call is
     | timestamped against.
     */
    'bout_seconds' => [
        'M' => (int) env('KURASH_BOUT_SECONDS_M', 240),
        'F' => (int) env('KURASH_BOUT_SECONDS_F', 180),
        'X' => (int) env('KURASH_BOUT_SECONDS_OPEN', 240),
    ],

    /*
     | How many yonbosh add up to a khalol and end the contest on the spot.
     |
     | It does not matter how a yonbosh was reached: one thrown for and one
     | conceded through the opponent's dakki both count, because both are a
     | yonbosh on the board.
     */
    'yonbosh_for_khalol' => (int) env('KURASH_YONBOSH_FOR_KHALOL', 2),

    /*
     | How many madichal an athlete may collect before the contest is awarded
     | against them.
     |
     | Madichal transfers nothing to the opponent on the way — one and two are
     | recorded and change no score. The third ends the contest.
     */
    'madichal_for_defeat' => (int) env('KURASH_MADICHAL_FOR_DEFEAT', 3),

    /*
     | Whether accumulated tanbeh escalate into a dakki, and at what count.
     |
     | Zero — the default — means they do not: tanbeh gives the opponent a chala
     | each time and stops there, which is the rule set this system is written
     | against. A federation running an edition where the Nth tanbeh becomes a
     | dakki sets the number here and gets it without a code change. The key
     | keeps its old name because it is the same question the old rule asked.
     */
    'tanbeh_for_dakki' => (int) env('KURASH_TANBEH_FOR_DAKKI', 0),

    /*
     | What each call is worth against the others.
     |
     | One table, read by the rules engine and by nothing else — the whole point
     | of centralising it is that no screen gets to hold an opinion about which
     | score outranks which. A federation moving a value between rule editions
     | changes it here and the winner calculation, the boards and the exports
     | all move together.
     |
     | Scores rank against scores: a contest is decided first on the highest
     | one either athlete holds, so a yonbosh beats any number of chala however
     | recently they were awarded. Penalties rank against penalties, which is
     | what "the more serious warning" means when two are compared.
     |
     | The numbers are spaced rather than consecutive so a call can be inserted
     | between two existing ones without renumbering the table.
     */
    'score_priority' => [
        // Awarded to an athlete.
        'khalol' => 100,
        'yonbosh' => 40,
        'chala' => 20,

        // Awarded against one.
        'girrom' => 90,
        'dakki' => 30,
        'madichal' => 15,
        'tanbeh' => 10,
    ],

    /*
     | Jazzo: the fraction of the contest at which a bout with nothing scored by
     | either athlete is stopped.
     |
     | A half, and expressed as a fraction rather than as a number of seconds
     | because the contest length is no longer one number — a three minute
     | cadet bout and a four minute senior bout both have a half.
     */
    'jazzo_at_fraction' => (float) env('KURASH_JAZZO_AT_FRACTION', 0.5),

    /*
     |--------------------------------------------------------------------------
     | The round robin
     |--------------------------------------------------------------------------
     |
     | The IKA rule runs a field of two to five as a round robin rather than a
     | bracket; see App\Services\TournamentFormatPolicy. Everything the
     | standings are computed from lives here, in one place, because a table
     | that decides who took gold must be answerable for the arithmetic behind
     | it — and because a federation running a different edition of the rules
     | should be able to say so in configuration rather than in code.
     */
    'round_robin' => [
        /*
         | What a result is worth.
         |
         | Flat: a win is a win. Every victory scores the same whether it came
         | by khalol in nine seconds or on the last call of the contest, and the
         | separation of athletes level on wins is done by the tie-break chain
         | below rather than by weighting the wins themselves.
         |
         | Deliberately NOT derived from ScoreTally::points(). That method
         | encodes yonbosh in the whole part and chala in the tenths for the
         | scoreboard column, and says so in its own docblock: ten chala would
         | read there as one yonbosh, and chala must never add up to a yonbosh.
         | It describes one contest to a screen. It is not a currency, and
         | summing it across a group would rank athletes on an encoding.
         |
         | A federation whose rules weight the manner of victory — ten for a
         | khalol, seven for a yonbosh, one for a decision, as judo does —
         | changes these numbers and the standings, the exports and the medals
         | all move together. Nothing else reads them.
         */
        'points' => [
            'win' => (int) env('KURASH_RR_POINTS_WIN', 1),
            'loss' => (int) env('KURASH_RR_POINTS_LOSS', 0),
        ],

        /*
         | How a tie on wins and points is broken.
         |
         | In order, each step reached only when the one above it left the
         | athletes level:
         |
         |   1. wins            the count of contests won
         |   2. points          the table above, summed
         |   3. head_to_head    for exactly two athletes, the contest between
         |                      them — the only tie-break the rules state
         |                      unambiguously
         |   4. mini_table      for three or more, the same standings computed
         |                      again over only the contests the tied athletes
         |                      fought against each other
         |   5. match_time      see below — off by default
         |   6. referee         an explicit technical decision. Not a tie-break
         |                      at all: it is the admission that the table
         |                      cannot separate them, and it is reported as such
         |                      rather than resolved by inventing an order
         |
         | Steps may be removed but not reordered by editing this list; the
         | standings walk it in the order it is written.
         */
        'tie_breaks' => ['wins', 'points', 'head_to_head', 'mini_table', 'match_time', 'referee'],

        /*
         | The match-time tie-break, which is off.
         |
         | The IKA wording on deciding a tie by match time does not say which
         | reading it means, and the three candidates rank athletes differently:
         |
         |   fastest_win     the single quickest victory
         |   total_time      the sum of the time taken across all wins, least
         |                   first — which penalises an athlete for having won
         |                   more contests
         |   average_time    the mean time per win
         |
         | Choosing one silently would decide medals on an assumption. It is
         | therefore 'disabled' until a federation states which it runs, and a
         | tie that reaches this step falls through to the referee decision.
         |
         | There is a second reason to leave it off. Nothing fought before this
         | setting existed has a recorded winning time at all — see the
         | decided_seconds_remaining migration — so enabling it mid-competition
         | would rank athletes on a column that is null for half the group. The
         | standings refuse to use it where any tied athlete's timing is
         | missing, whatever this is set to.
         */
        'match_time' => env('KURASH_RR_MATCH_TIME', 'disabled'),

        /*
         | Medals, when the group is complete.
         |
         | Rank one takes gold, rank two silver, rank three the single bronze —
         | a round robin has no second semi-final to lose, so it awards one
         | bronze and not two. A group of two ranks two athletes and awards no
         | bronze at all.
         */
        'medals' => [
            1 => 'gold',
            2 => 'silver',
            3 => 'bronze',
        ],

        /*
         | The least the running order should leave between an athlete's
         | contests, counted in bouts.
         |
         | A round robin gives an athlete a contest in almost every round, so
         | the rest that a bracket gets for free from its own shape has to be
         | asked for here. Where a field is small enough that the arithmetic
         | cannot deliver it — three athletes cannot all be rested two bouts
         | apart — the scheduler reports the shortfall rather than pretending.
         |
         | Three by default, matching the rest the scheduler has always kept
         | between a knockout bout and its feeders.
         */
        'minimum_rest' => (int) env('KURASH_RR_MINIMUM_REST', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Age eligibility
    |--------------------------------------------------------------------------
    |
    | Which age group an athlete may be entered in. See
    | App\Services\AgeEligibilityPolicy for how these are read.
    |
    | ── How the IKA states the rule ────────────────────────────────────────
    |
    | Section 23 of the IKA competition rules
    | (https://kurash-ika.org/2022/08/20/kurash-rules/) prints, for each
    | division, an age span AND the birth years that produced it — for example
    | "Cadets (14-15 years, born in 2012-2011 years)". Those birth years are
    | the ones that make the table true in *one* competition year; the table as
    | published is the 2026 edition.
    |
    | So what is stored here is the age span, and the birth years are derived
    | from it for whichever year a championship is held in:
    |
    |     competition age = competition year - birth year
    |
    | That reproduces every printed birth range exactly (2026 - 2012 = 14,
    | 2026 - 2011 = 15) while surviving into 2027 without an edit. It also
    | means eligibility never depends on a date within the year: an athlete
    | born on 1 January and one born on 31 December of the same year are the
    | same age all season, which is what a birth-year rule means and is why a
    | 29 February birthday needs no special handling anywhere in this code.
    |
    | ── Versions ───────────────────────────────────────────────────────────
    |
    | Keyed by the year the version came into force, and a version stays in
    | force until a later one supersedes it: a championship in 2028 is judged
    | by the newest version dated 2028 or earlier. A championship held before
    | the earliest version here is not judged at all — see the policy class,
    | which reports that rather than guessing, so importing a 2019 event does
    | not retrospectively invalidate its entries.
    |
    | A championship may pin a version explicitly in its own row
    | (championships.age_policy_version) when an event is run under rules
    | other than the ones current for its year.
    */
    /*
     | How a contest that reaches time is decided.
     |
     | Versioned the way age_eligibility is, and for the same reason: a
     | championship fought under one edition of the rules must keep being read
     | under that edition. A bout completed under an earlier edition does not
     | become a different result because a later one reordered a tie-break.
     |
     | ── Authority ─────────────────────────────────────────────────────────
     |
     | The order below is the federation's, supplied directly and treated as
     | authoritative for this project. It settles four questions the published
     | page at https://kurash-ika.org/2022/08/20/kurash-rules/ leaves open, and
     | on two of them it contradicts the reading this software briefly shipped:
     |
     |   * score ORIGIN does rank — a technique-earned appraisal beats an
     |     automatic one of equal value and count. It ranks BELOW count, not
     |     above it, which is where an earlier version of this file wrongly put
     |     it.
     |
     |   * the warning rule is LATEST WARNING LOSES, not "cautioned first
     |     wins". The two agree whenever each athlete holds one warning and
     |     disagree from the second onward.
     |
     | ── Supersession ──────────────────────────────────────────────────────
     |
     | A DAKKI replaces an active TANBEH against the same athlete outright: the
     | TANBEH leaves the live tally along with the automatic CHALA it gave the
     | opponent. Both rows stay in the log and are annulled by appended void
     | events — history is never rewritten. Only consequences linked to the
     | superseded TANBEH through parent_event_id are withdrawn, so a CHALA the
     | opponent threw for is untouched.
     */
    'bout_decision' => [

        /*
         | The edition a championship falls under when it pins none. Unlike
         | age_eligibility this never falls back to null: a contest on a mat
         | must be decidable, so the earliest published edition stands in.
         */
        'fallback_version' => (int) env('KURASH_DECISION_POLICY_FALLBACK', 2022),

        'versions' => [

            /*
             | The federation's order, as supplied for this project.
             |
             | `order` is walked top to bottom, each step reached only when the
             | one above it was level. Steps are listed rather than coded so a
             | later edition can reorder them without a deploy, and so a verdict
             | can cite the step that actually decided the contest.
             */
            2022 => [
                'label' => 'IKA competition rules, 2022-08-20, as interpreted by the federation for this project',
                'source' => 'https://kurash-ika.org/2022/08/20/kurash-rules/',

                'order' => [
                    [
                        'step' => 'higher_appraisal',
                        'clause' => 'Appraisal hierarchy — KHALOL > YONBOSH > CHALA',
                        'sourced' => true,
                        'describes' => 'The more valuable appraisal wins, and is evaluated before origin and before recency. A later CHALA can never defeat a YONBOSH. An athlete holding any appraisal also beats one holding only warnings.',
                    ],
                    [
                        'step' => 'more_chala',
                        'clause' => 'Greater applicable score count — "more CHALA wins"',
                        'sourced' => true,
                        'describes' => 'At an equal top appraisal, the greater count wins.',
                    ],
                    [
                        'step' => 'technique_origin',
                        'clause' => 'Technique-earned appraisal outranks an automatic one of equal value and count',
                        'sourced' => true,
                        'describes' => 'TECHNIQUE outranks AUTO_FROM_T and AUTO_FROM_D. Applies to CHALA and YONBOSH alike. A later automatic score never defeats an earlier technique-earned score of the same value.',
                    ],
                    [
                        'step' => 'last_appraisal',
                        'clause' => 'Latest live appraisal wins when value, count and origin priority are equal',
                        'sourced' => true,
                        'describes' => 'Read from the bout event sequence, never the clock: several calls can fall inside one displayed second. Voided appraisals do not take part.',
                    ],
                    [
                        'step' => 'latest_warning',
                        'clause' => 'The athlete receiving the most recent active warning loses',
                        'sourced' => true,
                        'describes' => 'Uses the LAST warning, not the first. Only live, non-voided penalties count. An athlete with no warning at all beats one carrying any.',
                    ],
                ],

                /*
                 | What remains genuinely open. The policy returns
                 | `referee_decision` for these rather than inventing an order,
                 | and names the entry in its verdict so the official is told
                 | what the rules did not cover.
                 */
                'ambiguities' => [
                    'score_counts_other_than_chala' => 'Only "more CHALA" is stated explicitly. A difference in YONBOSH counts at an equal top appraisal is unreachable while two YONBOSH make a KHALOL, and is left to a referee where an edition makes it reachable.',
                    'equal_origin_mix' => 'Two athletes each holding one technique-earned and one automatic appraisal of the same value are equal on origin priority, and fall through to the last-appraisal rule.',
                ],
            ],
        ],
    ],

    'age_eligibility' => [

        /*
         | The version a championship falls under when its year predates every
         | version below, or null to leave such an event unjudged. Null is the
         | default on purpose: a historical import is a record of what happened,
         | not an entry list to be re-approved.
         */
        'fallback_version' => env('KURASH_AGE_POLICY_FALLBACK'),

        'versions' => [

            /*
             | IKA competition rules, Section 23, as published for 2026.
             |
             | Bands are [min age, max age]; a null max is an open top end.
             | Keyed by the athlete's own gender — never the division's, since
             | a division may be open ('X') while every athlete in it is still
             | a man or a woman.
             |
             | The age-group keys are the vocabulary in
             | Championship::AGE_GROUPS. A championship carrying a group that
             | is not listed here — organizers may type their own — is left
             | unjudged for that group rather than refused, because this file
             | is a statement of the rules the IKA publishes and not a list of
             | the only competitions anybody may run.
             |
             | ── What is quoted, and what is inferred ──────────────────────
             |
             | Quoted from Section 23 (male):   Cadets 14-15, Juniors 16-17,
             | Seniors 17-35, Veterans 36-45/46-55/56-60/61-65 and above.
             | Quoted from Section 23 (female): Cadets 14-15, Juniors 16-17,
             | Seniors "above 17 years, born in 2009 and above".
             |
             | Inferred, and worth a federation's confirmation:
             |
             |  - Veterans are printed as five brackets for men. This system
             |    offers one "Veteran" group, so the brackets are collapsed to
             |    their floor, 36 and above. An event that runs the brackets
             |    separately should name them as its own age groups and add
             |    them here.
             |  - No veteran division is printed for women. One is defined
             |    here on the same floor as the men's, because the software
             |    offers the group to every competition and refusing every
             |    women's veteran entry would be a stronger claim than the
             |    rules make.
             |  - The women's senior line says "above 17 years" while its own
             |    birth years admit 17. The birth years are followed, since
             |    they are what Section 23 uses everywhere else.
             |  - The children's divisions (4-7, 8-11, 12-13) are three
             |    separate bands and this system's age-group vocabulary has no
             |    name for any of them. They are deliberately absent rather
             |    than guessed at.
             */
            2026 => [
                'source' => 'IKA competition rules, Section 23 (2026 edition)',

                /*
                 | The groups that count as an adults' competition for the
                 | purposes of Section 25(2) — the clause a 16- or 17-year-old
                 | needs the Chief Referee's sanction to cross into.
                 |
                 | The seniors, and only the seniors. The veterans are an
                 | adults' competition in the ordinary sense of the words, but
                 | they carry a floor of their own — 36 — and Section 25(2) is
                 | about youths joining the adults rather than a power to waive
                 | any age limit at all. Listing them here would let the Chief
                 | Referee sign a sixteen-year-old into a division for the
                 | over-thirty-fives.
                 */
                'adult_groups' => ['Senior'],

                /*
                 | Section 25(2): "With the sanction of the Chief Referee,
                 | youths (16-17 years) may also participate in adults'
                 | competitions."
                 |
                 | Both ends inclusive, in competition age. Nobody outside this
                 | window can be sanctioned into an adults' competition — the
                 | clause is an exception for youths and not a general power to
                 | waive Section 23.
                 */
                'sanction_window' => ['min' => 16, 'max' => 17],

                'bands' => [
                    'M' => [
                        'Cadet' => ['min' => 14, 'max' => 15],
                        'Junior' => ['min' => 16, 'max' => 17],
                        'Senior' => ['min' => 17, 'max' => 35],
                        'Veteran' => ['min' => 36, 'max' => null],
                    ],
                    'F' => [
                        'Cadet' => ['min' => 14, 'max' => 15],
                        'Junior' => ['min' => 16, 'max' => 17],
                        'Senior' => ['min' => 17, 'max' => null],
                        'Veteran' => ['min' => 36, 'max' => null],
                    ],
                ],
            ],
        ],
    ],
];
