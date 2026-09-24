<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-site
    |--------------------------------------------------------------------------
    |
    | StarSystem launches with one site but is built for many. While this is
    | false, every request resolves to the default site whatever its Host.
    | Once true, only hosts listed in a site's domains are accepted, and any
    | other Host gets a 404, so a forged Host header can't reach a site.
    |
    */

    'multisite' => (bool) env('STARSYSTEM_MULTISITE', false),

    'default_site' => 1,

];
