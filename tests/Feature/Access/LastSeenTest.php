<?php

namespace Tests\Feature\Access;

use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CrossSite;
use Tests\TestCase;

class LastSeenTest extends TestCase
{
    use CrossSite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed clock, so a request and its assertion can't fall on either side of a second.
        $this->freezeTime();
        $this->asSite($this->defaultSite());
    }

    private function seen(User $user): ?string
    {
        return SiteUser::query()->where('user_id', $user->id)->sole()->last_seen_at?->toDateTimeString();
    }

    public function test_a_members_first_request_records_when_they_were_seen()
    {
        $user = User::factory()->create();
        $this->assertNull($this->seen($user));

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertSame(now()->toDateTimeString(), $this->seen($user));
    }

    public function test_requests_within_ten_minutes_do_not_write_again()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'));
        $first = $this->seen($user);

        $this->travel(9)->minutes();
        $this->actingAs($user)->withSession(['member_seen_at' => now()->subMinutes(9)->getTimestamp()])->get(route('dashboard'));

        $this->assertSame($first, $this->seen($user));
    }

    public function test_a_request_after_ten_minutes_writes_again()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'));
        $first = $this->seen($user);

        $this->travel(11)->minutes();
        $this->actingAs($user)->withSession(['member_seen_at' => now()->subMinutes(11)->getTimestamp()])->get(route('dashboard'));

        $this->assertNotSame($first, $this->seen($user));
        $this->assertSame(now()->toDateTimeString(), $this->seen($user));
    }

    public function test_only_the_current_sites_membership_is_marked()
    {
        $other = $this->makeSite('other.example.com');
        $user = User::factory()->create();
        $this->asSite($other);
        SiteUser::create(['user_id' => $user->id, 'role_id' => SiteUser::withoutGlobalScopes()->where('user_id', $user->id)->value('role_id')]);
        $this->asSite($this->defaultSite());

        $this->actingAs($user)->get(route('dashboard'));

        $this->assertNotNull($this->seen($user));
        $this->asSite($other);
        $this->assertNull($this->seen($user));
    }

    public function test_the_install_owner_without_a_membership_is_fine()
    {
        $owner = User::factory()->installOwner()->create();
        SiteUser::query()->where('user_id', $owner->id)->delete();

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        $this->assertSame(0, SiteUser::query()->count());
    }

    public function test_someone_turned_away_is_not_marked()
    {
        $user = User::factory()->create();
        SiteUser::query()->where('user_id', $user->id)->delete();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertSame(0, SiteUser::query()->count());
    }
}
