<?php

namespace Tests\Feature\Frontend;

use App\Frontend\Theme;
use App\Frontend\ThemeManifest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'starsystem-theme-'.Str::random(10);
        File::ensureDirectoryExists($this->path.'/views/fields');
        File::put($this->path.'/views/entry.blade.php', 'x');
        File::put($this->path.'/views/fields/text.blade.php', 'x');

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->path));
    }

    public function test_it_knows_where_its_views_and_assets_are()
    {
        $theme = $this->theme();

        $this->assertSame($this->path.DIRECTORY_SEPARATOR.'views', $theme->viewsPath());
        $this->assertSame($this->path.DIRECTORY_SEPARATOR.'assets', $theme->assetsPath());
    }

    public function test_an_asset_url_names_the_theme_and_carries_the_version()
    {
        $this->assertSame(url('_themes/studio/theme.css').'?v=1.2.3', $this->theme()->assetUrl('theme.css'));
        $this->assertSame(url('_themes/studio/img/logo.svg').'?v=1.2.3', $this->theme()->assetUrl('/img/logo.svg'));
    }

    public function test_it_finds_a_template_by_its_blade_name()
    {
        $theme = $this->theme();

        $this->assertTrue($theme->has('entry'));
        $this->assertTrue($theme->has('fields.text'));
        $this->assertFalse($theme->has('list'));
        $this->assertFalse($theme->has('fields.number'));
    }

    public function test_it_gives_the_file_of_a_template_it_has()
    {
        $theme = $this->theme();
        $views = $this->path.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR;

        $this->assertSame($views.'entry.blade.php', $theme->templatePath('entry'));
        $this->assertSame($views.'fields'.DIRECTORY_SEPARATOR.'text.blade.php', $theme->templatePath('fields.text'));
        $this->assertNull($theme->templatePath('list'));
        $this->assertNull($theme->templatePath('../theme'));
    }

    public function test_a_template_name_that_could_leave_the_views_folder_is_never_found()
    {
        File::put($this->path.'/theme.json', '{}');

        $theme = $this->theme();

        $this->assertFalse($theme->has('../theme'));
        $this->assertFalse($theme->has('..'.DIRECTORY_SEPARATOR.'theme'));
        $this->assertFalse($theme->has('fields/text'));
        $this->assertFalse($theme->has(''));
    }

    private function theme(): Theme
    {
        return new Theme('studio', $this->path, ThemeManifest::fromJson(json_encode([
            'name' => 'Studio',
            'version' => '1.2.3',
            'requires' => ['starsystem' => '^0.1'],
        ])));
    }
}
