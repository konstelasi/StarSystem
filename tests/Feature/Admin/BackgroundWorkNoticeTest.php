<?php

namespace Tests\Feature\Admin;

use App\Models\TickRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BackgroundWorkNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_say_when_background_work_has_never_run()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('backgroundWork.minutesSinceLast', null)
            );
    }

    public function test_admin_pages_say_when_the_last_tick_is_old()
    {
        TickRun::create(['trigger' => 'cron', 'started_at' => now()->subMinutes(30), 'finished_at' => now()->subMinutes(30)]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('backgroundWork.minutesSinceLast', 30)
            );
    }

    public function test_nothing_is_shown_while_ticks_are_on_time()
    {
        TickRun::create(['trigger' => 'cron', 'started_at' => now()->subMinute(), 'finished_at' => now()->subMinute()]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('backgroundWork', null));
    }

    public function test_the_server_profile_never_shows_it()
    {
        config(['stardust.profile' => 'server']);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('backgroundWork', null));
    }

    public function test_guests_never_get_it()
    {
        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('backgroundWork', null));
    }
}
