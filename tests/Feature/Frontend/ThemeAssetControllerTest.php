<?php

namespace Tests\Feature\Frontend;

use App\Frontend\ThemeManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Themes are served from a temporary folder, never the real themes folder.
 * Nothing here touches the database, because the route has no site.
 */
class ThemeAssetControllerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'starsystem-theme-assets-'.Str::random(10);

        $this->addTheme('studio');
        File::put($this->root.'/studio/assets/theme.css', 'body { color: black; }');
        File::ensureDirectoryExists($this->root.'/studio/assets/img');
        File::put($this->root.'/studio/assets/img/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        File::put($this->root.'/studio/assets/page.html', '<script>alert(1)</script>');
        File::put($this->root.'/studio/assets/run.php', '<?php echo "ran";');
        File::put($this->root.'/studio/assets/noextension', 'x');
        File::put($this->root.'/studio/views/entry.blade.php', 'secret template');

        config(['starsystem.themes.path' => $this->root, 'starsystem.themes.default' => 'studio']);
        $this->app->forgetInstance(ThemeManager::class);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->root));
    }

    public function test_it_serves_a_css_file_with_the_right_type_and_safe_headers()
    {
        $response = $this->get('/_themes/studio/theme.css');

        $response->assertOk();
        $this->assertStringStartsWith('text/css', (string) $response->headers->get('Content-Type'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('body { color: black; }', File::get($response->baseResponse->getFile()->getPathname()));
    }

    public function test_it_serves_a_file_in_a_folder_of_the_assets()
    {
        $this->get('/_themes/studio/img/logo.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_it_sends_no_cookie()
    {
        $response = $this->get('/_themes/studio/theme.css');

        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_it_refuses_paths_that_lead_out_of_the_assets_folder()
    {
        $this->get('/_themes/studio/../theme.json')->assertNotFound();
        $this->get('/_themes/studio/..%2Ftheme.json')->assertNotFound();
        $this->get('/_themes/studio/%2e%2e/theme.json')->assertNotFound();
        $this->get('/_themes/studio/..%2Fviews%2Fentry.blade.php')->assertNotFound();
        $this->get('/_themes/studio/img/../../theme.json')->assertNotFound();
        $this->get('/_themes/studio/theme.css%00.png')->assertNotFound();
    }

    public function test_it_refuses_an_extension_that_is_not_on_the_list()
    {
        $this->get('/_themes/studio/page.html')->assertNotFound();
        $this->get('/_themes/studio/run.php')->assertNotFound();
        $this->get('/_themes/studio/noextension')->assertNotFound();
        $this->get('/_themes/studio/theme.css.php')->assertNotFound();
    }

    public function test_it_refuses_a_file_that_is_not_there()
    {
        $this->get('/_themes/studio/missing.css')->assertNotFound();
        $this->get('/_themes/studio/img')->assertNotFound();
        $this->get('/_themes/studio/img.css')->assertNotFound();
    }

    public function test_it_refuses_a_theme_that_is_not_installed_or_is_broken_or_is_named_oddly()
    {
        $this->addTheme('broken', ['version' => 'new']);
        File::put($this->root.'/broken/assets/theme.css', 'x');

        $this->get('/_themes/nowhere/theme.css')->assertNotFound();
        $this->get('/_themes/broken/theme.css')->assertNotFound();
        $this->get('/_themes/Studio/theme.css')->assertNotFound();
        $this->get('/_themes/stu%2Fdio/theme.css')->assertNotFound();
        $this->get('/_themes/../studio/theme.css')->assertNotFound();
        $this->get('/_themes/studio')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $manifest  Changes to a valid theme.json.
     */
    private function addTheme(string $folder, array $manifest = []): void
    {
        File::ensureDirectoryExists($this->root.'/'.$folder.'/views');
        File::ensureDirectoryExists($this->root.'/'.$folder.'/assets');
        File::put($this->root.'/'.$folder.'/theme.json', json_encode([
            'name' => ucfirst($folder),
            'version' => '1.0.0',
            'requires' => ['starsystem' => '^0.1'],
            ...$manifest,
        ]));
    }
}
