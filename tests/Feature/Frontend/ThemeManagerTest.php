<?php

namespace Tests\Feature\Frontend;

use App\Frontend\ThemeManager;
use App\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Themes are read from a temporary folder, never the real themes folder.
 * Nothing here touches the database.
 */
class ThemeManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'starsystem-themes-'.Str::random(10);
        File::ensureDirectoryExists($this->root);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->root));
    }

    public function test_it_lists_the_valid_themes_by_folder_name()
    {
        $this->addTheme('default');
        $this->addTheme('studio', ['name' => 'Studio', 'version' => '2.1.0']);

        $themes = $this->manager()->installed();

        $this->assertSame(['default', 'studio'], array_keys($themes));
        $this->assertSame('Studio', $themes['studio']->manifest->name);
        $this->assertSame($this->root.DIRECTORY_SEPARATOR.'studio', $themes['studio']->path);
        $this->assertSame([], $this->manager()->problems());
    }

    public function test_it_leaves_out_a_broken_theme_and_says_why()
    {
        $this->addTheme('default');
        $this->addTheme('bad-version', ['version' => 'new']);
        File::ensureDirectoryExists($this->root.'/no-manifest');
        File::ensureDirectoryExists($this->root.'/bad-json');
        File::put($this->root.'/bad-json/theme.json', '{');

        $manager = $this->manager();

        $this->assertSame(['default'], array_keys($manager->installed()));
        $this->assertNull($manager->find('bad-version'));
        $this->assertSame(['bad-json', 'bad-version', 'no-manifest'], array_keys($manager->problems()));
        $this->assertStringContainsString('must be a version', $manager->problems()['bad-version']);
        $this->assertStringContainsString('no theme.json', $manager->problems()['no-manifest']);
        $this->assertStringContainsString("isn't valid JSON", $manager->problems()['bad-json']);
    }

    public function test_a_folder_with_an_unusable_name_is_reported_and_not_loaded()
    {
        $this->addTheme('My Theme');
        $this->addTheme('Upper');

        $manager = $this->manager();

        $this->assertSame([], $manager->installed());
        $this->assertSame(['My Theme', 'Upper'], array_keys($manager->problems()));
        $this->assertStringContainsString('lowercase letters', $manager->problems()['Upper']);
    }

    public function test_files_and_dotfiles_are_not_themes_and_cause_no_problem()
    {
        $this->addTheme('default');
        File::put($this->root.'/.gitkeep', '');
        File::put($this->root.'/notes.txt', 'x');
        File::ensureDirectoryExists($this->root.'/.hidden');

        $this->assertSame(['default'], array_keys($this->manager()->installed()));
        $this->assertSame([], $this->manager()->problems());
    }

    public function test_a_theme_that_needs_a_newer_starsystem_is_left_out()
    {
        config(['modules.core_version' => '0.1.0']);
        $this->addTheme('default');
        $this->addTheme('future', ['name' => 'Future', 'requires' => ['starsystem' => '^2.0']]);

        $manager = $this->manager();

        $this->assertNull($manager->find('future'));
        $this->assertSame('Future needs StarSystem ^2.0, and this is 0.1.0.', $manager->problems()['future']);
    }

    public function test_a_missing_themes_folder_has_no_themes()
    {
        $manager = new ThemeManager($this->root.'/nowhere', 'default');

        $this->assertSame([], $manager->installed());
        $this->assertSame([], $manager->problems());
    }

    public function test_the_active_theme_is_the_one_the_site_chose()
    {
        $this->addTheme('default');
        $this->addTheme('studio');

        $this->assertSame('studio', $this->manager()->active(new Site(['theme' => 'studio']))->name);
    }

    public function test_the_active_theme_is_the_default_when_the_site_chose_none()
    {
        $this->addTheme('default');
        $this->addTheme('studio');

        $this->assertSame('default', $this->manager()->active(new Site(['theme' => null]))->name);
        $this->assertSame('default', $this->manager()->active(new Site(['theme' => '']))->name);
    }

    public function test_the_active_theme_is_the_default_when_the_chosen_one_is_missing_or_broken()
    {
        $this->addTheme('default');
        $this->addTheme('broken', ['version' => 'new']);

        $this->assertSame('default', $this->manager()->active(new Site(['theme' => 'deleted']))->name);
        $this->assertSame('default', $this->manager()->active(new Site(['theme' => 'broken']))->name);
    }

    public function test_it_throws_when_the_default_theme_is_missing()
    {
        $this->addTheme('studio');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('default theme folder [default]');

        $this->manager()->active(new Site(['theme' => 'studio-gone']));
    }

    public function test_the_message_for_a_broken_default_theme_says_what_is_wrong_with_it()
    {
        $this->addTheme('default', ['version' => 'new']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be a version');

        $this->manager()->active(new Site(['theme' => null]));
    }

    public function test_the_provider_reads_the_folder_from_the_config()
    {
        $this->addTheme('default');
        config(['starsystem.themes.path' => $this->root, 'starsystem.themes.default' => 'default']);
        $this->app->forgetInstance(ThemeManager::class);

        $this->assertSame(['default'], array_keys($this->app->make(ThemeManager::class)->installed()));
    }

    private function manager(): ThemeManager
    {
        return new ThemeManager($this->root, 'default');
    }

    /**
     * @param  array<string, mixed>  $manifest  Changes to a valid theme.json.
     */
    private function addTheme(string $folder, array $manifest = []): void
    {
        File::ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.'views');
        File::put($this->root.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.'theme.json', json_encode([
            'name' => ucfirst($folder),
            'version' => '1.0.0',
            'requires' => ['starsystem' => '^0.1'],
            ...$manifest,
        ]));
    }
}
