<?php

namespace Tests\Feature\Admin;

use App\Access\SiteAccess;
use App\Models\Role;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class UserListTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSite($this->defaultSite());
    }

    private function member(string $name, string $role = Role::EDITOR, array $over = []): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com']);
        SiteUser::query()->where('user_id', $user->id)->update(['role_id' => Role::builtIn($role)->id, ...$over]);
        app(SiteAccess::class)->forget();

        return $user;
    }

    private function admin(): User
    {
        return $this->member('Admin Adams', Role::ADMINISTRATOR);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function names(User $viewer, array $query = []): array
    {
        $page = $this->actingAs($viewer)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.users.index', $query))
            ->viewData('page');

        return array_column($page['props']['members'], 'name');
    }

    public function test_each_row_carries_what_the_page_shows()
    {
        $admin = $this->admin();
        $ben = $this->member('Ben Boyd', over: ['last_seen_at' => now()->subHour(), 'suspended_at' => now()]);
        $cleo = User::factory()->withTwoFactor()->create(['name' => 'Cleo Cruz']);

        $rows = collect($this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.users.index'))->viewData('page')['props']['members'])->keyBy('name');

        $this->assertFalse($rows['Ben Boyd']['twoFactor']);
        $this->assertTrue($rows['Ben Boyd']['suspended']);
        $this->assertNotNull($rows['Ben Boyd']['lastSeenAt']);
        $this->assertNotNull($rows['Ben Boyd']['joinedAt']);
        $this->assertTrue($rows['Cleo Cruz']['twoFactor']);
        $this->assertFalse($rows['Cleo Cruz']['suspended']);
        $this->assertNull($rows['Cleo Cruz']['lastSeenAt']);
        $this->assertSame($ben->email, $rows['Ben Boyd']['email']);
        $this->assertTrue($rows['Admin Adams']['isYou']);
    }

    public function test_the_default_order_is_by_name()
    {
        $admin = $this->admin();
        $this->member('Zoe Zane');
        $this->member('Ben Boyd');

        $this->assertSame(['Admin Adams', 'Ben Boyd', 'Zoe Zane'], $this->names($admin));
    }

    public function test_it_searches_names_and_emails_and_treats_wildcards_as_text()
    {
        $admin = $this->admin();
        $this->member('Ben Boyd');
        $this->member('Cleo Cruz');
        $this->member('50% Off');

        $this->assertSame(['Ben Boyd'], $this->names($admin, ['q' => 'boyd']));
        $this->assertSame(['Cleo Cruz'], $this->names($admin, ['q' => 'cleo.cruz@']));
        $this->assertSame(['50% Off'], $this->names($admin, ['q' => '50%']));
        $this->assertSame([], $this->names($admin, ['q' => '%zzz']));
        // Only spaces is no search at all.
        $this->assertSame(['50% Off', 'Admin Adams', 'Ben Boyd', 'Cleo Cruz'], $this->names($admin, ['q' => '   ']));
    }

    public function test_it_filters_by_role_and_by_status()
    {
        $admin = $this->admin();
        $this->member('Ben Boyd', Role::EDITOR);
        $this->member('Cleo Cruz', Role::VIEWER);
        $this->member('Dan Dunn', Role::EDITOR, ['suspended_at' => now()]);
        User::query()->where('name', 'Cleo Cruz')->update(['two_factor_confirmed_at' => now(), 'two_factor_secret' => 'x']);

        $this->assertSame(['Ben Boyd', 'Dan Dunn'], $this->names($admin, ['role' => Role::builtIn(Role::EDITOR)->id]));
        $this->assertSame(['Dan Dunn'], $this->names($admin, ['status' => 'paused']));
        $this->assertSame(['Admin Adams', 'Ben Boyd', 'Cleo Cruz'], $this->names($admin, ['status' => 'active']));
        $this->assertSame(['Cleo Cruz'], $this->names($admin, ['role' => Role::builtIn(Role::VIEWER)->id, 'status' => 'active']));
        $this->assertSame(['Admin Adams', 'Ben Boyd', 'Dan Dunn'], $this->names($admin, ['status' => 'no-two-factor']));
        $this->assertSame(['Dan Dunn'], $this->names($admin, ['status' => 'no-two-factor', 'q' => 'dan']));
    }

    public function test_it_sorts_by_name_joined_and_last_seen_in_either_direction()
    {
        $admin = $this->admin();
        $this->member('Ben Boyd', over: ['created_at' => now()->subDays(5), 'last_seen_at' => now()->subDays(1)]);
        $this->member('Cleo Cruz', over: ['created_at' => now()->subDays(9), 'last_seen_at' => now()->subDays(3)]);
        SiteUser::query()->where('user_id', $admin->id)->update(['created_at' => now()->subDays(1), 'last_seen_at' => now()]);

        $this->assertSame(['Cleo Cruz', 'Ben Boyd', 'Admin Adams'], $this->names($admin, ['sort' => '-name']));
        $this->assertSame(['Cleo Cruz', 'Ben Boyd', 'Admin Adams'], $this->names($admin, ['sort' => 'joined']));
        $this->assertSame(['Admin Adams', 'Ben Boyd', 'Cleo Cruz'], $this->names($admin, ['sort' => '-joined']));
        $this->assertSame(['Cleo Cruz', 'Ben Boyd', 'Admin Adams'], $this->names($admin, ['sort' => 'seen']));
        $this->assertSame(['Admin Adams', 'Ben Boyd', 'Cleo Cruz'], $this->names($admin, ['sort' => '-seen']));
    }

    public function test_an_unknown_sort_status_or_role_is_ignored()
    {
        $admin = $this->admin();
        $this->member('Ben Boyd');

        $page = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.users.index', ['sort' => 'password', 'status' => 'bogus', 'role' => 'abc', 'q' => str_repeat('x', 300)]))
            ->assertOk()
            ->viewData('page');

        $this->assertSame('name', $page['props']['filters']['sort']);
        $this->assertNull($page['props']['filters']['status']);
        $this->assertNull($page['props']['filters']['role']);
        $this->assertSame(100, mb_strlen($page['props']['filters']['q']));
    }

    public function test_it_pages_twenty_five_at_a_time()
    {
        $admin = $this->admin();
        foreach (range(1, 29) as $n) {
            $this->member(sprintf('Person %02d', $n));
        }

        $first = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('admin.users.index'));
        $first->assertInertia(fn (Assert $page) => $page
            ->has('members', 25)
            ->where('pagination', ['page' => 1, 'pages' => 2, 'total' => 30, 'from' => 1, 'to' => 25])
        );

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.users.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('members', 5)
                ->where('pagination', ['page' => 2, 'pages' => 2, 'total' => 30, 'from' => 26, 'to' => 30])
            );
    }

    public function test_an_empty_result_says_so()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.users.index', ['q' => 'nobody-like-this']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('members', 0)
                ->where('pagination', ['page' => 1, 'pages' => 1, 'total' => 0, 'from' => 0, 'to' => 0])
            );
    }

    public function test_owners_without_a_membership_show_only_on_the_first_unfiltered_page()
    {
        $admin = $this->admin();
        User::factory()->installOwner()->create(['name' => 'Zed Zimmer']);
        SiteUser::query()->where('user_id', User::query()->where('name', 'Zed Zimmer')->value('id'))->delete();
        foreach (range(1, 26) as $n) {
            $this->member(sprintf('Person %02d', $n));
        }
        $confirmed = fn () => $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

        $confirmed()->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page->has('owners', 1));
        $confirmed()->get(route('admin.users.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('owners', 0));
        $confirmed()->get(route('admin.users.index', ['q' => 'zed']))->assertInertia(fn (Assert $page) => $page->has('owners', 0));
    }

    public function test_another_sites_people_never_appear()
    {
        $admin = $this->admin();
        $other = $this->makeSite('other.example.com');
        $stranger = User::factory()->create(['name' => 'Cleo Cruz']);
        SiteUser::query()->where('user_id', $stranger->id)->delete();
        $this->asSite($other);
        SiteUser::create(['user_id' => $stranger->id, 'role_id' => Role::builtIn(Role::EDITOR)->id]);
        $this->asSite($this->defaultSite());

        $this->assertSame(['Admin Adams'], $this->names($admin));
        $this->assertSame([], $this->names($admin, ['q' => 'cleo']));
    }
}
