<?php

namespace Tests\Feature\StarDust;

use App\Models\TickRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('stardust:bootstrap')->assertSuccessful();
    }

    public function test_the_tick_command_runs_and_records_a_tick()
    {
        $this->artisan('stardust:tick', ['--budget' => 5, '--trigger' => 'cron'])->assertSuccessful();

        $run = TickRun::sole();
        $this->assertSame('cron', $run->trigger);
        $this->assertNull($run->error);
        $this->assertSame('idle', $run->stop_reason);
        $this->assertNotNull($run->finished_at);
    }

    public function test_the_server_profile_refuses_the_tick()
    {
        config(['stardust.profile' => 'server']);

        $this->artisan('stardust:tick')->assertFailed();

        $this->assertSame(0, TickRun::count());
    }

    public function test_the_shared_profile_refuses_the_daemons()
    {
        $this->artisan('stardust:daemon', ['name' => 'watcher'])->assertFailed();
    }

    public function test_the_tick_url_is_off_without_a_secret()
    {
        config(['stardust.tick.secret' => null]);

        $this->get('/_system/tick?key=')->assertNotFound();
        $this->assertSame(0, TickRun::count());
    }

    public function test_the_tick_url_rejects_a_wrong_key()
    {
        config(['stardust.tick.secret' => 'correct-horse']);

        $this->get('/_system/tick')->assertNotFound();
        $this->get('/_system/tick?key=wrong')->assertNotFound();
        $this->assertSame(0, TickRun::count());
    }

    public function test_the_tick_url_runs_a_tick_with_the_right_key()
    {
        config(['stardust.tick.secret' => 'correct-horse', 'stardust.tick.url_budget' => 5]);

        $this->get('/_system/tick?key=correct-horse')->assertOk()->assertJson(['ok' => true]);
        $this->post('/_system/tick', [], ['X-Tick-Key' => 'correct-horse'])->assertOk();

        $this->assertSame(['url', 'url'], TickRun::pluck('trigger')->all());
    }

    public function test_the_tick_url_refuses_on_the_server_profile()
    {
        config(['stardust.tick.secret' => 'correct-horse', 'stardust.profile' => 'server']);

        $this->get('/_system/tick?key=correct-horse')->assertStatus(409);
    }
}
