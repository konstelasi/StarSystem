<?php

namespace Tests\Feature\Modules;

use App\Hooks\Hook;
use App\Models\Module;
use App\Models\User;
use App\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithModules;
use Tests\TestCase;

class SafeModeTest extends TestCase
{
    use InteractsWithModules, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryModulesFolder();
        $this->addFixtureModule('Example');
        $this->addFixtureModule('Faulty');

        $modules = app(ModuleManager::class);
        $modules->enable('example');
        $modules->enable('faulty');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failures(): array
    {
        return [
            'while registering' => ['register', 'Its provider failed while registering: RuntimeException: Faulty broke while registering.'],
            'while booting' => ['boot', 'Its provider failed while booting: RuntimeException: Faulty broke while booting.'],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_throwing_module_is_switched_off_and_the_rest_keep_running(string $failIn, string $error)
    {
        config(['faulty.fail_in' => $failIn]);

        $loaded = $this->bootModules()->loaded();

        $this->assertSame(['example'], array_keys($loaded));

        $faulty = Module::where('slug', 'faulty')->sole();
        $this->assertFalse($faulty->enabled);
        $this->assertStringStartsWith($error, (string) $faulty->last_error);
        $this->assertNotNull($faulty->last_error_at);

        // The hook it added before throwing is gone; the example's stays.
        $this->assertSame(['example_rating'], array_keys(Hook::applyFilters('schema.field_types', [])));
    }

    public function test_the_app_keeps_serving_and_the_owner_sees_a_notice()
    {
        config(['faulty.fail_in' => 'boot']);
        $this->bootModules();
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('moduleNotices.safeMode', null)
                ->has('moduleNotices.failed', 1, fn (Assert $module) => $module
                    ->where('slug', 'faulty')
                    ->where('name', 'Faulty')
                    ->where('error', fn (string $error) => str_contains($error, 'Faulty broke while booting.'))
                )
            );

        $this->actingAs($other)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('moduleNotices', null));
    }

    public function test_a_module_whose_files_are_gone_is_switched_off()
    {
        File::deleteDirectory($this->modulesPath.'/Faulty');

        $this->assertSame(['example'], array_keys($this->bootModules()->loaded()));
        $this->assertStringContainsString("files can't be loaded", (string) Module::where('slug', 'faulty')->sole()->last_error);
    }

    public function test_a_module_left_behind_by_a_starsystem_upgrade_is_switched_off()
    {
        config(['modules.core_version' => '1.0.0']);

        $this->assertSame([], $this->bootModules()->loaded());
        $this->assertStringContainsString('needs StarSystem ^0.1, and this is 1.0.0', (string) Module::where('slug', 'example')->sole()->last_error);
    }

    public function test_manual_safe_mode_from_the_environment_loads_no_modules()
    {
        config(['modules.safe_mode' => true]);

        $this->assertSame([], $this->bootModules()->loaded());
        $this->assertFalse(Hook::hasFilter('schema.field_types'));

        // Nothing is switched off: the modules come back with safe mode off.
        $this->assertSame(2, Module::where('enabled', true)->count());
    }

    public function test_manual_safe_mode_from_a_file_uploaded_over_ftp()
    {
        $flag = $this->modulesPath.'/.safe-mode';
        config(['modules.safe_mode_file' => $flag]);
        File::put($flag, '');

        $this->assertSame([], $this->bootModules()->loaded());

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('moduleNotices.safeMode', 'file'));

        File::delete($flag);

        $this->assertSame(['example', 'faulty'], array_keys($this->bootModules()->loaded()));
    }
}
