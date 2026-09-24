<?php

namespace Tests\Feature\Modules;

use App\Models\Module;
use App\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
