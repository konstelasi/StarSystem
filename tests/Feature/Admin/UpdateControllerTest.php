<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\StarDust\TickPause;
use App\Update\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The updates page and its endpoints, against a synthetic install so no
 * request here ever touches this repository's own files.
 */
class UpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/starsystem-update-ctrl-'.bin2hex(random_bytes(6));
        mkdir($this->base.'/root', 0775, true);
        file_put_contents($this->base.'/root/VERSION', "1.0.0\n");

        $this->app->instance(Updater::class, new Updater(
            $this->base.'/root',
            $this->base.'/work',
            app(TickPause::class),
        ));
    }

    protected function tearDown(): void
    {
        app(TickPause::class)->resume();

        if (app()->isDownForMaintenance()) {
            Artisan::call('up');
        }

        $this->removeDir($this->base);

        parent::tearDown();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('admin.updates'))->assertRedirect(route('login'));
        $this->post(route('admin.updates.start'))->assertRedirect(route('login'));
        $this->post(route('admin.updates.check'))->assertRedirect(route('login'));
        $this->post(route('admin.updates.step'))->assertRedirect(route('login'));
        $this->post(route('admin.updates.retry'))->assertRedirect(route('login'));
    }

    public function test_the_page_and_starting_an_update_need_a_freshly_confirmed_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.updates'))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($user)->post(route('admin.updates.start'), ['version' => '1.1.0'])
            ->assertRedirect(route('password.confirm'));
    }

    public function test_checking_stepping_and_retrying_do_not_need_a_freshly_confirmed_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.updates.check'))
            ->assertRedirect(route('admin.updates'));

        $this->actingAs($user)->post(route('admin.updates.step'))
            ->assertOk()
            ->assertJson(['state' => null, 'busy' => false]);

        $this->actingAs($user)->post(route('admin.updates.retry'))
            ->assertOk()
            ->assertJson(['state' => null]);
    }

    public function test_the_page_shows_the_current_version_and_what_the_feed_offers(): void
    {
        Http::fake(['https://feed.example/release.json' => Http::response([
            'version' => '1.1.0',
            'zip' => 'https://feed.example/starsystem-1.1.0.zip',
            'sha256' => str_repeat('a', 64),
            'published_at' => '2026-02-01T00:00:00Z',
        ])]);
        config(['starsystem.updates.feed' => 'https://feed.example/release.json']);

        $this->asConfirmedUser()
            ->get(route('admin.updates'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Updates')
                ->where('current', '1.0.0')
                ->where('available', true)
                ->where('latest.release.version', '1.1.0')
                ->where('latest.error', null)
                ->where('state', null)
            );
    }

    public function test_the_page_reports_why_the_feed_could_not_be_read(): void
    {
        Http::fake(['https://feed.example/release.json' => Http::response(status: 500)]);
        config(['starsystem.updates.feed' => 'https://feed.example/release.json']);

        $this->asConfirmedUser()
            ->get(route('admin.updates'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('available', false)
                ->where('latest.release', null)
                ->has('latest.error')
            );
    }

    public function test_starting_refuses_a_version_the_feed_no_longer_offers(): void
    {
        Http::fake(['https://feed.example/release.json' => Http::response([
            'version' => '1.2.0',
            'zip' => 'https://feed.example/starsystem-1.2.0.zip',
            'sha256' => str_repeat('a', 64),
        ])]);
        config(['starsystem.updates.feed' => 'https://feed.example/release.json']);

        $this->asConfirmedUser()
            ->post(route('admin.updates.start'), ['version' => '1.1.0'])
            ->assertRedirect(route('admin.updates'))
            ->assertSessionHasErrors('update');

        $this->assertNull(app(Updater::class)->state());
    }

    public function test_starting_a_current_release_puts_the_update_in_running_state(): void
    {
        Http::fake(['https://feed.example/release.json' => Http::response([
            'version' => '1.1.0',
            'zip' => 'https://feed.example/starsystem-1.1.0.zip',
            'sha256' => str_repeat('a', 64),
        ])]);
        config(['starsystem.updates.feed' => 'https://feed.example/release.json']);

        $this->asConfirmedUser()
            ->post(route('admin.updates.start'), ['version' => '1.1.0'])
            ->assertRedirect(route('admin.updates'));

        $state = app(Updater::class)->state();
        $this->assertSame('running', $state['status']);
        $this->assertSame('1.1.0', $state['to']['version']);
    }

    public function test_step_advances_a_running_update_and_reports_it_as_json(): void
    {
        Http::fake(['https://feed.example/release.json' => Http::response([
            'version' => '1.1.0',
            'zip' => 'https://feed.example/nowhere.zip',
            'sha256' => str_repeat('a', 64),
        ])]);
        config(['starsystem.updates.feed' => 'https://feed.example/release.json']);

        $this->asConfirmedUser()->post(route('admin.updates.start'), ['version' => '1.1.0']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.updates.step'))
            ->assertOk()
            ->assertJsonPath('state.mode', 'update')
            ->assertJsonPath('busy', false);
    }

    private function asConfirmedUser(): static
    {
        $this->actingAs(User::factory()->create());
        $this->withSession(['auth.password_confirmed_at' => time()]);

        return $this;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = "{$dir}/{$entry}";
            is_dir($path) && ! is_link($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
