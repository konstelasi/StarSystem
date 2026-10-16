<?php

namespace Tests\Feature\Activity;

use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class AuthActivityTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    public function test_signing_in_is_logged_with_who_and_from_where()
    {
        $user = User::factory()->create(['name' => 'Rina']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::LOGIN, $row->action);
        $this->assertSame($user->id, $row->actor_id);
        $this->assertSame('Rina', $row->actor_name);
        $this->assertSame('10.1.2.3', $row->ip_address);
    }

    public function test_a_wrong_password_is_logged_with_the_typed_address_and_no_actor()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => strtoupper($user->email), 'password' => 'not-the-password']);

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::LOGIN_FAILED, $row->action);
        $this->assertNull($row->actor_id);
        $this->assertSame(strtolower($user->email), $row->subject_label);
        $this->assertStringNotContainsString('not-the-password', json_encode($row->toArray()));
    }

    public function test_text_that_is_not_an_address_is_never_kept()
    {
        $this->post(route('login.store'), ['email' => 'hunter2hunter2', 'password' => 'whatever']);

        $row = Activity::query()->sole();
        $this->assertSame('unrecognised', $row->subject_label);
        $this->assertStringNotContainsString('hunter2', json_encode($row->toArray()));
    }

    public function test_an_account_the_site_turns_away_is_logged_as_a_failed_sign_in()
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->delete();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
        $this->assertSame(ActivityAction::LOGIN_FAILED, Activity::query()->sole()->action);
    }

    public function test_failed_sign_ins_are_capped_for_each_address()
    {
        for ($i = 0; $i < 35; $i++) {
            event(new Failed('web', null, ['email' => "person{$i}@example.com", 'password' => 'x']));
        }

        $this->assertSame(30, Activity::query()->where('action', ActivityAction::LOGIN_FAILED)->count());
    }

    public function test_signing_out_is_logged()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'));

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::LOGOUT, $row->action);
        $this->assertSame($user->id, $row->actor_id);
    }

    public function test_resetting_a_forgotten_password_is_logged_as_that_account()
    {
        $user = User::factory()->create();

        event(new PasswordReset($user));

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::PASSWORD_RESET, $row->action);
        $this->assertSame($user->id, $row->actor_id);
    }

    public function test_two_factor_changes_are_logged_once_they_take_effect()
    {
        $user = User::factory()->create();

        // Fortify asks for a code before two-factor counts, so starting it is not an event.
        event(new TwoFactorAuthenticationEnabled($user));
        $this->assertSame(0, Activity::query()->count());

        event(new TwoFactorAuthenticationConfirmed($user));
        event(new TwoFactorAuthenticationFailed($user));
        event(new TwoFactorAuthenticationDisabled($user));

        $this->assertSame(
            [ActivityAction::TWO_FACTOR_ENABLED, ActivityAction::TWO_FACTOR_FAILED, ActivityAction::TWO_FACTOR_DISABLED],
            Activity::query()->orderBy('id')->pluck('action')->all(),
        );
    }
}
