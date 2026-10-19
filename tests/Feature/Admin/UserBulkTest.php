<?php

namespace Tests\Feature\Admin;

use App\Access\Ability;
use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class UserBulkTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function memberAs(string|Role $role): User
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->update([
            'role_id' => $role instanceof Role ? $role->id : Role::builtIn($role)->id,
        ]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    private function membership(User $user): SiteUser
    {
        return SiteUser::query()->where('user_id', $user->id)->firstOrFail();
    }

    /**
     * @param  list<User>  $users
     * @return list<int>
     */
    private function ids(array $users): array
    {
        return array_map(fn (User $user) => $this->membership($user)->id, $users);
    }

    private function role(string $name): int
    {
        return Role::builtIn($name)->id;
    }

    private function actingConfirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_several_people_get_a_new_role_at_once()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        [$ben, $cleo, $dan] = [$this->memberAs(Role::EDITOR), $this->memberAs(Role::EDITOR), $this->memberAs(Role::EDITOR)];

        $this->actingConfirmed($admin)
            ->put(route('admin.users.bulk.update'), ['ids' => $this->ids([$ben, $cleo]), 'role_id' => $this->role(Role::VIEWER)])
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame($this->role(Role::VIEWER), $this->membership($ben)->role_id);
        $this->assertSame($this->role(Role::VIEWER), $this->membership($cleo)->role_id);
        $this->assertSame($this->role(Role::EDITOR), $this->membership($dan)->role_id);
    }

    public function test_each_change_is_logged_on_its_own_and_unchanged_people_are_not()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        $cleo = $this->memberAs(Role::VIEWER);

        $this->actingConfirmed($admin)
            ->put(route('admin.users.bulk.update'), ['ids' => $this->ids([$ben, $cleo]), 'role_id' => $this->role(Role::VIEWER)]);

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::USER_ROLE_CHANGED, $row->action);
        $this->assertSame($ben->email, $row->subject_label);
        $this->assertEquals(['from' => 'Editor', 'to' => 'Viewer'], $row->properties);
        $this->assertSame($admin->id, $row->actor_id);
    }

    public function test_people_the_actor_may_not_manage_are_skipped_and_the_rest_go_through()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        $owner = User::factory()->installOwner()->create();

        $this->actingConfirmed($admin)
            ->put(route('admin.users.bulk.update'), ['ids' => $this->ids([$ben, $owner, $admin]), 'role_id' => $this->role(Role::VIEWER)])
            ->assertRedirect();

        $this->assertSame($this->role(Role::VIEWER), $this->membership($ben)->role_id);
        $this->assertSame($this->role(Role::ADMINISTRATOR), $this->membership($owner)->role_id);
        $this->assertSame($this->role(Role::ADMINISTRATOR), $this->membership($admin)->role_id);
    }

    public function test_someone_with_a_smaller_role_cannot_move_people_above_them()
    {
        $tiny = Role::create(['tenant_id' => 1, 'name' => 'Tiny']);
        $tiny->syncAbilities([Ability::ENTRIES_VIEW]);
        $mover = Role::create(['tenant_id' => 1, 'name' => 'Mover']);
        $mover->syncAbilities([Ability::USERS_VIEW, Ability::USERS_UPDATE, Ability::ENTRIES_VIEW, Ability::MEDIA_VIEW]);
        $small = $this->memberAs($tiny);
        $editor = $this->memberAs(Role::EDITOR);
        $actor = $this->memberAs($mover);

        // The mover may hand out Viewer, and move Tiny, but not an Editor or themselves.
        $this->actingConfirmed($actor)
            ->put(route('admin.users.bulk.update'), ['ids' => $this->ids([$small, $editor, $actor]), 'role_id' => $this->role(Role::VIEWER)])
            ->assertRedirect();

        $this->assertSame($this->role(Role::VIEWER), $this->membership($small)->role_id);
        $this->assertSame($this->role(Role::EDITOR), $this->membership($editor)->role_id);
        $this->assertSame($mover->id, $this->membership($actor)->role_id);
    }

    public function test_nobody_hands_out_a_role_above_their_own()
    {
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Mover']);
        $custom->syncAbilities([Ability::USERS_VIEW, Ability::USERS_UPDATE]);
        $mover = $this->memberAs($custom);
        $ben = $this->memberAs(Role::VIEWER);

        $this->actingConfirmed($mover)
            ->put(route('admin.users.bulk.update'), ['ids' => $this->ids([$ben]), 'role_id' => $this->role(Role::ADMINISTRATOR)])
            ->assertForbidden();

        $this->assertSame($this->role(Role::VIEWER), $this->membership($ben)->role_id);
    }

    public function test_the_selection_and_the_role_are_checked()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $other = $this->makeSite('other.example.com');
        $foreignRole = Role::create(['tenant_id' => $other->id, 'name' => 'Translator']);

        $this->actingConfirmed($admin)->put(route('admin.users.bulk.update'), ['ids' => [], 'role_id' => $this->role(Role::VIEWER)])->assertSessionHasErrors('ids');
        $this->actingConfirmed($admin)->put(route('admin.users.bulk.update'), ['ids' => range(1, 101), 'role_id' => $this->role(Role::VIEWER)])->assertSessionHasErrors('ids');
        $this->actingConfirmed($admin)->put(route('admin.users.bulk.update'), ['ids' => [1], 'role_id' => $foreignRole->id])->assertSessionHasErrors('role_id');
        $this->actingConfirmed($admin)->put(route('admin.users.bulk.update'), ['ids' => ['x'], 'role_id' => $this->role(Role::VIEWER)])->assertSessionHasErrors('ids.0');
    }

    public function test_another_sites_memberships_are_not_touched()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $other = $this->makeSite('other.example.com');
        $stranger = User::factory()->create();
        SiteUser::query()->where('user_id', $stranger->id)->delete();
        $this->asSite($other);
        $foreign = SiteUser::create(['user_id' => $stranger->id, 'role_id' => $this->role(Role::EDITOR)]);
        $this->asSite($this->defaultSite());

        $this->actingConfirmed($admin)->put(route('admin.users.bulk.update'), ['ids' => [$foreign->id], 'role_id' => $this->role(Role::VIEWER)]);

        $this->asSite($other);
        $this->assertSame($this->role(Role::EDITOR), $foreign->fresh()->role_id);
    }

    public function test_several_people_are_removed_at_once_and_each_is_logged()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ben = $this->memberAs(Role::EDITOR);
        $cleo = $this->memberAs(Role::VIEWER);
        $dan = $this->memberAs(Role::EDITOR);

        $this->actingConfirmed($admin)
            ->delete(route('admin.users.bulk.destroy'), ['ids' => $this->ids([$ben, $cleo])])
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame(0, SiteUser::query()->whereIn('user_id', [$ben->id, $cleo->id])->count());
        $this->assertSame(1, SiteUser::query()->where('user_id', $dan->id)->count());
        $this->assertNotNull($ben->fresh());

        $rows = Activity::query()->orderBy('id')->get();
        $this->assertSame([ActivityAction::USER_REMOVED, ActivityAction::USER_REMOVED], $rows->pluck('action')->all());
        $this->assertEqualsCanonicalizing([$ben->email, $cleo->email], $rows->pluck('subject_label')->all());
    }

    public function test_nobody_removes_themselves_or_the_owner_in_a_bulk()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $owner = User::factory()->installOwner()->create();
        $ben = $this->memberAs(Role::EDITOR);

        $this->actingConfirmed($admin)->delete(route('admin.users.bulk.destroy'), ['ids' => $this->ids([$admin, $owner, $ben])]);

        $this->assertSame(1, SiteUser::query()->where('user_id', $admin->id)->count());
        $this->assertSame(1, SiteUser::query()->where('user_id', $owner->id)->count());
        $this->assertSame(0, SiteUser::query()->where('user_id', $ben->id)->count());
    }

    public function test_only_roles_that_may_do_each_can_do_it()
    {
        $ben = $this->memberAs(Role::EDITOR);
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Updater']);
        $custom->syncAbilities([Ability::USERS_VIEW, Ability::USERS_UPDATE]);

        $this->actingConfirmed($this->memberAs(Role::EDITOR))->put(route('admin.users.bulk.update'), ['ids' => $this->ids([$ben]), 'role_id' => $this->role(Role::VIEWER)])->assertForbidden();
        $this->actingConfirmed($this->memberAs($custom))->delete(route('admin.users.bulk.destroy'), ['ids' => $this->ids([$ben])])->assertForbidden();

        $this->assertSame(1, SiteUser::query()->where('user_id', $ben->id)->count());
    }

    public function test_the_password_is_asked_for_first()
    {
        $ben = $this->memberAs(Role::EDITOR);

        $this->actingAs($this->memberAs(Role::ADMINISTRATOR))
            ->delete(route('admin.users.bulk.destroy'), ['ids' => $this->ids([$ben])])
            ->assertRedirect(route('password.confirm'));
    }
}
