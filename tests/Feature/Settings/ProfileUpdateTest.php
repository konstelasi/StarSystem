<?php

namespace Tests\Feature\Settings;

use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_changing_the_name_and_email_is_logged_with_both_addresses()
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Test User', 'email' => 'new@example.com']);

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::ACCOUNT_UPDATED, $row->action);
        $this->assertSame($user->id, $row->actor_id);
        $this->assertEqualsCanonicalizing(['name', 'email'], $row->properties['changed']);
        $this->assertSame('old@example.com', $row->properties['email_from']);
        $this->assertSame('new@example.com', $row->properties['email_to']);
    }

    public function test_changing_only_the_name_logs_the_name_and_no_addresses()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Test User', 'email' => $user->email]);

        $this->assertSame(['changed' => ['name']], Activity::query()->sole()->properties);
    }

    public function test_saving_a_profile_without_changes_logs_nothing()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email]);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_deleting_an_account_is_logged_and_the_row_outlives_it()
    {
        $user = User::factory()->create(['name' => 'Rina']);

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);

        $row = Activity::query()->where('action', ActivityAction::ACCOUNT_DELETED)->sole();
        $this->assertNull($row->actor_id);
        $this->assertSame('Rina', $row->actor_name);
    }

    public function test_the_install_owner_cannot_delete_their_account()
    {
        $owner = User::factory()->installOwner()->create();

        $this->actingAs($owner)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));

        $this->assertAuthenticatedAs($owner);
        $this->assertNotNull($owner->fresh());
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_a_former_owner_can_delete_their_account()
    {
        $former = User::factory()->create(['is_install_owner' => false]);

        $this->actingAs($former)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('home'));

        $this->assertNull($former->fresh());
    }

    public function test_a_refused_delete_is_not_logged()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'wrong-password']);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
