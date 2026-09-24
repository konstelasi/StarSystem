<?php

namespace Tests\Feature\Modules;

use App\Models\Module;
use App\Modules\ModuleException;
use App\Modules\ModuleManager;
use App\Modules\ZipInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsModuleZips;
use Tests\Concerns\InteractsWithModules;
use Tests\TestCase;

class ZipInstallerTest extends TestCase
{
    use BuildsModuleZips, InteractsWithModules, RefreshDatabase;

    private ZipInstaller $installer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryModulesFolder();
        $this->installer = app(ZipInstaller::class);
        $this->beforeApplicationDestroyed(fn () => $this->deleteZips());
    }

    public function test_it_installs_a_module_into_a_folder_named_after_it()
    {
        // Downloaded from a code host, the zip's folder has another name.
        $result = $this->installer->install($this->moduleZip(folder: 'example-main', extra: [
            'example-main/.htaccess' => 'Options +ExecCGI',
            'example-main/src/.user.ini' => 'auto_prepend_file=evil.php',
            '__MACOSX/example-main/._module.json' => 'junk',
        ]));

        $this->assertSame('Example', $result['manifest']->name);
        $this->assertNull($result['upgradedFrom']);
        $this->assertFileExists($this->modulesPath.'/Example/module.json');
        $this->assertFileExists($this->modulesPath.'/Example/src/ExampleServiceProvider.php');
        $this->assertFileExists($this->modulesPath.'/Example/dist/components.js');

        // Hidden files are left out.
        $this->assertFileDoesNotExist($this->modulesPath.'/Example/.htaccess');
        $this->assertFileDoesNotExist($this->modulesPath.'/Example/src/.user.ini');
        $this->assertDirectoryDoesNotExist($this->modulesPath.'/__MACOSX');

        // New modules start disabled, and nothing is left staged.
        $this->assertSame(0, Module::count());
        $this->assertSame(['Example'], $this->folders());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function hostileEntries(): array
    {
        return [
            'zip-slip' => [['Example/../../evil.php' => '<?php'], 'leads outside the module folder'],
            'zip-slip with backslashes' => [['Example\\..\\..\\evil.php' => '<?php'], 'leads outside the module folder'],
            'absolute path' => [['/tmp/evil.php' => '<?php'], 'absolute path'],
            'drive letter' => [['C:/Windows/evil.php' => '<?php'], 'absolute path'],
            'file beside the module folder' => [['evil.php' => '<?php'], 'must be inside one module folder'],
            'second top-level folder' => [['Other/evil.php' => '<?php'], 'must be inside one module folder'],
        ];
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[DataProvider('hostileEntries')]
    public function test_hostile_paths_are_rejected_before_anything_is_written(array $extra, string $message)
    {
        $this->assertRejected($this->moduleZip(extra: $extra), $message);

        $this->assertFileDoesNotExist(dirname($this->modulesPath).'/evil.php');
    }

    public function test_symlinks_are_rejected()
    {
        $this->assertRejected($this->zipWithSymlink(), 'symbolic link');
    }

    public function test_a_zip_without_a_manifest_is_rejected()
    {
        $this->assertRejected($this->moduleZip(withManifest: false), 'no Example/module.json');
    }

    public function test_an_invalid_manifest_is_rejected()
    {
        $this->assertRejected($this->moduleZip(manifest: ['slug' => 'Not A Slug']), "module.json can't be used");
    }

    public function test_a_module_for_another_starsystem_version_is_rejected()
    {
        $this->assertRejected($this->moduleZip(manifest: ['requires' => ['starsystem' => '^9.0']]), 'needs StarSystem ^9.0');
    }

    public function test_a_file_that_is_not_a_zip_is_rejected()
    {
        $path = sys_get_temp_dir().'/starsystem-not-a-zip.zip';
        File::put($path, 'plain text');

        try {
            $this->assertRejected($path, "isn't a zip file");
        } finally {
            File::delete($path);
        }
    }

    public function test_an_installed_module_is_not_overwritten_by_the_same_or_an_older_version()
    {
        $this->installer->install($this->moduleZip());

        $this->assertRejected($this->moduleZip(), 'Example 1.0.0 is already installed', keep: 1);
        $this->assertRejected($this->moduleZip(manifest: ['version' => '0.9.0']), 'already installed', keep: 1);
    }

    public function test_a_folder_holding_another_module_is_not_overwritten()
    {
        $this->installer->install($this->moduleZip());

        $this->assertRejected($this->moduleZip(manifest: ['slug' => 'impostor', 'version' => '2.0.0']), 'already holds a different module', keep: 1);
        $this->assertSame('example', app(ModuleManager::class)->read('Example')->slug);
    }

    public function test_a_newer_version_upgrades_an_enabled_module()
    {
        $this->installer->install($this->moduleZip());
        app(ModuleManager::class)->enable('example');

        $result = $this->installer->install($this->moduleZip(manifest: ['version' => '1.1.0']));

        $this->assertSame('1.0.0', $result['upgradedFrom']);
        $this->assertSame('1.1.0', app(ModuleManager::class)->read('Example')->version);
        $this->assertSame('1.1.0', Module::where('slug', 'example')->sole()->version);
        $this->assertSame(['Example'], $this->folders());
    }

    public function test_an_upgrade_is_staged_but_not_applied_in_safe_mode()
    {
        $this->installer->install($this->moduleZip());
        app(ModuleManager::class)->enable('example');

        config(['modules.safe_mode' => true]);

        $result = $this->installer->install($this->moduleZip(manifest: ['version' => '1.1.0']));

        $this->assertSame('1.0.0', $result['upgradedFrom']);
        $this->assertSame('1.1.0', app(ModuleManager::class)->read('Example')->version);
        $this->assertSame('1.0.0', Module::where('slug', 'example')->sole()->version);
    }

    private function assertRejected(string $zip, string $message, int $keep = 0): void
    {
        try {
            $this->installer->install($zip);
            $this->fail("The zip was accepted, but should have been rejected with: {$message}");
        } catch (ModuleException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        // Nothing half-written, and no staging folders left behind.
        $this->assertCount($keep, $this->folders());
    }

    /**
     * @return list<string> Everything in the modules folder, dot folders too.
     */
    private function folders(): array
    {
        return array_values(array_diff(scandir($this->modulesPath) ?: [], ['.', '..']));
    }
}
