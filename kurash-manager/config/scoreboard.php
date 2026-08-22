<?php

return [
    /*
     | Which driver talks to the mats.
     |   http — real hardware
     |   fake — in-memory, for tests and for rehearsing a venue setup
     |   null — accept and discard, for running the system with no scoreboards
     */
    'driver' => env('SCOREBOARD_DRIVER', 'http'),

    'timeout' => (int) env('SCOREBOARD_TIMEOUT', 5),

    /*
     | Shared secret the scoreboard sends back on the result webhook.
     | There is deliberately no default: the endpoint refuses every request
     | until this is set, rather than falling back to a placeholder that is
     | published in the source.
     */
    'webhook_secret' => env('SCOREBOARD_WEBHOOK_SECRET'),

    'webhook_header' => env('SCOREBOARD_WEBHOOK_HEADER', 'X-Scoreboard-Token'),

    /*
     | The sounds a contest can end on, as paths under public/.
     |
     | Served from the venue's own machine: at match time there may be no route
     | off the hall's network, and a buzzer that has to be fetched is a buzzer
     | that does not sound. Add a file here and it appears on every mat screen
     | to be chosen from.
     |
     | Each mat picks its own, which is not only a preference: two mats running
     | side by side want to be told apart by ear, and a hall where every mat
     | sounds the same is a hall where nobody looks up for the right one.
     */
    'finish_sounds' => [
        'sounds/match-end01.wav' => 'Sound 1',
        'sounds/match-end02.wav' => 'Sound 2',
    ],

    /*
     | What a mat sounds until somebody chooses otherwise. Set
     | SCOREBOARD_FINISH_SOUND to an empty string to run without one, and no
     | mat will sound at all.
     */
    'finish_sound' => env('SCOREBOARD_FINISH_SOUND', 'sounds/match-end01.wav'),
];
