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
     | The sound a contest ends on.
     |
     | A path under public/, served from the venue's own machine: at match time
     | there may be no route off the hall's network, and a buzzer that has to be
     | fetched is a buzzer that does not sound. Replace the file, or point this
     | at another one, to change what the hall hears.
     |
     | Set SCOREBOARD_FINISH_SOUND to an empty string to run without it.
     */
    'finish_sound' => env('SCOREBOARD_FINISH_SOUND', 'sounds/match-end.wav'),
];
