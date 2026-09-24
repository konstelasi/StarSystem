<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modules folder
    |--------------------------------------------------------------------------
    |
    | Each module lives in its own folder here, named after the module, with
    | a module.json manifest at its top. Modules are uploaded as zips from
    | the admin, or copied in over FTP, so they never need Composer.
    |
    */

    'path' => env('STARSYSTEM_MODULES_PATH', base_path('modules')),

    /*
    |--------------------------------------------------------------------------
    | StarSystem version
    |--------------------------------------------------------------------------
    |
    | What each module's `requires.starsystem` constraint is checked against.
    |
    */

    'core_version' => '0.1.0',

];
