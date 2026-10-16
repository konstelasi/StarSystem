<?php

namespace App\Activity;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ActivityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::subscribe(RecordAuthEvents::class);
    }
}
