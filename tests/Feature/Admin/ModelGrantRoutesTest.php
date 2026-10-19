<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Entries\EntryManager;
use App\Models\Role;
use App\Models\SchemaModel;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CrossSite;
use Tests\Concerns\ManagesTestModels;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * A role that holds an ability for one model reaches that model's pages and
 * no other model's. Requests go through the test site's own domain, as in
 * EntryOwnershipTest.
 */
class ModelGrantRoutesTest extends TestCase
{
    use CrossSite, ManagesTestModels, RefreshDatabase, UsesStarDust;

    private SchemaModel $articles;

    private SchemaModel $notes;

    protected function setUp(): void
    {
        parent::setUp();

        config(['starsystem.multisite' => true]);

        $this->articles = $this->create('articles', 'Articles');
        $this->notes = $this->create('notes', 'Notes');
    }

    protected function tearDown(): void
    {
        $this->drain();

        parent::tearDown();
    }

    /**
     * A member whose role holds $global for every model and $scoped for the
     * models named by slug.
     *
     * @param  list<string>  $global
     * @param  array<string, list<string>>  $scoped
     */
    private function member(array $global, array $scoped = []): User
    {
        static $n = 0;
        $role = Role::create(['tenant_id' => $this->site->id, 'name' => 'Custom '.++$n]);
        $role->syncAbilities($global);
        $role->syncModelAbilities(collect($scoped)->mapWithKeys(fn (array $abilities, string $slug) => [
            SchemaModel::query()->where('slug', $slug)->value('id') => $abilities,
        ])->all());

        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => $role->id]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    private function entry(SchemaModel $model, ?User $author = null, string $title = 'Entry'): int
    {
        return app(EntryManager::class)->create($model, ['title' => $title], $author?->id);
    }

    private function title(SchemaModel $model, int $entryId): ?string
    {
        return app(EntryManager::class)->get($model, $entryId)?->fields['title'] ?? null;
    }

    private function page(User $as, string $route, SchemaModel $model, array $more = [])
    {
        return $this->actingAs($as)->get($this->url($route, ['model' => $model->id, ...$more]));
    }

    private function save(User $as, SchemaModel $model, int $entryId, string $title)
    {
        return $this->actingAs($as)->put(
            $this->url('admin.models.entries.update', ['model' => $model->id, 'entry' => $entryId]),
            ['fields' => ['title' => $title]],
        );
    }

    public function test_viewing_one_model_opens_its_pages_and_not_another_models()
    {
        $reader = $this->member([], ['articles' => [Ability::ENTRIES_VIEW]]);
        $here = $this->entry($this->articles);
        $there = $this->entry($this->notes);

        $this->page($reader, 'admin.models.entries.index', $this->articles)->assertOk();
        $this->page($reader, 'admin.models.entries.trash', $this->articles)->assertOk();
        $this->page($reader, 'admin.models.entries.edit', $this->articles, ['entry' => $here])->assertOk();

        $this->page($reader, 'admin.models.entries.index', $this->notes)->assertForbidden();
        $this->page($reader, 'admin.models.entries.trash', $this->notes)->assertForbidden();
        $this->page($reader, 'admin.models.entries.edit', $this->notes, ['entry' => $there])->assertForbidden();
    }

    public function test_an_ability_held_for_every_model_opens_each_model()
    {
        $reader = $this->member([Ability::ENTRIES_VIEW]);

        $this->page($reader, 'admin.models.entries.index', $this->articles)->assertOk();
        $this->page($reader, 'admin.models.entries.index', $this->notes)->assertOk();
    }

    public function test_creating_in_one_model_does_not_allow_creating_in_another()
    {
        $writer = $this->member([], ['articles' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_CREATE]]);

        $this->page($writer, 'admin.models.entries.create', $this->articles)->assertOk();
        $this->page($writer, 'admin.models.entries.create', $this->notes)->assertForbidden();

        $this->actingAs($writer)
            ->post($this->url('admin.models.entries.store', ['model' => $this->notes->id]), ['fields' => ['title' => 'Sneaked in']])
            ->assertForbidden();
        $this->actingAs($writer)
            ->post($this->url('admin.models.entries.store', ['model' => $this->articles->id]), ['fields' => ['title' => 'Allowed']])
            ->assertRedirect();

