<?php

namespace Tests\Feature\Admin;

use App\Access\SiteAccess;
use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class OwnershipTransferTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function memberAs(string|Role $role, ?string $name = null): User
    {
        $user = User::factory()->create($name === null ? [] : ['name' => $name]);
        SiteUser::query()->where('user_id', $user->id)->update([
            'role_id' => $role instanceof Role ? $role->id : Role::builtIn($role)->id,
        ]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    private function owner(): User
    {
        return User::factory()->installOwner()->create(['name' => 'Olga']);
    }

    private function membership(User $user): SiteUser
    {
        return SiteUser::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function actingConfirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function payload(User $target, array $over = []): array
    {
        return ['member_id' => $this->membership($target)->id, 'email' => $target->email, 'password' => 'password', ...$over];
    }

    public function test_the_owner_hands_the_installation_to_an_administrator_and_keeps_their_access()
    {
        $owner = $this->owner();
        $ada = $this->memberAs(Role::ADMINISTRATOR, 'Ada');
        $other = $this->makeSite('other.example.com');

        $this->actingConfirmed($owner)
            ->post(route('admin.users.ownership'), $this->payload($ada))
            ->assertRedirect(route('admin.users.index'));

        $this->assertFalse($owner->fresh()->is_install_owner);
        $this->assertTrue($ada->fresh()->is_install_owner);
        $this->assertFalse(Gate::forUser($owner->fresh())->allows('manage-updates'));
        $this->assertTrue(Gate::forUser($ada->fresh())->allows('manage-updates'));

        // The old owner is an Administrator of this site and of every other one.
        foreach ([$this->defaultSite(), $other] as $site) {
            $this->asSite($site);
            $this->assertTrue($this->membership($owner)->role->isAdministrator(), "No Administrator membership on site {$site->id}.");
        }
    }

    public function test_an_old_owner_who_already_has_a_membership_keeps_that_role()
    {
        $owner = $this->owner();
        $ada = $this->memberAs(Role::ADMINISTRATOR);
        $other = $this->makeSite('other.example.com');
        $this->asSite($other);
        SiteUser::create(['user_id' => $owner->id, 'role_id' => Role::builtIn(Role::EDITOR)->id]);
        $this->asSite($this->defaultSite());

        $this->actingConfirmed($owner)->post(route('admin.users.ownership'), $this->payload($ada));

        $this->asSite($other);
        $this->assertSame(Role::EDITOR, $this->membership($owner)->role->name);
        $this->assertSame(1, SiteUser::query()->where('user_id', $owner->id)->count());
    }

    public function test_the_hand_over_is_logged()
    {
        $owner = $this->owner();
        $ada = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($owner)->post(route('admin.users.ownership'), $this->payload($ada));

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::OWNERSHIP_TRANSFERRED, $row->action);
        $this->assertSame($owner->id, $row->actor_id);
        $this->assertSame($ada->email, $row->subject_label);
    }

    public function test_only_the_install_owner_can_do_it()
    {
        $this->owner();
        $admin = $this->memberAs(Role::ADMINISTRATOR);
        $ada = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($admin)->post(route('admin.users.ownership'), $this->payload($ada))->assertForbidden();

        $this->assertFalse($ada->fresh()->is_install_owner);
    }

    public function test_guests_sign_in_and_everyone_confirms_their_password_first()
    {
        $owner = $this->owner();
        $ada = $this->memberAs(Role::ADMINISTRATOR);

        $this->post(route('admin.users.ownership'), $this->payload($ada))->assertRedirect(route('login'));
        $this->actingAs($owner)->post(route('admin.users.ownership'), $this->payload($ada))->assertRedirect(route('password.confirm'));

        $this->assertTrue($owner->fresh()->is_install_owner);
    }

    public function test_the_password_is_asked_for_again()
    {
        $owner = $this->owner();
        $ada = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($owner)
            ->post(route('admin.users.ownership'), $this->payload($ada, ['password' => 'not-it']))
            ->assertSessionHasErrors('password');

        $this->assertTrue($owner->fresh()->is_install_owner);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_their_email_has_to_be_typed_to_confirm()
    {
        $owner = $this->owner();
        $ada = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($owner)
            ->post(route('admin.users.ownership'), $this->payload($ada, ['email' => 'someone@else.example']))
            ->assertSessionHasErrors('email');

        $this->assertFalse($ada->fresh()->is_install_owner);
    }

    public function test_the_email_is_matched_without_regard_to_case_or_spaces()
    {
        $owner = $this->owner();
        $ada = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingConfirmed($owner)
            ->post(route('admin.users.ownership'), $this->payload($ada, ['email' => '  '.strtoupper($ada->email).' ']))
            ->assertSessionHasNoErrors();

        $this->assertTrue($ada->fresh()->is_install_owner);
    }

    public function test_it_goes_only_to_an_administrator_and_never_to_oneself()
    {
        $owner = $this->owner();
        $editor = $this->memberAs(Role::EDITOR);
        $custom = Role::create(['tenant_id' => 1, 'name' => 'Nearly admin']);
        $custom->syncAbilities(Role::builtIn(Role::ADMINISTRATOR)->abilities());
        $nearly = $this->memberAs($custom);

        $this->actingConfirmed($owner)->post(route('admin.users.ownership'), $this->payload($editor))->assertForbidden();
        $this->actingConfirmed($owner)->post(route('admin.users.ownership'), $this->payload($nearly))->assertForbidden();
        $this->actingConfirmed($owner)->post(route('admin.users.ownership'), $this->payload($owner))->assertForbidden();

        $this->assertTrue($owner->fresh()->is_install_owner);
    }

    public function test_a_member_of_another_site_cannot_be_picked()
    {
        $owner = $this->owner();
        $other = $this->makeSite('other.example.com');
        $stranger = User::factory()->create();
        SiteUser::query()->where('user_id', $stranger->id)->delete();
        $this->asSite($other);
        $foreign = SiteUser::create(['user_id' => $stranger->id, 'role_id' => Role::builtIn(Role::ADMINISTRATOR)->id]);
        $this->asSite($this->defaultSite());

        $this->actingConfirmed($owner)
            ->post(route('admin.users.ownership'), ['member_id' => $foreign->id, 'email' => $stranger->email, 'password' => 'password'])
            ->assertNotFound();

        $this->assertFalse($stranger->fresh()->is_install_owner);
    }
}
