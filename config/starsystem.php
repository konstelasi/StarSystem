<?php

use App\Update\FeedSignature;

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

    /*
    |--------------------------------------------------------------------------
    | Entries
    |--------------------------------------------------------------------------
    |
    | Deleting an entry moves it to its model's trash. A daily job deletes
    | entries that have been in the trash longer than this many days for
    | good, the same as the media library does with its files.
    |
    */

    'entries' => [
        'trash_days' => (int) env('STARSYSTEM_ENTRY_TRASH_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity log
    |--------------------------------------------------------------------------
    |
    | A daily job deletes log rows older than this many days. Set it to 0 to
    | keep them for good. A row names the person who did something, so a
    | shorter time keeps less about people who have since left.
    |
    */

    'activity' => [
        'keep_days' => (int) env('STARSYSTEM_ACTIVITY_KEEP_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Updates
    |--------------------------------------------------------------------------
    |
    | Where this install looks for new versions: the release.json published
    | with every release. GitHub's "latest" address always serves the newest
    | one. Set it to an empty value to turn update checks off.
    |
    | A feed is only believed when it is signed with the release key. The
    | public key is built in. A copy of StarSystem that publishes its own
    | releases changes the built-in key, and an install that follows another
    | feed sets STARSYSTEM_UPDATE_KEY. An empty key refuses every feed.
    |
    */

    'updates' => [
        'feed' => env('STARSYSTEM_UPDATE_FEED', 'https://github.com/damarbob/StarSystem/releases/latest/download/release.json'),
        'public_key' => env('STARSYSTEM_UPDATE_KEY', FeedSignature::PUBLIC_KEY),
    ],

];