        $this->assertSame(0, count(app(EntryManager::class)->page($this->notes, null, false, null)->rows));
        $this->assertCount(1, app(EntryManager::class)->page($this->articles, null, false, null)->rows);
    }

    public function test_editing_one_model_leaves_another_models_entries_alone()
    {
        $editor = $this->member([Ability::ENTRIES_VIEW], ['articles' => [Ability::ENTRIES_UPDATE]]);
        $here = $this->entry($this->articles, null, 'Here');
        $there = $this->entry($this->notes, null, 'There');

        $this->save($editor, $this->articles, $here, 'Changed')->assertRedirect();
        $this->save($editor, $this->notes, $there, 'Changed')->assertForbidden();

        $this->assertSame('Changed', $this->title($this->articles, $here));
        $this->assertSame('There', $this->title($this->notes, $there));
    }

    public function test_the_own_form_for_one_model_covers_the_entries_made_there_by_the_account()
    {
        $writer = $this->member([], ['articles' => [Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE_OWN]]);
        $other = User::factory()->create();
        $mine = $this->entry($this->articles, $writer, 'Mine');
        $theirs = $this->entry($this->articles, $other, 'Theirs');
        $mineElsewhere = $this->entry($this->notes, $writer, 'Mine too');

        $this->save($writer, $this->articles, $mine, 'Changed')->assertRedirect();
        $this->save($writer, $this->articles, $theirs, 'Hijacked')->assertForbidden();
        $this->save($writer, $this->notes, $mineElsewhere, 'Hijacked')->assertForbidden();

        $this->assertSame('Changed', $this->title($this->articles, $mine));
        $this->assertSame('Theirs', $this->title($this->articles, $theirs));
        $this->assertSame('Mine too', $this->title($this->notes, $mineElsewhere));
    }

    public function test_trashing_restoring_and_deleting_follow_the_model_too()
    {
        $manager = $this->member([Ability::ENTRIES_VIEW], ['articles' => [
            Ability::ENTRIES_TRASH, Ability::ENTRIES_RESTORE, Ability::ENTRIES_DELETE,
        ]]);
        $here = $this->entry($this->articles);
        $there = $this->entry($this->notes);
        $route = fn (string $name, SchemaModel $model, int $id) => $this->url($name, ['model' => $model->id, 'entry' => $id]);

        $this->actingAs($manager)->post($route('admin.models.entries.trash.store', $this->notes, $there))->assertForbidden();
        $this->actingAs($manager)->post($route('admin.models.entries.trash.store', $this->articles, $here))->assertRedirect();
        $this->actingAs($manager)->post($route('admin.models.entries.restore', $this->articles, $here))->assertRedirect();

        app(EntryManager::class)->trash($this->notes, app(EntryManager::class)->get($this->notes, $there));
        $this->actingAs($manager)->delete($route('admin.models.entries.destroy', $this->notes, $there))->assertForbidden();
        $this->assertNotNull(app(EntryManager::class)->get($this->notes, $there));
    }

    public function test_what_is_held_for_every_model_and_for_one_add_up_in_the_row_flags()
    {
        $editor = $this->member([Ability::ENTRIES_VIEW], ['articles' => [Ability::ENTRIES_UPDATE]]);
        $this->entry($this->articles);
        $this->entry($this->notes);
        $flags = fn (SchemaModel $model) => $this->page($editor, 'admin.models.entries.index', $model)->inertiaProps('entries')[0]['can'];

        $this->assertSame(['update' => true, 'trash' => false, 'restore' => false, 'delete' => false], $flags($this->articles));
        $this->assertSame(['update' => false, 'trash' => false, 'restore' => false, 'delete' => false], $flags($this->notes));
    }

    public function test_the_edit_page_says_whether_this_model_allows_saving()
    {
        $editor = $this->member([Ability::ENTRIES_VIEW], ['articles' => [Ability::ENTRIES_UPDATE]]);
        $here = $this->entry($this->articles);
        $there = $this->entry($this->notes);

        $this->assertTrue($this->page($editor, 'admin.models.entries.edit', $this->articles, ['entry' => $here])->inertiaProps('entry')['can_update']);
        $this->assertFalse($this->page($editor, 'admin.models.entries.edit', $this->notes, ['entry' => $there])->inertiaProps('entry')['can_update']);
    }

    public function test_changing_the_builder_of_one_model_does_not_open_another_models()
    {
        $builder = $this->member([Ability::MODELS_VIEW], ['articles' => [Ability::MODELS_UPDATE]]);

        $this->page($builder, 'admin.models.builder', $this->articles)->assertOk();
        $this->page($builder, 'admin.models.builder', $this->notes)->assertForbidden();
        $this->actingAs($builder)
            ->post($this->url('admin.models.builder.preview', ['model' => $this->notes->id]), [])
            ->assertForbidden();
    }

    public function test_exporting_one_model_does_not_export_another()
    {
        $exporter = $this->member([Ability::MODELS_VIEW], ['articles' => [Ability::MODELS_EXPORT]]);

        $this->page($exporter, 'admin.models.export', $this->articles)->assertOk();
        $this->page($exporter, 'admin.models.export', $this->notes)->assertForbidden();
    }

    public function test_a_role_with_no_grants_still_reaches_nothing()
    {
        $nobody = $this->member([Ability::MODELS_VIEW]);

        $this->page($nobody, 'admin.models.entries.index', $this->articles)->assertForbidden();
        $this->page($nobody, 'admin.models.builder', $this->articles)->assertForbidden();
    }
}
