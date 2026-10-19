<?php

namespace Tests\Feature\Activity;

use App\Activity\ActivityAction;
use App\Activity\ActivityLog;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteInvite;
use App\Models\SiteUser;
use App\Models\User;
use App\Sites\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function log(): ActivityLog
    {
        return app(ActivityLog::class);
    }

    public function test_a_row_keeps_the_site_the_actor_and_the_details()
    {
        $actor = User::factory()->create(['name' => 'Rina']);

        $this->log()->record(ActivityAction::USER_ROLE_CHANGED, null, ['from' => 'Editor', 'to' => 'Viewer'], $actor);

        $row = Activity::query()->sole();
        $this->assertSame(1, $row->tenant_id);
        $this->assertSame($actor->id, $row->actor_id);
        $this->assertSame('Rina', $row->actor_name);
        $this->assertSame('user.role_changed', $row->action);
        // MySQL 8 re-sorts the keys of a JSON column, so compare without key order.
        $this->assertEquals(['from' => 'Editor', 'to' => 'Viewer'], $row->properties);
        $this->assertNotNull($row->created_at);
    }

    public function test_the_signed_in_account_is_the_actor_unless_one_is_given()
    {
        $this->actingAs(User::factory()->create(['name' => 'Ben']));

        $this->log()->record(ActivityAction::ROLE_DELETED, 'Proofreader');

        $this->assertSame('Ben', Activity::query()->sole()->actor_name);
    }

    public function test_an_event_with_no_one_signed_in_has_no_actor()
    {
        $this->log()->record(ActivityAction::LOGIN_FAILED, 'dewi@example.com');

        $row = Activity::query()->sole();
        $this->assertNull($row->actor_id);
        $this->assertNull($row->actor_name);
        $this->assertSame('dewi@example.com', $row->subject_label);
        $this->assertNull($row->subject_type);
    }

    public function test_a_model_is_linked_by_kind_id_and_a_readable_label()
    {
        $person = User::factory()->create(['email' => 'dewi@example.com']);
        $role = Role::builtIn(Role::EDITOR);
        $invite = SiteInvite::create([
            'email' => 'nia@example.com',
            'role_id' => $role->id,
            'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->addDay(),
        ]);

        $this->log()->record(ActivityAction::INVITE_SENT, $invite);
        $this->log()->record(ActivityAction::ROLE_UPDATED, $role);
        $this->log()->record(ActivityAction::USER_REMOVED, SiteUser::query()->where('user_id', $person->id)->sole());

        $rows = Activity::query()->orderBy('id')->get();
        $this->assertSame(['invite', $invite->id, 'nia@example.com'], [$rows[0]->subject_type, $rows[0]->subject_id, $rows[0]->subject_label]);
        $this->assertSame(['role', $role->id, 'Editor'], [$rows[1]->subject_type, $rows[1]->subject_id, $rows[1]->subject_label]);
        $this->assertSame(['user', $person->id, 'dewi@example.com'], [$rows[2]->subject_type, $rows[2]->subject_id, $rows[2]->subject_label]);
    }

    public function test_the_address_of_the_request_is_kept()
    {
        $this->app->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.1.2.3']));

        $this->log()->record(ActivityAction::LOGIN, 'Rina');

        $this->assertSame('10.1.2.3', Activity::query()->sole()->ip_address);
    }

    public function test_nothing_is_recorded_without_a_current_site()
    {
        app(CurrentSite::class)->forget();

        $this->log()->record(ActivityAction::LOGIN, 'Rina');

        $this->asSite($this->defaultSite());
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_a_row_stays_readable_after_the_actor_is_deleted()
    {
        $actor = User::factory()->create(['name' => 'Rina']);
        $this->log()->record(ActivityAction::ROLE_CREATED, 'Proofreader', actor: $actor);

        $actor->delete();

        $row = Activity::query()->sole();
        $this->assertNull($row->actor_id);
        $this->assertSame('Rina', $row->actor_name);
    }

    public function test_a_failure_to_log_never_stops_what_is_being_logged()
    {
        // Longer than the column, which MySQL refuses.
        $this->log()->record(str_repeat('x', 100), 'Rina');

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_one_site_never_sees_another_sites_rows()
    {
        $other = $this->makeSite('other.example.com');

        $this->assertInvisibleAcrossSites(
            $this->defaultSite(),
            $other,
            fn () => $this->log()->record(ActivityAction::LOGIN, 'Rina'),
            fn () => Activity::query()->count(),
        );
    }

    public function test_every_action_has_a_label_and_no_two_areas_share_one()
    {
        $groups = ActivityAction::groups();

        $this->assertCount(count(ActivityAction::all()), array_merge(...array_values($groups)));
        $this->assertSame('Signed in', ActivityAction::label(ActivityAction::LOGIN));
        $this->assertSame('auth.something_new', ActivityAction::label('auth.something_new'));
    }
}
