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
    | disable the module under Modules in the admin (App\Modules\SafeMode
    | lists other ways) and delete the file again.
    |
    */

    'safe_mode' => (bool) env('STARSYSTEM_SAFE_MODE', false),

    'safe_mode_file' => storage_path('safe-mode'),

    /*
    |--------------------------------------------------------------------------
    | Zip uploads
    |--------------------------------------------------------------------------
    |
    | Limits for modules uploaded as zips in the admin. The upload is also
    | capped by PHP's upload_max_filesize and post_max_size.
    |
    */

    'upload_max_kb' => 20 * 1024,

    'max_files' => 5000,

    'max_unpacked_mb' => 100,

];
