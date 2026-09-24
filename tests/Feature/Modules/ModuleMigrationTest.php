<?php

namespace Tests\Feature\Modules;

use App\Models\Module;
use App\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithModules;
use Tests\TestCase;

class ModuleMigrationTest extends TestCase
{
    use InteractsWithModules, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryModulesFolder();
    }

    public function test_enabling_a_module_runs_its_migrations_once()
    {
        $this->addFixtureModule('Example', withMigrations: true);
        $modules = app(ModuleManager::class);

        $modules->enable('example');

        $this->assertTrue(Schema::hasTable('mod_example_notes'));
        $this->assertTrue(DB::table('migrations')->where('migration', '2026_01_01_000000_create_mod_example_notes_table')->exists());

        // Enabling again after a disable doesn't rerun what already ran.
        $modules->disable('example');
        $modules->enable('example');

        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_01_01_000000_create_mod_example_notes_table')->count());
        $this->assertTrue(Module::where('slug', 'example')->sole()->enabled);
    }

    public function test_uninstalling_with_its_data_rolls_its_migrations_back()
    {
        $this->addFixtureModule('Example', withMigrations: true);
        $modules = app(ModuleManager::class);
        $modules->enable('example');

        $modules->uninstall('example', deleteData: true);

        $this->assertFalse(Schema::hasTable('mod_example_notes'));
        $this->assertFalse(DB::table('migrations')->where('migration', '2026_01_01_000000_create_mod_example_notes_table')->exists());
        $this->assertDirectoryDoesNotExist($this->modulesPath.'/Example');
        $this->assertSame(0, Module::count());
    }

    public function test_an_upgrade_copied_in_over_ftp_runs_its_new_migrations_when_applied()
    {
        $this->addFixtureModule('Example');
        $modules = app(ModuleManager::class);
        $modules->enable('example');

        // The new version's files arrive by FTP, migrations and all.
        File::copyDirectory(base_path('tests/Fixtures/modules/Example/database'), $this->modulesPath.'/Example/database');
        File::put($this->modulesPath.'/Example/module.json', str_replace('"1.0.0"', '"1.1.0"', File::get($this->modulesPath.'/Example/module.json')));

        $this->assertTrue(collect($modules->overview())->firstWhere('slug', 'example')['updatePending'] ?? false);

        $modules->applyUpdate('example');

        $this->assertTrue(Schema::hasTable('mod_example_notes'));
        $this->assertSame('1.1.0', Module::where('slug', 'example')->sole()->version);
        $this->assertFalse(collect($modules->overview())->firstWhere('slug', 'example')['updatePending'] ?? true);
    }
}
