<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment profile
    |--------------------------------------------------------------------------
    |
    | "shared": StarDust's background work runs as a budgeted tick, driven by
    | the scheduler (one cron line) or by the secret tick URL.
    |
    | "server": the four persistent daemons (watcher, reconciler, liberator,
    | chronicler) run under supervisor or systemd, and the tick is refused.
    |
    | StarDust says never to mix the two on one installation.
    |
    */

    'profile' => env('STARDUST_PROFILE', 'shared'),

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | StarDust gets its own PDO, built from this Laravel connection's
    | credentials. It never shares Laravel's PDO: StarDust requires
    | ERRMODE_EXCEPTION and EMULATE_PREPARES=false, and opens its own
    | transactions.
    |
    */

    'connection' => env('STARDUST_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    /*
    |--------------------------------------------------------------------------
    | Advisory lock namespace
    |--------------------------------------------------------------------------
    |
    | Null lets StarDust derive a private namespace from the database name,
    | which is correct on a MySQL server shared by several accounts. Set it
    | only when renaming the database or when two installs must coordinate.
    |
    */

    'lock_namespace' => env('STARDUST_LOCK_NAMESPACE'),

    /*
    |--------------------------------------------------------------------------
    | Working directories
    |--------------------------------------------------------------------------
    |
    | Both default to the system temp directory in StarDust, which shared
    | hosts may share between accounts or clear. Keep them under storage/,
    | which the docroot rules never serve.
    |
    */

    'artifact_dir' => env('STARDUST_ARTIFACT_DIR', storage_path('app/stardust/artifacts')),

    'pid_dir' => env('STARDUST_PID_DIR', storage_path('app/stardust/pids')),

    /*
    |--------------------------------------------------------------------------
    | Tick
    |--------------------------------------------------------------------------
    |
    | The budget plus queue_seconds keeps the tick and the queue worker
    | inside one cron minute; both run in the schedule:run process. StarDust
    | also clamps the budget to PHP's max_execution_time when the SAPI
    | reports one.
    |
    | The secret guards /_system/tick for hosts that only offer a scheduled
    | URL fetch. The URL is disabled while the secret is empty.
    |
    */

    'tick' => [
        'budget' => (int) env('STARDUST_TICK_BUDGET', 40),
        'queue_seconds' => (int) env('STARDUST_QUEUE_SECONDS', 15),
        // URL fetch services often time out at 30 seconds.
        'url_budget' => (int) env('STARDUST_TICK_URL_BUDGET', 25),
        'exports' => (bool) env('STARDUST_TICK_EXPORTS', true),
        'secret' => env('STARDUST_TICK_SECRET'),
        'stale_after_minutes' => (int) env('STARDUST_TICK_STALE_AFTER', 15),
    ],

];
