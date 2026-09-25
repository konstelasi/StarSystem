<?php

namespace Tests\Feature\Admin;

use App\Models\TickRun;
use App\Models\User;
use App\StarDust\TickPause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('admin.health'))->assertRedirect(route('login'));
    }

    public function test_it_warns_when_stardust_has_never_ticked()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.health'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Health')
                ->where('profile', 'shared')
                ->where('tick.stale', true)
                ->where('tick.minutesSinceLast', null)
                ->where('paused', null)
                ->where('server.supported', true)
                ->has('paths', 3)
            );
    }

    public function test_it_lists_recent_ticks_and_clears_the_warning()
    {
        $this->artisan('stardust:bootstrap')->assertSuccessful();
        $this->artisan('stardust:tick', ['--budget' => 5, '--trigger' => 'cron'])->assertSuccessful();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.health'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tick.stale', false)
                ->where('stardust.bootstrapped', true)
                ->has('tick.recent', 1, fn (Assert $run) => $run
                    ->where('trigger', 'cron')
                    ->where('stopReason', 'idle')
                    ->where('error', null)
                    ->etc()
                )
            );
    }

    public function test_it_warns_when_the_last_tick_is_old()
    {
        TickRun::create(['trigger' => 'cron', 'started_at' => now()->subMinutes(30), 'finished_at' => now()->subMinutes(30)]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.health'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tick.stale', true)
                ->where('tick.minutesSinceLast', 30)
            );
    }

    public function test_it_says_when_background_work_is_paused_for_an_update()
    {
        app(TickPause::class)->pause('update');

        try {
            $this->artisan('stardust:tick', ['--trigger' => 'cron'])->assertSuccessful();

            $this->actingAs(User::factory()->create())
                ->get(route('admin.health'))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('paused.reason', 'update')
                    ->whereType('paused.since', 'string')
                    ->where('tick.stale', false)
                    ->where('tick.recent.0.stopReason', 'paused')
                );
        } finally {
            app(TickPause::class)->resume();
        }
    }

    public function test_stardust_folders_are_writable_and_outside_the_public_folder()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.health'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('paths.0.writable', true)
                ->where('paths.0.public', false)
                ->where('paths.1.writable', true)
                ->where('paths.1.public', false)
            );
    }
}
