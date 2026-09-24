<?php

namespace Tests\Feature\Modules;

use App\Models\Module;
use App\Models\User;
use App\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsModuleZips;
use Tests\Concerns\InteractsWithModules;
use Tests\TestCase;

class ModuleAdminTest extends TestCase
{
    use BuildsModuleZips, InteractsWithModules, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryModulesFolder();
        $this->beforeApplicationDestroyed(fn () => $this->deleteZips());
        $this->owner = User::factory()->create();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('admin.modules.index'))->assertRedirect(route('login'));
    }

    public function test_only_the_install_owner_manages_modules()
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.modules.index'))
            ->assertForbidden();
    }

    public function test_the_owner_confirms_their_password_first()
    {
        $this->actingAs($this->owner)
            ->get(route('admin.modules.index'))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($this->owner)
            ->post(route('admin.modules.store'), ['module' => $this->upload()])
            ->assertRedirect(route('password.confirm'));

        $this->assertDirectoryDoesNotExist($this->modulesPath.'/Example');
    }

    public function test_the_list_shows_modules_and_their_view_slot()
    {
        $this->addFixtureModule('Example');
        app(ModuleManager::class)->enable('example');
        $this->bootModules();

        $this->asOwner()
            ->get(route('admin.modules.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/modules/Index')
                ->where('safeMode', null)
                ->where('coreVersion', '0.1.0')
                ->has('modules', 1, fn (Assert $module) => $module
                    ->where('slug', 'example')
                    ->where('enabled', true)
                    ->where('version', '1.0.0')
                    ->where('problem', null)
                    ->etc()
                )
                // Slot names contain dots, so no dot-path lookup here.
                ->where('hookSlots', fn ($slots) => collect($slots)->get('backend.view:modules:index')[0]['component'] === 'example::ExampleNotice')
            );
    }

    public function test_the_upload_page_warns_that_modules_run_code()
    {
        $this->asOwner()
            ->get(route('admin.modules.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/modules/Upload')
                ->where('zipSupported', true)
            );
    }

    public function test_uploading_installs_a_disabled_module()
    {
        $this->asOwner()
            ->post(route('admin.modules.store'), ['module' => $this->upload()])
            ->assertRedirect(route('admin.modules.index'))
            ->assertSessionHasNoErrors();

        $this->assertFileExists($this->modulesPath.'/Example/module.json');
        $this->assertSame(0, Module::count());
    }

    public function test_a_hostile_upload_is_refused_with_the_reason()
    {
        $this->asOwner()
            ->from(route('admin.modules.create'))
            ->post(route('admin.modules.store'), ['module' => $this->upload($this->moduleZip(extra: ['Example/../../evil.php' => '<?php']))])
            ->assertRedirect(route('admin.modules.create'))
            ->assertSessionHasErrors(['module' => 'The zip contains a path that leads outside the module folder (Example/../../evil.php).']);

        $this->assertSame([], glob($this->modulesPath.'/*') ?: []);
    }

    public function test_only_zips_are_accepted()
    {
        $this->asOwner()
            ->post(route('admin.modules.store'), ['module' => UploadedFile::fake()->create('module.php', 1)])
            ->assertSessionHasErrors('module');
    }

    public function test_enabling_and_disabling_from_the_admin()
    {
        $this->addFixtureModule('Example');

        $this->asOwner()->post(route('admin.modules.enable', 'example'))->assertRedirect(route('admin.modules.index'));
        $this->assertTrue(Module::where('slug', 'example')->sole()->enabled);

        $this->asOwner()->post(route('admin.modules.disable', 'example'))->assertRedirect(route('admin.modules.index'));
        $this->assertFalse(Module::where('slug', 'example')->sole()->enabled);
    }

    public function test_enabling_an_unknown_module_explains_why()
    {
        $this->asOwner()
            ->post(route('admin.modules.enable', 'nope'))
            ->assertRedirect(route('admin.modules.index'))
            ->assertInertiaFlash('toast.type', 'error');
    }

    public function test_no_module_code_runs_from_the_admin_in_safe_mode()
    {
        $this->addFixtureModule('Example');
        config(['modules.safe_mode' => true]);

        $this->asOwner()
            ->post(route('admin.modules.enable', 'example'))
            ->assertInertiaFlash('toast.message', 'Safe mode is on, so no module code may run. Turn safe mode off first.');

        $this->assertSame(0, Module::count());
    }

    public function test_dismissing_an_error()
    {
        $this->addFixtureModule('Example');
        app(ModuleManager::class)->enable('example');
        Module::where('slug', 'example')->update(['enabled' => false, 'last_error' => 'Boom', 'last_error_at' => now()]);

        $this->asOwner()->post(route('admin.modules.dismiss', 'example'));

        $this->assertNull(Module::where('slug', 'example')->sole()->last_error);
    }

    public function test_uninstalling_removes_the_files_and_the_row()
    {
        $this->addFixtureModule('Example');
        app(ModuleManager::class)->enable('example');

        $this->asOwner()
            ->delete(route('admin.modules.destroy', 'example'), ['delete_data' => 'on'])
            ->assertRedirect(route('admin.modules.index'));

        $this->assertDirectoryDoesNotExist($this->modulesPath.'/Example');
        $this->assertSame(0, Module::count());
    }

    public function test_uninstalling_with_data_deletion_is_refused_in_safe_mode()
    {
        $this->addFixtureModule('Example', withMigrations: true);
        app(ModuleManager::class)->enable('example');
        config(['modules.safe_mode' => true]);

        $this->asOwner()
            ->delete(route('admin.modules.destroy', 'example'), ['delete_data' => 'on'])
            ->assertInertiaFlash('toast.message', 'Safe mode is on, so no module code may run. Turn safe mode off first.');

        $this->assertDirectoryExists($this->modulesPath.'/Example');
        $this->assertSame(1, Module::count());
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function upload(?string $zip = null): UploadedFile
    {
        return new UploadedFile($zip ?? $this->moduleZip(), 'example.zip', 'application/zip', null, true);
    }
}
