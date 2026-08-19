<?php

return [
    /*
     | The federation's name, as it appears on every screen and printout.
     */
    'organisation' => env('BRANDING_ORGANISATION', 'International Kurash Association'),

    'short_name' => env('BRANDING_SHORT_NAME', 'IKA'),

    /*
     | Path to the official logo, relative to public/.
     |
     | Drop the real artwork at public/images/logo.svg (or .png) and it appears
     | in the sidebar, on the login screen, on the venue displays and at the top
     | of every PDF. Until then the components fall back to a plain typographic
     | mark — deliberately generic, so nothing in the system pretends to be
     | official artwork it is not.
     |
     | SVG for the screens. Dompdf renders PNG far more reliably than SVG, so
     | supply a PNG as well if the printouts matter.
     */
    'logo' => env('BRANDING_LOGO', 'images/logo.svg'),

    'logo_print' => env('BRANDING_LOGO_PRINT', 'images/logo.png'),
];
