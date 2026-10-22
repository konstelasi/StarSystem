<?php

namespace Tests\Feature\Admin;

use App\Activity\ActivityAction;
use App\Models\Activity;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class ActivityControllerTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function memberAs(string $role, ?string $name = null): User
    {
        $user = User::factory()->create($name === null ? [] : ['name' => $name]);
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => Role::builtIn($role)->id]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function row(array $attributes = [], ?CarbonImmutable $at = null): Activity
    {
        $row = new Activity([
            'action' => ActivityAction::LOGIN,
            'actor_name' => 'Rina',
            ...$attributes,
        ]);

        if ($at !== null) {
            $row->forceFill(['created_at' => $at]);
        }

        $row->save();

        return $row;
    }

    public function test_guests_are_sent_to_sign_in_and_other_roles_are_refused()
    {
        $this->get(route('admin.activity'))->assertRedirect(route('login'));
        $this->actingAs($this->memberAs(Role::EDITOR))->get(route('admin.activity'))->assertForbidden();
        $this->actingAs($this->memberAs(Role::VIEWER))->get(route('admin.activity'))->assertForbidden();
    }

    public function test_an_administrator_sees_rows_newest_first_with_their_words()
    {
        $this->row(['action' => ActivityAction::LOGIN, 'actor_name' => 'Rina', 'ip_address' => '10.0.0.4']);
        $this->row([
            'action' => ActivityAction::USER_ROLE_CHANGED,
            'actor_name' => 'Ada',
            'subject_label' => 'dewi@example.com',
            'properties' => ['from' => 'Editor', 'to' => 'Viewer'],
        ]);

        $this->actingAs($this->memberAs(Role::ADMINISTRATOR))
            ->get(route('admin.activity'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/activity/Index')
                ->has('rows', 2)
                ->where('rows.0.actor', 'Ada')
                ->where('rows.0.label', "Changed someone's role")
                ->where('rows.0.subject', 'dewi@example.com')
                ->where('rows.0.details', ['Editor to Viewer'])
                ->where('rows.1.label', 'Signed in')
                ->where('rows.1.ip', '10.0.0.4')
                ->where('nextCursor', null)
                ->where('groups', ['Sign-in', 'People', 'Roles', 'Site', 'Account'])
            );
    }

    public function test_a_row_with_no_actor_is_shown_without_one()
    {
        $this->row(['action' => ActivityAction::LOGIN_FAILED, 'actor_name' => null, 'subject_label' => 'dewi@example.com']);

        $this->actingAs($this->memberAs(Role::ADMINISTRATOR))
            ->get(route('admin.activity'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.actor', null)
                ->where('rows.0.label', 'Failed to sign in')
                ->where('rows.0.subject', 'dewi@example.com')
            );
    }

    public function test_it_pages_fifty_at_a_time_by_the_id_of_the_last_row()
    {
        for ($i = 0; $i < 55; $i++) {
            $this->row(['subject_label' => "row-{$i}"]);
        }
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $first = $this->actingAs($admin)->get(route('admin.activity'));
        $first->assertInertia(fn (Assert $page) => $page
            ->has('rows', 50)
            ->where('rows.0.subject', 'row-54')
            ->where('rows.49.subject', 'row-5')
            ->has('nextCursor')
        );
        $cursor = $first->viewData('page')['props']['nextCursor'];

        $this->actingAs($admin)->get(route('admin.activity', ['cursor' => $cursor]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 5)
                ->where('rows.0.subject', 'row-4')
                ->where('rows.4.subject', 'row-0')
                ->where('nextCursor', null)
            );
    }

    public function test_it_filters_by_person_by_kind_and_by_date()
    {
        $ada = $this->memberAs(Role::ADMINISTRATOR, 'Ada');
        $this->row(['actor_id' => $ada->id, 'actor_name' => 'Ada', 'subject_label' => 'by-ada'], CarbonImmutable::parse('2026-03-10 12:00'));
        $this->row(['actor_name' => 'Rina', 'action' => ActivityAction::ROLE_CREATED, 'subject_label' => 'role-row'], CarbonImmutable::parse('2026-03-12 09:00'));
        $this->row(['actor_name' => 'Rina', 'subject_label' => 'late'], CarbonImmutable::parse('2026-03-20 23:59'));

        $subjects = fn (array $query) => collect(
            $this->actingAs($ada)->get(route('admin.activity', $query))->viewData('page')['props']['rows']
        )->pluck('subject')->all();

        $this->assertSame(['by-ada'], $subjects(['person' => $ada->id]));
        $this->assertSame(['role-row'], $subjects(['group' => 'Roles']));
        $this->assertSame(['late', 'role-row'], $subjects(['from' => '2026-03-11']));
        $this->assertSame(['role-row', 'by-ada'], $subjects(['to' => '2026-03-12']));
        $this->assertSame(['late'], $subjects(['from' => '2026-03-20', 'to' => '2026-03-20']));
    }

    public function test_a_filter_that_makes_no_sense_is_ignored()
    {
        $this->row();
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingAs($admin)
            ->get(route('admin.activity', ['group' => 'Nonsense', 'from' => 'last week', 'to' => '2026-13-45', 'person' => 'abc', 'cursor' => 'x']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('filters', ['person' => null, 'group' => null, 'from' => null, 'to' => null])
            );
    }

    public function test_the_people_to_pick_are_the_sites_members_and_the_install_owner()
    {
        $admin = $this->memberAs(Role::ADMINISTRATOR, 'Ada');
        $this->memberAs(Role::EDITOR, 'Ben');
        $owner = User::factory()->installOwner()->create(['name' => 'Zed']);
        SiteUser::query()->where('user_id', $owner->id)->delete();
        $other = $this->makeSite('other.example.com');
        $outsider = User::factory()->create(['name' => 'Cleo']);
        SiteUser::query()->where('user_id', $outsider->id)->delete();
        $this->asSite($other);
        SiteUser::create(['user_id' => $outsider->id, 'role_id' => Role::builtIn(Role::EDITOR)->id]);
        $this->asSite($this->defaultSite());

        $this->actingAs($admin)
            ->get(route('admin.activity'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('people', 3)
                ->where('people.0.name', 'Ada')
                ->where('people.1.name', 'Ben')
                ->where('people.2.name', 'Zed')
            );
    }

    public function test_one_site_never_sees_another_sites_rows()
    {
        $other = $this->makeSite('other.example.com');
        $this->asSite($other);
        $this->row(['subject_label' => 'secret']);
        $this->asSite($this->defaultSite());
        $admin = $this->memberAs(Role::ADMINISTRATOR);

        $this->actingAs($admin)
            ->get(route('admin.activity'))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    }
}
