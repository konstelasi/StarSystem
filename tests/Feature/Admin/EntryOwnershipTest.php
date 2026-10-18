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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\Concerns\ManagesTestModels;
use Tests\Concerns\UsesStarDust;
use Tests\TestCase;

/**
 * Who may change which entry, by who made it. Requests go through the test
 * site's own domain, as in EntryControllerTest.
 */
class EntryOwnershipTest extends TestCase
{
    use CrossSite, ManagesTestModels, RefreshDatabase, UsesStarDust;

    protected function setUp(): void
    {
        parent::setUp();

        config(['starsystem.multisite' => true]);
    }

    protected function tearDown(): void
    {
        $this->drain();

        parent::tearDown();
    }

    /**
     * @param  list<string>  $abilities
     */
    private function memberWith(array $abilities): User
    {
        static $n = 0;
        $role = Role::create(['tenant_id' => $this->site->id, 'name' => 'Custom '.++$n]);
        $role->syncAbilities($abilities);

        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => $role->id]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    /**
     * Someone who edits, trashes, restores and deletes only their own.
     */
    private function contributor(): User
    {
        return $this->memberWith([
            Ability::ENTRIES_VIEW, Ability::ENTRIES_CREATE,
            Ability::ENTRIES_UPDATE_OWN, Ability::ENTRIES_TRASH_OWN,
            Ability::ENTRIES_RESTORE_OWN, Ability::ENTRIES_DELETE_OWN,
        ]);
    }

