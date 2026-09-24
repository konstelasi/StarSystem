<?php

use App\StarDust\TickRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| On the shared profile, one cron line (`php artisan schedule:run` every
| minute) or the secret tick URL drives all background work. The server
| profile runs StarDust's daemons and a real queue worker instead.
|
| Both jobs run inside the schedule:run process. Schedule::command() and
| runInBackground() would start child processes through proc_open, which
| many shared hosts disable. The tick's budget plus the queue's max time
| stay inside one minute.
*/

if (config('stardust.profile') === 'shared') {
    Schedule::call(fn (TickRunner $runner) => $runner->run('cron'))
        ->name('stardust:tick')
        ->everyMinute()
        ->withoutOverlapping(5);

    Schedule::call(fn () => Artisan::call('queue:work', [
        '--stop-when-empty' => true,
        '--max-time' => (int) config('stardust.tick.queue_seconds'),
    ]))
        ->name('queue:work')
        ->everyMinute()
        ->withoutOverlapping(5);
}
