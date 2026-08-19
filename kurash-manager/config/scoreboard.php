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
];
