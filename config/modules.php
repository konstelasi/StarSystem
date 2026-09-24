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

    /*
    |--------------------------------------------------------------------------
    | Safe mode
    |--------------------------------------------------------------------------
    |
    | A module whose provider throws is switched off on its own. If a module
    | breaks the site in a way that can't be caught, turn on safe mode and no
    | module loads: set STARSYSTEM_SAFE_MODE=true, or, without a shell,
    | upload an empty file named `safe-mode` into the storage folder. Then
    | switch the module off (see App\Modules\SafeMode for the ways) and
    | delete the file again.
    |
    */

    'safe_mode' => (bool) env('STARSYSTEM_SAFE_MODE', false),

    'safe_mode_file' => storage_path('safe-mode'),

];