    private function editor(): User
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => Role::builtIn(Role::EDITOR)->id]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    private function entry(SchemaModel $model, ?User $author, string $title = 'Entry'): int
    {
        return app(EntryManager::class)->create($model, ['title' => $title], $author?->id);
    }

    /**
     * An entry written before authors were kept.
     */
    private function legacyEntry(SchemaModel $model, string $title = 'Old'): int
    {
        return $this->writeEntry($model->stardust_model_id, ['title' => $title, EntryManager::TRASH_FIELD => 0]);
    }

    private function title(SchemaModel $model, int $entryId): ?string
    {
        return app(EntryManager::class)->get($model, $entryId)?->fields['title'] ?? null;
    }

    private function save(User $as, SchemaModel $model, int $entryId, string $title)
    {
        return $this->actingAs($as)->put(
            $this->url('admin.models.entries.update', ['model' => $model->id, 'entry' => $entryId]),
            ['fields' => ['title' => $title]],
        );
    }

    public function test_someone_edits_their_own_entry_and_not_another_persons_or_an_old_one()
    {
        $model = $this->create('articles', 'Articles');
        $contributor = $this->contributor();
        $other = User::factory()->create();
        $mine = $this->entry($model, $contributor, 'Mine');
        $theirs = $this->entry($model, $other, 'Theirs');
        $old = $this->legacyEntry($model);

        $this->save($contributor, $model, $mine, 'Mine, changed')->assertRedirect();
        $this->save($contributor, $model, $theirs, 'Hijacked')->assertForbidden();
        $this->save($contributor, $model, $old, 'Hijacked')->assertForbidden();

        $this->assertSame('Mine, changed', $this->title($model, $mine));
        $this->assertSame('Theirs', $this->title($model, $theirs));
        $this->assertSame('Old', $this->title($model, $old));
    }

    public function test_an_editor_with_the_full_ability_edits_anything_including_old_entries()
    {
        $model = $this->create('articles', 'Articles');
        $editor = $this->editor();
        $theirs = $this->entry($model, User::factory()->create(), 'Theirs');
        $old = $this->legacyEntry($model);

        $this->save($editor, $model, $theirs, 'Changed')->assertRedirect();
        $this->save($editor, $model, $old, 'Changed too')->assertRedirect();

        $this->assertSame('Changed', $this->title($model, $theirs));
        $this->assertSame('Changed too', $this->title($model, $old));
    }

    public function test_a_role_with_neither_form_cannot_edit_even_its_own()
    {
        $model = $this->create('articles', 'Articles');
        $reader = $this->memberWith([Ability::ENTRIES_VIEW, Ability::ENTRIES_CREATE]);
        $mine = $this->entry($model, $reader, 'Mine');

        $this->save($reader, $model, $mine, 'Changed')->assertForbidden();

        $this->assertSame('Mine', $this->title($model, $mine));
    }

    public function test_trashing_restoring_and_deleting_follow_the_same_rule()
    {
        $model = $this->create('articles', 'Articles');
        $contributor = $this->contributor();
        $mine = $this->entry($model, $contributor, 'Mine');
        $theirs = $this->entry($model, User::factory()->create(), 'Theirs');
        $route = fn (string $name, int $id) => $this->url($name, ['model' => $model->id, 'entry' => $id]);

        $this->actingAs($contributor)->post($route('admin.models.entries.trash.store', $theirs))->assertForbidden();
        $this->actingAs($contributor)->post($route('admin.models.entries.trash.store', $mine))->assertRedirect();
        $this->assertTrue(app(EntryManager::class)->isTrashed(app(EntryManager::class)->get($model, $mine)));
        $this->assertFalse(app(EntryManager::class)->isTrashed(app(EntryManager::class)->get($model, $theirs)));

        $this->actingAs($contributor)->post($route('admin.models.entries.restore', $mine))->assertRedirect();
        $this->assertFalse(app(EntryManager::class)->isTrashed(app(EntryManager::class)->get($model, $mine)));

        // Someone else's trashed entry can be neither restored nor deleted for good.
        app(EntryManager::class)->trash($model, app(EntryManager::class)->get($model, $theirs));
        $this->actingAs($contributor)->post($route('admin.models.entries.restore', $theirs))->assertForbidden();
        $this->actingAs($contributor)->delete($route('admin.models.entries.destroy', $theirs))->assertForbidden();
        $this->assertNotNull(app(EntryManager::class)->get($model, $theirs));

        app(EntryManager::class)->trash($model, app(EntryManager::class)->get($model, $mine));
        $this->actingAs($contributor)->delete($route('admin.models.entries.destroy', $mine))->assertRedirect();
        $this->assertNull(app(EntryManager::class)->get($model, $mine));
    }

    public function test_the_list_says_who_made_each_entry_and_what_this_account_may_do()
    {
        $model = $this->create('articles', 'Articles');
        $contributor = $this->contributor();
        $ada = User::factory()->create(['name' => 'Ada']);
        $gone = User::factory()->create(['name' => 'Gone']);
        $mine = $this->entry($model, $contributor);
        $byAda = $this->entry($model, $ada);
        $byGone = $this->entry($model, $gone);
        $old = $this->legacyEntry($model);
        $gone->delete();

        $rows = collect($this->actingAs($contributor)
            ->get($this->url('admin.models.entries.index', ['model' => $model->id]))
            ->inertiaProps('entries'))->keyBy('id');

        $this->assertSame(['state' => 'known', 'name' => $contributor->name], $rows[$mine]['author']);
        $this->assertSame(['state' => 'known', 'name' => 'Ada'], $rows[$byAda]['author']);
        $this->assertSame(['state' => 'former', 'name' => null], $rows[$byGone]['author']);
        $this->assertSame(['state' => 'unknown', 'name' => null], $rows[$old]['author']);

        $this->assertSame(['update' => true, 'trash' => true, 'restore' => true, 'delete' => true], $rows[$mine]['can']);
        foreach ([$byAda, $byGone, $old] as $id) {
            $this->assertSame(['update' => false, 'trash' => false, 'restore' => false, 'delete' => false], $rows[$id]['can']);
        }
    }

    public function test_an_editor_may_do_everything_to_every_row()
    {
        $model = $this->create('articles', 'Articles');
        $this->entry($model, User::factory()->create());
        $this->legacyEntry($model);

        $rows = $this->actingAs($this->editor())
            ->get($this->url('admin.models.entries.index', ['model' => $model->id]))
            ->inertiaProps('entries');

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['update' => true, 'trash' => true, 'restore' => true, 'delete' => true], $row['can']);
        }
    }

    public function test_the_edit_page_says_who_made_it_who_saved_it_last_and_whether_this_account_may_save()
    {
        $model = $this->create('articles', 'Articles');
        $contributor = $this->contributor();
        $ada = User::factory()->create(['name' => 'Ada']);
        $mine = $this->entry($model, $contributor);
        $theirs = $this->entry($model, $ada);
        app(EntryManager::class)->update($model, $theirs, ['title' => 'Edited'], $contributor->id);

        $this->actingAs($contributor)
            ->get($this->url('admin.models.entries.edit', ['model' => $model->id, 'entry' => $mine]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.can_update', true)
                ->where('entry.author.name', $contributor->name)
            );

        $this->actingAs($contributor)
            ->get($this->url('admin.models.entries.edit', ['model' => $model->id, 'entry' => $theirs]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.can_update', false)
                ->where('entry.author', ['state' => 'known', 'name' => 'Ada'])
                ->where('entry.saved_by', $contributor->name)
            );
    }

    public function test_only_mine_lists_the_signed_in_accounts_entries_before_and_after_the_tick()
    {
        $model = $this->create('articles', 'Articles');
        $contributor = $this->contributor();
        $other = User::factory()->create();
        $mine = $this->entry($model, $contributor);
        $this->entry($model, $other);
        $this->legacyEntry($model);
        $list = fn (bool $mineOnly) => array_column($this->actingAs($contributor)
            ->get($this->url('admin.models.entries.index', ['model' => $model->id]).($mineOnly ? '?mine=1' : ''))
            ->assertInertia(fn (Assert $page) => $page->where('mine', $mineOnly))
            ->inertiaProps('entries'), 'id');

        // Before StarDust has indexed the creator field, the list drops the rest itself.
        $this->assertSame([$mine], $list(true));
        $this->assertCount(3, $list(false));

        $this->drain();

        $this->assertTrue(app(EntryManager::class)->createdByIndexed($model));
        $this->assertSame([$mine], $list(true));
        $this->assertCount(3, $list(false));
    }

    public function test_only_mine_works_in_the_trash_too()
    {
        $model = $this->create('articles', 'Articles');
        $contributor = $this->contributor();
        $mine = $this->entry($model, $contributor);
        $theirs = $this->entry($model, User::factory()->create());
        foreach ([$mine, $theirs] as $id) {
            app(EntryManager::class)->trash($model, app(EntryManager::class)->get($model, $id));
        }

        $rows = $this->actingAs($contributor)
            ->get($this->url('admin.models.entries.trash', ['model' => $model->id]).'?mine=1')
            ->inertiaProps('entries');

        $this->assertSame([$mine], array_column($rows, 'id'));
    }

    public function test_a_role_that_holds_only_an_own_form_still_opens_the_list_and_the_edit_page()
    {
        $model = $this->create('articles', 'Articles');
        $viewer = $this->memberWith([Ability::ENTRIES_VIEW, Ability::ENTRIES_UPDATE_OWN]);
        $theirs = $this->entry($model, User::factory()->create());

        $this->actingAs($viewer)->get($this->url('admin.models.entries.index', ['model' => $model->id]))->assertOk();
        $this->actingAs($viewer)->get($this->url('admin.models.entries.edit', ['model' => $model->id, 'entry' => $theirs]))->assertOk();
        $this->actingAs($viewer)->post($this->url('admin.models.entries.trash.store', ['model' => $model->id, 'entry' => $theirs]))->assertForbidden();
    }
}
