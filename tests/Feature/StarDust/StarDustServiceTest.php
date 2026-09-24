<?php

namespace Tests\Feature\StarDust;

use App\Models\User;
use App\StarDust\StarDustService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use StarDust\StarDust;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class StarDustServiceTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    public function test_a_request_that_does_not_use_stardust_opens_no_stardust_connection()
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();

        $this->assertFalse(app()->resolved(StarDust::class));
    }

    public function test_stardust_gets_its_own_pdo_with_the_attributes_it_requires()
    {
        $pdo = app(StarDustService::class)->engine()->pdo();

        $this->assertNotSame(DB::connection()->getPdo(), $pdo);
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        $this->assertFalse((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    public function test_the_test_database_server_is_supported()
    {
        $server = app(StarDustService::class)->server();

        $this->assertTrue($server['supported'], (string) $server['error']);
        $this->assertContains($server['engine'], ['MySQL', 'MariaDB']);
    }

    public function test_bootstrap_is_safe_to_run_twice()
    {
        $this->artisan('stardust:bootstrap')->assertSuccessful();
        $this->artisan('stardust:bootstrap')->assertSuccessful();

        $this->asSite($this->defaultSite());

        $this->assertTrue(app(StarDustService::class)->status()['bootstrapped']);
    }
}
