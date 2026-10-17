<?php

namespace Tests\Feature\Settings;

use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SessionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    private function addSession(User $user, string $id, int $minutesAgo = 5): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '10.0.0.4',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'payload' => '',
            'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    private function remaining(User $user): array
    {
        return DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all();
    }

    public function test_the_security_page_lists_where_the_account_is_signed_in()
    {
        $user = User::factory()->create();
        $this->addSession($user, 'laptop');
        $this->addSession(User::factory()->create(), 'somebody-else');

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Security')
                ->has('sessions', 1)
                ->where('sessions.0.browser', 'Chrome')
                ->where('sessions.0.os', 'Windows')
                ->where('sessions.0.ip', '10.0.0.4')
                ->where('sessions.0.current', false)
            );
    }

    public function test_the_list_is_left_out_when_sessions_are_not_kept_in_the_database()
    {
        config(['session.driver' => 'array']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('sessions', null));
    }

    public function test_signing_out_other_devices_ends_only_this_accounts_other_sessions()
    {
        $user = User::factory()->create();
        $this->addSession($user, 'phone');
        $this->addSession($user, 'laptop');
        $other = User::factory()->create();
        $this->addSession($other, 'theirs');

        $this->actingAs($user)
            ->delete(route('sessions.destroy'), ['current_password' => 'password'])
            ->assertRedirect();

        $this->assertNotContains('phone', $this->remaining($user));
        $this->assertNotContains('laptop', $this->remaining($user));
        $this->assertSame(['theirs'], $this->remaining($other));
    }

    public function test_it_is_logged_with_how_many_were_ended()
    {
        $user = User::factory()->create();
        $this->addSession($user, 'phone');
        $this->addSession($user, 'laptop');

        $this->actingAs($user)->delete(route('sessions.destroy'), ['current_password' => 'password']);

        $row = Activity::query()->sole();
        $this->assertSame(ActivityAction::SESSIONS_ENDED, $row->action);
        $this->assertSame($user->id, $row->actor_id);
        $this->assertSame(['count' => 2], $row->properties);
    }

    public function test_with_nothing_else_to_end_nothing_is_logged()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('sessions.destroy'), ['current_password' => 'password'])->assertRedirect();

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_the_current_password_is_needed()
    {
        $user = User::factory()->create();
        $this->addSession($user, 'phone');

        $this->actingAs($user)
            ->delete(route('sessions.destroy'), ['current_password' => 'not-it'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)
            ->delete(route('sessions.destroy'))
            ->assertSessionHasErrors('current_password');

        $this->assertContains('phone', $this->remaining($user));
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_guests_are_sent_to_sign_in()
    {
        $this->delete(route('sessions.destroy'), ['current_password' => 'password'])->assertRedirect(route('login'));
    }
}
