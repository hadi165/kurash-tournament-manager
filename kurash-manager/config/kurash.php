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
];
