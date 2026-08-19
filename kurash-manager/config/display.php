<?php

return [
    /*
     | Whether the venue display screens are readable without signing in.
     |
     | Off by default. Turning it on publishes athlete names, draws and results
     | to anyone with the URL, which is normal for a championship but is a
     | decision to make deliberately rather than inherit.
     */
    'public' => (bool) env('DISPLAY_PUBLIC', false),

    /*
     | Ceiling on how long a rendered screen may be served for.
     |
     | Correctness does not depend on this: every screen is invalidated the
     | moment a bout in that championship changes. It is a backstop for
     | anything that changes without touching a bout row.
     */
    'ttl' => (int) env('DISPLAY_TTL', 300),
];
