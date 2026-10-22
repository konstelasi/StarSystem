<?php

namespace Tests\Feature\Frontend;

use App\Frontend\ThemeManager;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public pages of a site that has published nothing yet, which is
 * enough to check the routing, the headers and which theme is used.
 * FrontendPagesTest covers pages that show entries.
 */
class FrontendControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $themes;

    public function test_the_home_page_shows_the_empty_state_when_nothing_is_published()
    {
        $site = Site::query()->findOrFail(config()->integer('starsystem.default_site'));

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>'.e($site->name).'</title>', false)
            ->assertSee('<h1>'.e($site->name).'</h1>', false)
            ->assertSee('Nothing has been published here yet.');
    }

    public function test_an_unknown_address_gives_the_theme_404_page_with_status_404()
    {
        $this->get('/nothing-here')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Go to the home page');

        $this->get('/blog/nothing-here')->assertNotFound()->assertSee('Page not found');
        $this->get('/a/b/c/d')->assertNotFound()->assertSee('Page not found');
    }

    public function test_an_address_starsystem_keeps_gives_the_plain_404_and_not_the_theme_page()
    {
        foreach (['/admin/nothing-here', '/api/anything', '/files/nothing', '/build/app.js', '/vendor/x', '/_modules/x/y.js'] as $path) {
            $this->get($path)->assertNotFound()->assertDontSee('Go to the home page');
        }

        // The framework's own storage route answers this one, not the front end.
        $this->get('/storage/x.png')->assertDontSee('Go to the home page');

        $this->getJson('/api/anything')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_routes_that_exist_still_win_over_the_catch_all()
    {
        $this->get('/login')->assertOk()->assertDontSee('Page not found');
        $this->get('/up')->assertOk();
        $this->get('/_themes/default/theme.css')->assertOk();
    }

    public function test_public_pages_set_no_cookie_and_say_how_they_may_be_kept()
    {
        foreach (['/', '/nothing-here'] as $path) {
            $response = $this->get($path);

            $this->assertSame([], $response->headers->getCookies(), "{$path} set a cookie");
            $this->assertFalse($response->headers->has('Set-Cookie'));
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('must-revalidate', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_head_requests_work()
    {
        $this->call('HEAD', '/')->assertOk();
        $this->call('HEAD', '/nothing-here')->assertNotFound();
    }

    public function test_only_get_and_head_reach_the_public_pages()
    {
        $this->post('/nothing-here')->assertStatus(405);
        $this->put('/')->assertStatus(405);
    }

    public function test_a_site_with_a_missing_theme_falls_back_to_the_default()
    {
        Site::query()->findOrFail(config()->integer('starsystem.default_site'))->update(['theme' => 'deleted-long-ago']);

        $this->get('/')->assertOk()->assertSee('theme.css?v=1.0.0', false);
    }

    public function test_each_site_shows_the_theme_it_chose()
    {
        config(['starsystem.multisite' => true]);
        $this->useThemes(['default' => null, 'mini' => '<p>MINI HOME {{ $site[\'name\'] }}</p>']);

        $plain = Site::create(['name' => 'Plain site', 'domains' => ['plain.test']]);
        Site::create(['name' => 'Mini site', 'domains' => ['mini.test'], 'theme' => 'mini']);

        $this->get('http://plain.test/')->assertOk()->assertSee('Nothing has been published here yet.')->assertDontSee('MINI HOME');
        $this->get('http://mini.test/')->assertOk()->assertSee('MINI HOME Mini site')->assertDontSee('Nothing has been published here yet.');
        $this->get('http://plain.test/')->assertOk()->assertSee('Nothing has been published here yet.')->assertDontSee('MINI HOME');

        $plain->update(['theme' => 'mini']);
        $this->get('http://plain.test/')->assertOk()->assertSee('MINI HOME Plain site');
    }

    public function test_a_host_that_is_no_site_gets_the_plain_404()
    {
        config(['starsystem.multisite' => true]);

        $this->get('http://nobody.test/')->assertNotFound()->assertDontSee('Go to the home page');
        $this->get('http://nobody.test/anything')->assertNotFound()->assertDontSee('Go to the home page');
    }

    public function test_a_theme_without_the_template_it_needs_is_a_server_error_and_not_a_blank_page()
    {
        $this->useThemes(['default' => null, 'bare' => null]);
        File::delete($this->themes.'/bare/views/home.blade.php');
        Site::query()->findOrFail(config()->integer('starsystem.default_site'))->update(['theme' => 'bare']);

        $this->get('/')->assertStatus(500);
    }

    public function test_a_site_without_any_usable_theme_is_a_server_error()
    {
        $this->useThemes([]);

        $this->get('/')->assertStatus(500);
    }

    /**
     * Points the front end at a temporary themes folder. The bundled theme
     * is copied in as "default", and any other theme is a copy of it whose
     * home template is replaced by the given Blade source, if there is one.
     *
     * @param  array<string, string|null>  $themes
     */
    private function useThemes(array $themes): void
    {
        $this->themes = sys_get_temp_dir().DIRECTORY_SEPARATOR.'starsystem-front-'.Str::random(10);
        File::ensureDirectoryExists($this->themes);

        foreach ($themes as $name => $home) {
            File::copyDirectory(base_path('themes/default'), $this->themes.'/'.$name);

            if ($home !== null) {
                File::put($this->themes.'/'.$name.'/views/home.blade.php', $home);
            }
        }

        config(['starsystem.themes.path' => $this->themes]);
        $this->app->forgetInstance(ThemeManager::class);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->themes));
    }
}
