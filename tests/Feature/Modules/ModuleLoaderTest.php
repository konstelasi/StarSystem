<?php

namespace Tests\Feature\Modules;

use App\Hooks\Hook;
use App\Models\Module;
use App\Modules\InvalidManifest;
use App\Modules\Manifest;
use App\Modules\ModuleException;
use App\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Modules\Example\Activity;
use Modules\Example\Support\Greeting;
use ReflectionClass;
use Tests\Concerns\CrossSite;
use Tests\Concerns\InteractsWithModules;
use Tests\TestCase;

class ModuleLoaderTest extends TestCase
{
    use CrossSite, InteractsWithModules, RefreshDatabase;

    private ModuleManager $modules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryModulesFolder();
        $this->modules = app(ModuleManager::class);
    }

    public function test_it_finds_modules_and_explains_broken_ones()
    {
        $this->addFixtureModule('Example');
        File::copyDirectory(base_path('tests/Fixtures/modules/Example'), $this->modulesPath.'/Renamed');
        File::ensureDirectoryExists($this->modulesPath.'/Empty');

        $found = $this->modules->discover();

        $this->assertInstanceOf(Manifest::class, $found['Example']);
        $this->assertInstanceOf(InvalidManifest::class, $found['Renamed']);
        $this->assertStringContainsString('Rename one to match', $found['Renamed']->getMessage());
        $this->assertInstanceOf(InvalidManifest::class, $found['Empty']);
    }

    public function test_module_classes_load_from_src_without_composer()
    {
        $this->addFixtureModule('Example');
        $this->modules->enable('example');

        $this->assertSame('Hello from the example module', Greeting::hello());

        $file = (string) (new ReflectionClass(Greeting::class))->getFileName();
        $this->assertStringStartsWith(realpath($this->modulesPath), realpath($file));
    }

    public function test_enabling_and_disabling_is_saved()
    {
        $this->addFixtureModule('Example');

        $this->modules->enable('example');

        $module = Module::where('slug', 'example')->sole();
        $this->assertTrue($module->enabled);
        $this->assertSame('Example', $module->name);
        $this->assertSame('1.0.0', $module->version);
        $this->assertNotNull($module->installed_at);

        $this->modules->disable('example');

        $this->assertFalse($module->fresh()?->enabled);
    }

    public function test_a_module_for_another_starsystem_version_cannot_be_enabled()
    {
        $this->addFixtureModule('Example');
        config(['modules.core_version' => '0.2.0']);

        try {
            $this->modules->enable('example');
            $this->fail('An incompatible module was enabled.');
        } catch (ModuleException $e) {
            $this->assertStringContainsString('needs StarSystem ^0.1, and this is 0.2.0', $e->getMessage());
        }

        $this->assertSame(0, Module::count());
    }

    public function test_enabled_modules_register_their_hooks_at_boot()
    {
        $this->addFixtureModule('Example');
        $this->modules->enable('example');
        $this->asSite($this->defaultSite());
        Activity::$saved = [];

        $loaded = $this->bootModules()->loaded();

        $this->assertSame(['example'], array_keys($loaded));

        // The filter.
        $this->assertArrayHasKey('example_rating', Hook::applyFilters('schema.field_types', []));

        // The action, with the current site.
        Hook::doAction('backend.entries:saved', 42);
        $this->assertSame([['entry' => 42, 'site' => 1]], Activity::$saved);

        // The view slot, pointing at the module's prebuilt script.
        $this->assertSame([[
            'component' => 'example::ExampleNotice',
            'props' => ['message' => "Example 1.0.0 is running on {$this->defaultSite()->name}."],
            'src' => url('_modules/example/components.js').'?v=1.0.0',
        ]], Hook::viewSlot('backend.view:modules:index'));
    }

    public function test_disabled_modules_are_not_loaded()
    {
        $this->addFixtureModule('Example');
        $this->modules->enable('example');
        $this->modules->disable('example');

        $this->assertSame([], $this->bootModules()->loaded());
        $this->assertFalse(Hook::hasFilter('schema.field_types'));
    }

    public function test_a_module_left_behind_by_a_starsystem_upgrade_is_not_loaded()
    {
        $this->addFixtureModule('Example');
        $this->modules->enable('example');
        config(['modules.core_version' => '1.0.0']);

        $this->assertSame([], $this->bootModules()->loaded());
    }

    public function test_module_assets_are_served_from_the_assets_folder_only()
    {
        $this->addFixtureModule('Example');
        $this->modules->enable('example');
        $this->bootModules();

        $this->get('/_modules/example/components.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get('/_modules/example/../module.json')->assertNotFound();
        $this->get('/_modules/example/..%2Fsrc%2FActivity.php')->assertNotFound();
        $this->get('/_modules/example/missing.js')->assertNotFound();
        $this->get('/_modules/other/components.js')->assertNotFound();
    }

    public function test_assets_of_disabled_modules_are_not_served()
    {
        $this->addFixtureModule('Example');
        $this->modules->enable('example');
        $this->modules->disable('example');
        $this->bootModules();

        $this->get('/_modules/example/components.js')->assertNotFound();
    }
}
