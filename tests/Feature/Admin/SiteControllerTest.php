<?php

namespace Tests\Feature\Admin;

use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Frontend\ThemeManager;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

/**
 * The Site page, apart from its address table, which SiteTableTest covers.
 * Nothing here needs StarDust.
 */
class SiteControllerTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    private string $themes = '';

    public function test_guests_are_sent_to_sign_in_and_roles_without_the_ability_are_refused()
    {
        $this->asSite($this->defaultSite());

        $this->get(route('admin.site.index'))->assertRedirect(route('login'));

        foreach ([Role::EDITOR, Role::VIEWER] as $role) {
            $this->actingAs($this->memberAs($role))->get(route('admin.site.index'))->assertForbidden();
            $this->actingAs($this->memberAs($role))->put(route('admin.site.update'), ['theme' => '', 'routes' => []])->assertForbidden();
        }
    }

    public function test_an_administrator_sees_the_themes_the_models_and_the_table()
    {
        $this->asSite($this->defaultSite());
        $this->useThemes(['default', 'studio'], broken: ['bare']);

        $this->actingAs($this->memberAs(Role::ADMINISTRATOR))
            ->get(route('admin.site.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/site/Index')
                ->where('themes.0.name', 'default')
                ->where('themes.0.title', 'Default')
                ->where('themes.0.version', '1.0.0')
                ->where('themes.1.name', 'studio')
                ->has('themes', 2)
                ->where('broken.0.folder', 'bare')
                ->where('broken.0.message', fn (string $message) => str_contains($message, 'no theme.json'))
                ->where('theme', null)
                ->where('defaultTheme', 'default')
                ->where('routes', [])
                ->where('models', [])
                ->where('editable', true)
            );
    }

    public function test_a_theme_the_site_chose_is_shown_as_chosen_until_it_goes_missing()
    {
        $this->asSite($this->defaultSite());
        $this->useThemes(['default', 'studio']);
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->defaultSite()->update(['theme' => 'studio']);
        $this->actingAs($admin)->get(route('admin.site.index'))->assertInertia(fn (Assert $page) => $page->where('theme', 'studio'));

        $this->defaultSite()->update(['theme' => 'deleted']);
        $this->actingAs($admin)->get(route('admin.site.index'))->assertInertia(fn (Assert $page) => $page->where('theme', null));
    }

    public function test_someone_who_may_only_view_sees_the_page_and_cannot_save()
    {
        $this->asSite($this->defaultSite());

        $role = Role::create(['tenant_id' => $this->defaultSite()->id, 'name' => 'Watcher']);
        $role->syncAbilities(['site.view']);
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => $role->id]);
        app(SiteAccess::class)->forget();

        $this->actingAs($user)->get(route('admin.site.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('editable', false));
        $this->actingAs($user)->put(route('admin.site.update'), ['theme' => '', 'routes' => []])->assertForbidden();
    }

    public function test_the_theme_must_be_installed_and_saving_it_is_logged()
    {
        $this->asSite($this->defaultSite());
        $this->useThemes(['default', 'studio']);
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingAs($admin)->put(route('admin.site.update'), ['theme' => 'nowhere', 'routes' => []])->assertSessionHasErrors(['theme' => 'Pick one of the installed themes.']);
        $this->assertNull($this->defaultSite()->theme);

        $this->actingAs($admin)->put(route('admin.site.update'), ['theme' => 'studio', 'routes' => []])
            ->assertRedirect(route('admin.site.index'))
            ->assertInertiaFlash('toast.message', 'Site saved.');
        $this->assertSame('studio', $this->defaultSite()->theme);

        $row = Activity::query()->where('action', ActivityAction::SITE_UPDATED)->latest('id')->firstOrFail();
        $this->assertSame(['theme' => 'studio'], $row->properties);

        // Saving what is already there logs nothing more.
        $this->actingAs($admin)->put(route('admin.site.update'), ['theme' => 'studio', 'routes' => []])->assertSessionHasNoErrors();
        $this->assertSame(1, Activity::query()->where('action', ActivityAction::SITE_UPDATED)->count());

        // An empty choice goes back to the default theme.
        $this->actingAs($admin)->put(route('admin.site.update'), ['theme' => '', 'routes' => []])->assertSessionHasNoErrors();
        $this->assertNull($this->defaultSite()->theme);
        $this->assertSame(['theme' => 'default'], Activity::query()->where('action', ActivityAction::SITE_UPDATED)->latest('id')->firstOrFail()->properties);
    }

    public function test_a_table_that_is_not_an_array_of_rows_is_refused()
    {
        $this->asSite($this->defaultSite());
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingAs($admin)->put(route('admin.site.update'), ['theme' => ''])->assertSessionHasErrors('routes');
        $this->actingAs($admin)->put(route('admin.site.update'), ['theme' => '', 'routes' => 'nope'])->assertSessionHasErrors('routes');
        $this->actingAs($admin)->put(route('admin.site.update'), ['theme' => '', 'routes' => ['nope']])->assertSessionHasErrors('routes.0');
        $this->actingAs($admin)->put(route('admin.site.update'), ['theme' => '', 'routes' => [[]]])->assertSessionHasErrors(['routes.0' => 'Pick a model for each address.']);
    }

    private function memberAs(string $role): User
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => Role::builtIn($role)->id]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    /**
     * Points the front end at a temporary themes folder holding copies of
     * the bundled theme, and folders that are not themes.
     *
     * @param  list<string>  $themes
     * @param  list<string>  $broken
     */
    private function useThemes(array $themes, array $broken = []): void
    {
        $this->themes = sys_get_temp_dir().DIRECTORY_SEPARATOR.'starsystem-site-'.Str::random(10);
        File::ensureDirectoryExists($this->themes);

        foreach ($themes as $name) {
            File::copyDirectory(base_path('themes/default'), $this->themes.'/'.$name);
            File::put($this->themes.'/'.$name.'/theme.json', json_encode(['name' => ucfirst($name), 'version' => '1.0.0', 'requires' => ['starsystem' => '^0.1']]));
        }

        foreach ($broken as $name) {
            File::ensureDirectoryExists($this->themes.'/'.$name);
        }

        config(['starsystem.themes.path' => $this->themes]);
        $this->app->forgetInstance(ThemeManager::class);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->themes));
    }
}
