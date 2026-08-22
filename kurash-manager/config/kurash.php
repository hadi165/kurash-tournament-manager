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
];
