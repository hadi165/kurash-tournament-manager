<?php

return [
    /*
     | How long a contest runs, in seconds, by the weight class's gender.
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
     | How many tanbeh an athlete may collect before the accumulated warnings
     | become dakki and the contest is awarded against them.
     |
     | Configurable because federations have moved this number between rule
     | editions, and a championship should not need a code change to run under
     | the edition it was sanctioned for.
     */
    'tanbeh_for_dakki' => (int) env('KURASH_TANBEH_FOR_DAKKI', 3),

    /*
     | How many yonbosh add up to a halal and end the contest on the spot.
     */
    'yonbosh_for_halal' => (int) env('KURASH_YONBOSH_FOR_HALAL', 2),
];
